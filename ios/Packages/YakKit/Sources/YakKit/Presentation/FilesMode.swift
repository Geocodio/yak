/// The ways the Files pane can list files. Each mode is built by its own feature.
public enum FilesMode: String, CaseIterable, Codable, Sendable {
    case changed, everything

    /// Modes this build has, one per line; the changes feature adds `.changed`, the terminal feature `.everything`.
    public static let built: [FilesMode] = [
        // One line per built mode.
    ]

    /// `.changed` when there are changes and Changed is built; `.everything` when there are none and
    /// Everything is built; otherwise the first built mode. With nothing built the Files pane does not exist,
    /// and the answer is `.changed`.
    public static func initial(hasChanges: Bool, built: [FilesMode] = FilesMode.built) -> FilesMode {
        let preferred: FilesMode = hasChanges ? .changed : .everything
        if built.contains(preferred) {
            return preferred
        }
        return built.first ?? .changed
    }
}
