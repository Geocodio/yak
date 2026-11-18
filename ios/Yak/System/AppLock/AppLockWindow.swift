import Observation
import SwiftUI
import UIKit

extension View {
    /// Puts the lock and the app switcher cover in a window above everything in every window scene, and feeds the
    /// controller the app's background and active events. A SwiftUI overlay would not cover sheets.
    func appLockWindow(_ controller: AppLockController) -> some View {
        modifier(AppLockHost(controller: controller))
    }
}

private struct AppLockHost: ViewModifier {
    let controller: AppLockController

    func body(content: Content) -> some View {
        content
            .onAppear {
                // Every scene root runs this; the windows are made once for the whole process.
                controller.startWindowsOnce {
                    let windows = AppLockWindows(controller: controller)
                    windows.start()
                    return windows
                }
            }
            .task { await controller.launched() }
    }
}

/// What one lock window draws.
@MainActor @Observable private final class WindowContent {
    var shows: AppLockScenes<ObjectIdentifier>.Shows = .nothing
}

private struct LockWindowView: View {
    let content: WindowContent
    let controller: AppLockController

    var body: some View {
        switch content.shows {
        case .lockScreen: AppLockScreen(controller: controller)
        case .cover: AppSwitcherCover()
        case .nothing: Color(.systemBackground).ignoresSafeArea()
        }
    }
}

/// One `UIWindow` per window scene, above alerts, hidden unless it has something to show. The process has one set of
/// these, so it also owns the app-wide events and the haptics, which would otherwise repeat once per scene.
@MainActor final class AppLockWindows {
    @MainActor private struct Entry {
        let scene: UIWindowScene
        let window: UIWindow
        let content: WindowContent
        let keyHandoff = LockKeyHandoff()
    }

    private let controller: AppLockController
    private var scenes = AppLockScenes<ObjectIdentifier>()
    private var entries: [ObjectIdentifier: Entry] = [:]
    private var observers: [any NSObjectProtocol] = []
    private var generation = 0
    private var seenUnlocks: Int
    private var seenFailures: Int

    init(controller: AppLockController) {
        self.controller = controller
        seenUnlocks = controller.unlockCount
        seenFailures = controller.failureCount
    }

    func start() {
        let center = NotificationCenter.default
        let names: [Notification.Name] = [
            UIScene.willConnectNotification, UIScene.didActivateNotification, UIScene.willDeactivateNotification,
            UIScene.didEnterBackgroundNotification, UIScene.didDisconnectNotification,
        ]
        for name in names {
            observers.append(
                center.addObserver(forName: name, object: nil, queue: .main) { [weak self] notification in
                    guard let scene = notification.object as? UIWindowScene else { return }
                    let changed = notification.name
                    MainActor.assumeIsolated { self?.sceneChanged(scene, name: changed) }
                })
        }
        observers.append(
            center.addObserver(forName: UIApplication.didEnterBackgroundNotification, object: nil, queue: .main) {
                [weak self] _ in
                MainActor.assumeIsolated { self?.controller.appEnteredBackground() }
            })
        observers.append(
            center.addObserver(forName: UIApplication.didBecomeActiveNotification, object: nil, queue: .main) {
                [weak self] _ in
                MainActor.assumeIsolated {
                    guard let controller = self?.controller else { return }
                    let ask = controller.relockIfDue()
                    Task { await controller.finishBecomingActive(askIfLocked: ask) }
                }
            })
        observers.append(
            center.addObserver(
                forName: UIApplication.protectedDataDidBecomeAvailableNotification, object: nil, queue: .main
            ) { [weak self] _ in
                MainActor.assumeIsolated {
                    guard let controller = self?.controller else { return }
                    let isActive = UIApplication.shared.applicationState == .active
                    Task { await controller.protectedDataBecameAvailable(isActive: isActive) }
                }
            })
        for case let scene as UIWindowScene in UIApplication.shared.connectedScenes { connect(scene) }
        render()
    }

    private func sceneChanged(_ scene: UIWindowScene, name: Notification.Name) {
        let key = ObjectIdentifier(scene)
        switch name {
        case UIScene.didDisconnectNotification:
            scenes.disconnected(key)
            if let entry = entries.removeValue(forKey: key) {
                entry.keyHandoff.release()
                entry.window.isHidden = true
            }
        case UIScene.didActivateNotification:
            // Decided before the render below, so content never shows after the limit.
            _ = controller.relockIfDue()
            if entries[key] == nil { connect(scene) }
            scenes.phaseChanged(key, isActive: true)
        default:
            if entries[key] == nil { connect(scene) }
            scenes.phaseChanged(key, isActive: false)
        }
        render()
    }

