import Foundation

/// A request, from another pane or from a Changed diff page, to open one file of the sandbox in the Files pane.
struct FileOpenRequest: Equatable, Sendable {
    /// A new id per tap, so the same path twice is two requests.
    let id = UUID()
    let path: String
    let edit: Bool
}

/// Takes a pending request once and opens it. Requests open one after the other, so a newer request is never
/// dropped by the open that is still running, and a request clears itself only while it is still the current one.
@MainActor
final class FileOpenHandler {
    private let open: (String, Bool) async -> Bool
    private let failed: (FileOpenRequest) -> Void
    private var lastTaken: UUID?
    private var tail: Task<Void, Never>?

    /// `failed` hears of a request whose file could not be opened.
    init(open: @escaping (String, Bool) async -> Bool, failed: @escaping (FileOpenRequest) -> Void = { _ in }) {
        self.open = open
        self.failed = failed
    }

    func take(_ request: FileOpenRequest?, current: () -> FileOpenRequest?, clear: () -> Void) async {
        guard let request, request.id != lastTaken else { return }
        lastTaken = request.id
        if current()?.id == request.id { clear() }
        let previous = tail
        let opening = Task { [open, failed] in
            await previous?.value
            if await !open(request.path, request.edit) { failed(request) }
        }
        tail = opening
        await opening.value
    }
}
