import Foundation

public enum FileScope: String, Codable, Sendable { case repo, sandbox }

public struct FileTreeEntry: Codable, Equatable, Sendable, Identifiable {
    public let name: String
    public let path: String
    public let isDirectory: Bool
    public let isWritable: Bool
    public let size: Int?
    public var id: String { path }

    public init(name: String, path: String, isDirectory: Bool, isWritable: Bool, size: Int?) {
        self.name = name
        self.path = path
        self.isDirectory = isDirectory
        self.isWritable = isWritable
        self.size = size
    }
}

/// One folder of the tree. `changedPaths` and `addedPaths` cover the whole thread, not only this folder.
public struct FileTreeFolder: Codable, Equatable, Sendable {
    public let root: String
    public let entries: [FileTreeEntry]
    public let changedPaths: [String]
    public let addedPaths: [String]
    /// False when the key is missing.
    public let truncated: Bool

    public init(
        root: String, entries: [FileTreeEntry], changedPaths: [String] = [], addedPaths: [String] = [],
        truncated: Bool = false
    ) {
        self.root = root
        self.entries = entries
        self.changedPaths = changedPaths
        self.addedPaths = addedPaths
        self.truncated = truncated
    }
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

    public init(
        path: String, sha256: String, size: Int, isBinary: Bool, isTooLarge: Bool, content: String?,
        headContent: String?, language: FileLanguage
    ) {
        self.path = path
        self.sha256 = sha256
        self.size = size
        self.isBinary = isBinary
        self.isTooLarge = isTooLarge
        self.content = content
        self.headContent = headContent
        self.language = language
    }
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

    public init(
        path: String, sha256: String, size: Int, isBinary: Bool, isTooLarge: Bool, content: String?,
        headContent: String?, language: FileLanguage, canEdit: Bool
    ) {
        self.path = path
        self.sha256 = sha256
        self.size = size
        self.isBinary = isBinary
        self.isTooLarge = isTooLarge
        self.content = content
        self.headContent = headContent
        self.language = language
        self.canEdit = canEdit
    }

    public var body: FileBody {
        FileBody(
            path: path, sha256: sha256, size: size, isBinary: isBinary, isTooLarge: isTooLarge,
            content: content, headContent: headContent, language: language)
    }
}

public struct FileSaveResult: Codable, Equatable, Sendable {
    public let file: FileBody
    public let working: Bool

    public init(file: FileBody, working: Bool) {
        self.file = file
        self.working = working
    }
}

public struct FileFindMatch: Codable, Equatable, Sendable {
    public let name: String
    public let path: String

    public init(name: String, path: String) {
        self.name = name
        self.path = path
    }
}

public struct FileSearchResult: Codable, Equatable, Sendable {
    public let matches: [FileFindMatch]
    public let truncated: Bool

    public init(matches: [FileFindMatch], truncated: Bool) {
        self.matches = matches
        self.truncated = truncated
    }
}
