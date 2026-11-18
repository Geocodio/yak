import Foundation
import SwiftUI
import UIKit
import YakKit

/// The one answer to "does this window offer several windows": the app supports several scenes and the window has
/// regular width and height. An iPhone never has both, so it never takes the iPad's window paths.
@MainActor
enum SeveralWindows {
    static func isAvailable(isCompactWidth: Bool, isCompactHeight: Bool) -> Bool {
        isAvailable(
            supportsMultipleScenes: UIApplication.shared.supportsMultipleScenes, isCompactWidth: isCompactWidth,
            isCompactHeight: isCompactHeight)
    }

    nonisolated static func isAvailable(
        supportsMultipleScenes: Bool, isCompactWidth: Bool, isCompactHeight: Bool
    ) -> Bool {
        supportsMultipleScenes && !isCompactWidth && !isCompactHeight
    }
}

extension EnvironmentValues {
    /// Set once by the shell from `SeveralWindows`; the row menus and the buttons read it.
    @Entry var hasSeveralWindows = false
}

/// Opening a window. The view passes `{ openWindow(value: $0) }`; tests pass a recording closure.
@MainActor
enum WindowRoutes {
    /// Command Shift N: a new window with no thread, new every time.
    static func newWindow(
        open: (WindowRequest) -> Void, supportsMultipleWindows: Bool, makeID: () -> UUID = { UUID() }
    ) {
        guard supportsMultipleWindows else { return }
        open(.blank(makeID()))
    }

    /// "Open in new window" on a thread row.
    static func open(
        thread id: String, instance: UUID, open: (WindowRequest) -> Void, supportsMultipleWindows: Bool
    ) {
        guard supportsMultipleWindows else { return }
        open(.thread(instance: instance, id: id))
    }
}
