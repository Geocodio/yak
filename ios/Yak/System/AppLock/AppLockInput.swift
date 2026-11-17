import UIKit

/// What the lock does to the windows around its own: who stays reachable for VoiceOver, and who gets the keyboard.
enum AppLockInput {
    /// Lock windows sit at `.alert + 1`; every window below them is covered while locked. A lock window is never
    /// hidden from accessibility, whichever instance made it, so VoiceOver can always reach "Unlock".
    static func windowsToHide(in windows: [UIWindow], lock: UIWindow) -> [UIWindow] {
        windows.filter { $0 !== lock && $0.windowLevel < .alert + 1 }
    }
}
