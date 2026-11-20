import SwiftUI

/// Whether endless animations stay still: under Reduce Motion, and in test mode, where an animation that never ends
/// keeps XCUITest from ever getting an accessibility snapshot on a loaded machine.
enum StillMotion {
    static func isOn(reduceMotion: Bool) -> Bool {
        isTestMode || reduceMotion
    }

    /// The app runs under a UI test: movement is instant, so a screen is still when the audit looks at it.
    static var isTestMode: Bool {
        #if DEBUG
            TestMode.value("yakTestInstance") != nil
        #else
            false
        #endif
    }
}
