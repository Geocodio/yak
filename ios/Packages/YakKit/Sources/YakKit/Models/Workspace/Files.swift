import Foundation

public enum FileScope: String, Codable, Sendable { case repo, sandbox }

public struct FileTreeEntry: Codable, Equatable, Sendable, Identifiable {
    public let name: String
    public let path: String
    public let isDirectory: Bool
    public let isWritable: Bool
    public let size: Int?
    public var id: String { path }
}

/// One folder of the tree. `changedPaths` and `addedPaths` cover the whole thread, not only this folder.
public struct FileTreeFolder: Codable, Equatable, Sendable {
    public let root: String
    public let entries: [FileTreeEntry]
    public let changedPaths: [String]
    public let addedPaths: [String]
    /// False when the key is missing.
    public let truncated: Bool
}

extension FileTreeFolder {
    private enum CodingKeys: String, CodingKey {
        case root, entries, changedPaths, addedPaths, truncated
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        root = try container.decode(String.self, forKey: .root)
        entries = try container.decode([FileTreeEntry].self, forKey: .entries)
        changedPaths = try container.decodeIfPresent([String].self, forKey: .changedPaths) ?? []
        addedPaths = try container.decodeIfPresent([String].self, forKey: .addedPaths) ?? []
        truncated = try container.decodeIfPresent(Bool.self, forKey: .truncated) ?? false
    }
}

/// The server's language name for a file. Unknown values are kept.
public struct FileLanguage: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let text = FileLanguage(rawValue: "text")
}

/// A file as a save or a conflict returns it.
public struct FileBody: Codable, Equatable, Sendable {
    public let path: String
    public let sha256: String
    public let size: Int
    public let isBinary: Bool
    public let isTooLarge: Bool
    public let content: String?
    public let headContent: String?
    public let language: FileLanguage
}

/// A file as `GET files/content` returns it.
public struct FileContent: Codable, Equatable, Sendable {
    public let path: String
    public let sha256: String
    public let size: Int
    public let isBinary: Bool
    public let isTooLarge: Bool
    public let content: String?
    public let headContent: String?
    public let language: FileLanguage
    public let canEdit: Bool

    public var body: FileBody {
        FileBody(
            path: path, sha256: sha256, size: size, isBinary: isBinary, isTooLarge: isTooLarge,
            content: content, headContent: headContent, language: language)
    }
}

public struct FileSaveResult: Codable, Equatable, Sendable {
    public let file: FileBody
    public let working: Bool
}