    private func connect(_ scene: UIWindowScene) {
        let key = ObjectIdentifier(scene)
        guard entries[key] == nil else { return }
        let content = WindowContent()
        let window = UIWindow(windowScene: scene)
        window.windowLevel = .alert + 1
        let host = UIHostingController(rootView: LockWindowView(content: content, controller: controller))
        host.view.backgroundColor = .systemBackground
        window.rootViewController = host
        window.isHidden = true
        entries[key] = Entry(scene: scene, window: window, content: content)
        scenes.connected(key, isActive: scene.activationState == .foregroundActive)
    }

    /// Applies the state to every window and asks to be called again when the controller changes.
    private func render() {
        generation += 1
        let mine = generation
        withObservationTracking {
            for (key, entry) in entries { apply(scenes.shows(key, controller: controller), to: entry) }
            playHaptics(unlocks: controller.unlockCount, failures: controller.failureCount)
        } onChange: { [weak self] in
            Task { @MainActor in
                if let self, self.generation == mine { self.render() }
            }
        }
    }

    private func playHaptics(unlocks: Int, failures: Int) {
        let generator = UINotificationFeedbackGenerator()
        if failures != seenFailures { generator.notificationOccurred(.error) }
        if unlocks != seenUnlocks { generator.notificationOccurred(.success) }
        seenUnlocks = unlocks
        seenFailures = failures
    }

    /// What a window shows, turned into what it does to input: the lock screen and the unreadable-store cover take
    /// touches and hide the content from accessibility; the app switcher cover, shown while the app is not in
    /// use, does neither.
    struct Presentation: Equatable {
        let blocksInput: Bool
        let hidesCoveredFromAccessibility: Bool
        let isModal: Bool

        init(shows: AppLockScenes<ObjectIdentifier>.Shows, storeUnreadable: Bool) {
            blocksInput = shows == .lockScreen || storeUnreadable
            hidesCoveredFromAccessibility = blocksInput
            isModal = shows == .lockScreen
        }
    }

    /// The windows this process made, one per scene.
    var lockWindows: [UIWindow] { entries.values.map(\.window) }

    private func apply(_ shows: AppLockScenes<ObjectIdentifier>.Shows, to entry: Entry) {
        let covered = AppLockInput.windowsToHide(in: entry.scene.windows, lock: entry.window)
        let presentation = Presentation(shows: shows, storeUnreadable: controller.storeUnreadable)
        for other in covered { other.accessibilityElementsHidden = presentation.hidesCoveredFromAccessibility }
        if shows != .nothing {
            // At once and without animation, so the app switcher snapshot is never taken half covered.
            entry.window.layer.removeAllAnimations()
            entry.window.alpha = 1
            entry.window.isUserInteractionEnabled = presentation.blocksInput
            entry.content.shows = shows
            entry.window.rootViewController?.view.accessibilityViewIsModal = presentation.isModal
            entry.window.isHidden = false
            if presentation.blocksInput {
                entry.keyHandoff.engage(lock: entry.window, covering: covered)
            } else {
                entry.keyHandoff.release()
            }
        } else if !entry.window.isHidden {
            entry.keyHandoff.release()
            let fade = {
                entry.window.alpha = 0
            }
            let finish: (Bool) -> Void = { [weak self] _ in
                MainActor.assumeIsolated {
                    guard let self, self.shows(entry) == .nothing else { return }
                    entry.window.isHidden = true
                    entry.window.alpha = 1
                    entry.content.shows = .nothing
                }
            }
            if UIAccessibility.isReduceMotionEnabled {
                UIView.animate(withDuration: 0.25, animations: fade, completion: finish)
            } else {
                UIView.animate(
                    withDuration: 0.35, delay: 0, usingSpringWithDamping: 1, initialSpringVelocity: 0, options: [],
                    animations: fade, completion: finish)
            }
        }
    }

    private func shows(_ entry: Entry) -> AppLockScenes<ObjectIdentifier>.Shows {
        scenes.shows(ObjectIdentifier(entry.scene), controller: controller)
    }
}
