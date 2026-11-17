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
/// key and the covered windows have no first responder, so nothing typed reaches the app. Unlocking gives key back.
@MainActor final class LockKeyHandoff {
    private weak var previousKey: UIWindow?
    private(set) var isEngaged = false

    func engage(lock: UIWindow, covering windows: [UIWindow]) {
        if !isEngaged {
            previousKey = windows.first { $0.isKeyWindow }
            isEngaged = true
        }
        for window in windows { window.endEditing(true) }
        if !lock.isKeyWindow { lock.makeKey() }
    }

    func release() {
        guard isEngaged else { return }
        isEngaged = false
        let restore = previousKey
        previousKey = nil
        if let restore, !restore.isHidden { restore.makeKey() }
    }
}
