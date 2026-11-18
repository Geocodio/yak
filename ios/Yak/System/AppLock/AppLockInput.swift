import UIKit

/// What the lock does to the windows around its own: who stays reachable for VoiceOver, and who gets the keyboard.
enum AppLockInput {
    /// Lock windows sit at `.alert + 1`; every window below them is covered while locked. A lock window is never
    /// hidden from accessibility, whichever instance made it, so VoiceOver can always reach "Unlock".
    static func windowsToHide(in windows: [UIWindow], lock: UIWindow) -> [UIWindow] {
        windows.filter { $0 !== lock && $0.windowLevel < .alert + 1 }
    }
}

/// Hardware keyboard input goes to the key window and its first responder. While the lock shows, the lock window is
/// key and the covered windows have no first responder, so nothing typed reaches the app. A covered window that
/// becomes key anyway (a focus request, a link that opens a thread) loses key again at once. Unlocking gives key back.
@MainActor final class LockKeyHandoff {
    private weak var previousKey: UIWindow?
    private weak var lock: UIWindow?
    private var covered: [WeakWindow] = []
    private var observer: (any NSObjectProtocol)?
    private let center: NotificationCenter
    private(set) var isEngaged = false

    private struct WeakWindow { weak var window: UIWindow? }

    init(center: NotificationCenter = .default) { self.center = center }

    func engage(lock: UIWindow, covering windows: [UIWindow]) {
        self.lock = lock
        covered = windows.map { WeakWindow(window: $0) }
        if !isEngaged {
            previousKey = windows.first { $0.isKeyWindow }
            isEngaged = true
            observer = center.addObserver(forName: UIWindow.didBecomeKeyNotification, object: nil, queue: .main) {
                [weak self] notification in
                guard let window = notification.object as? UIWindow else { return }
                MainActor.assumeIsolated { self?.windowBecameKey(window) }
            }
        }
        for window in windows { window.endEditing(true) }
        if !lock.isKeyWindow { lock.makeKey() }
    }

    private func windowBecameKey(_ window: UIWindow) {
        guard isEngaged, let lock, window !== lock, window.windowScene === lock.windowScene,
            window.windowLevel < .alert + 1
        else { return }
        // The window the person was in is the one to give key back to.
        previousKey = window
        window.endEditing(true)
        lock.makeKey()
    }

    func release() {
        guard isEngaged else { return }
        isEngaged = false
        if let observer { center.removeObserver(observer) }
        observer = nil
        lock = nil
        let restore = previousKey ?? covered.lazy.compactMap(\.window).first { !$0.isHidden }
        previousKey = nil
        covered = []
        if let restore, !restore.isHidden { restore.makeKey() }
    }
}
