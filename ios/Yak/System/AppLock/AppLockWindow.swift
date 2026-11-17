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
    @State private var windows: AppLockWindows?

    func body(content: Content) -> some View {
        content
            .onAppear {
                guard windows == nil else { return }
                let created = AppLockWindows(controller: controller)
                windows = created
                created.start()
            }
            .task { await controller.launched() }
            .onReceive(NotificationCenter.default.publisher(for: UIApplication.didEnterBackgroundNotification)) { _ in
                controller.appEnteredBackground()
            }
            .onReceive(NotificationCenter.default.publisher(for: UIApplication.didBecomeActiveNotification)) { _ in
                Task { await controller.appBecameActive() }
            }
            .sensoryFeedback(.success, trigger: controller.unlockCount)
            .sensoryFeedback(.error, trigger: controller.failureCount)
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

/// One `UIWindow` per window scene, above alerts, hidden unless it has something to show.
@MainActor private final class AppLockWindows {
    private struct Entry {
        let scene: UIWindowScene
        let window: UIWindow
        let content: WindowContent
    }

    private let controller: AppLockController
    private var scenes = AppLockScenes<ObjectIdentifier>()
    private var entries: [ObjectIdentifier: Entry] = [:]
    private var observers: [any NSObjectProtocol] = []
    private var generation = 0

    init(controller: AppLockController) { self.controller = controller }

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
        for case let scene as UIWindowScene in UIApplication.shared.connectedScenes { connect(scene) }
        render()
    }

    private func sceneChanged(_ scene: UIWindowScene, name: Notification.Name) {
        let key = ObjectIdentifier(scene)
        switch name {
        case UIScene.didDisconnectNotification:
            scenes.disconnected(key)
            if let entry = entries.removeValue(forKey: key) { entry.window.isHidden = true }
        case UIScene.didActivateNotification:
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
        } onChange: { [weak self] in
            Task { @MainActor in
                if let self, self.generation == mine { self.render() }
            }
        }
    }

    private func apply(_ shows: AppLockScenes<ObjectIdentifier>.Shows, to entry: Entry) {
        let others = entry.scene.windows.filter { $0 !== entry.window }
        for other in others { other.accessibilityElementsHidden = shows == .lockScreen }
        if shows != .nothing {
            // At once and without animation, so the app switcher snapshot is never taken half covered.
            entry.window.layer.removeAllAnimations()
            entry.window.alpha = 1
            // The cover shows only while the app is not in use, so it takes no touches.
            entry.window.isUserInteractionEnabled = shows == .lockScreen
            entry.content.shows = shows
            entry.window.rootViewController?.view.accessibilityViewIsModal = shows == .lockScreen
            entry.window.isHidden = false
        } else if !entry.window.isHidden {
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
