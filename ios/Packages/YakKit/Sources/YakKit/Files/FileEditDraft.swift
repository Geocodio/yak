import Foundation

/// The person's own unsaved text for one file. It is the only file text that reaches the disk.
public struct FileEditDraft: Codable, Equatable, Sendable {
    public let scope: FileScope
    public let path: String
    /// The hash the text was based on; empty for a file that did not exist.
    public let baseSHA256: String
    public let text: String
    public let savedAt: Date
    public static let maximumBytes = 1_000_000

    public init(scope: FileScope, path: String, baseSHA256: String, text: String, savedAt: Date) {
        self.scope = scope
        self.path = path
        self.baseSHA256 = baseSHA256
        self.text = text
        self.savedAt = savedAt
    }
}
