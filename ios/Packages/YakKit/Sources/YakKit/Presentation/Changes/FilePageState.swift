/// What one file's page shows. A patch is never saved on disk, so `notSaved` is the offline answer for a file that
/// was never loaded.
public enum FilePageState: Equatable, Sendable {
    /// Shown after 300 ms by the view.
    case loading
    case slow
    case ready(ParsedPatch)
    /// A rename or mode change: "No content changes".
    case noContentChanges
    case binary
    case tooLarge
    /// A second 404 after the reload.
    case gone
    case notSaved
    case failed(ThreadProblem)
}
