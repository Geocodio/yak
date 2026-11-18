import Foundation
import YakKit

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
