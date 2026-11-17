import SwiftUI

/// Whether endless animations stay still: under Reduce Motion, and in test mode, where an animation that never ends
/// keeps XCUITest from ever getting an accessibility snapshot on a loaded machine.
enum StillMotion {
    static func isOn(reduceMotion: Bool) -> Bool {
        #if DEBUG
            if TestMode.value("yakTestInstance") != nil { return true }
        #endif
        return reduceMotion
    }
}
