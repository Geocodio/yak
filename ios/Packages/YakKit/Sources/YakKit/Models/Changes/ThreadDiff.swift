/// The totals of a thread's diff, counted over the repositories that get a pull request plus scratch files.
public struct DiffTotals: Codable, Equatable, Sendable {
    public let additions: Int
    public let deletions: Int
    public let filesCount: Int

    public init(additions: Int, deletions: Int, filesCount: Int = 0) {
        self.additions = additions
        self.deletions = deletions
        self.filesCount = filesCount
    }

    private enum CodingKeys: String, CodingKey { case additions, deletions, filesCount }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        additions = try container.decode(Int.self, forKey: .additions)
        deletions = try container.decode(Int.self, forKey: .deletions)
        filesCount = try container.decodeIfPresent(Int.self, forKey: .filesCount) ?? 0
    }
}

public struct DiffFileStatus: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let added = DiffFileStatus(rawValue: "added")
    public static let modified = DiffFileStatus(rawValue: "modified")
    public static let deleted = DiffFileStatus(rawValue: "deleted")
    public static let renamed = DiffFileStatus(rawValue: "renamed")
}

public struct RepositoryRole: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let primary = RepositoryRole(rawValue: "primary")
    public static let changes = RepositoryRole(rawValue: "changes")
    public static let context = RepositoryRole(rawValue: "context")
}

/// A file's identity across captures. File ids change on every capture; the repository and path do not.
public struct DiffFileKey: Hashable, Codable, Sendable {
    public let repo: String
    public let path: String

    public init(repo: String, path: String) {
        self.repo = repo
        self.path = path
    }
}

public struct DiffRepository: Codable, Equatable, Sendable {
    public let repo: String
    public let role: RepositoryRole
    public let isThrowaway: Bool
    public let baseSha: String?
    public let headSha: String?
    public let filesCount: Int
    public let additions: Int
    public let deletions: Int
    public let branch: String?
    public let pullRequestUrl: String?

    public init(
        repo: String, role: RepositoryRole, isThrowaway: Bool = false, baseSha: String? = nil,
        headSha: String? = nil, filesCount: Int, additions: Int, deletions: Int, branch: String? = nil,
        pullRequestUrl: String? = nil
    ) {
        self.repo = repo
        self.role = role
        self.isThrowaway = isThrowaway
        self.baseSha = baseSha
        self.headSha = headSha
        self.filesCount = filesCount
        self.additions = additions
        self.deletions = deletions
        self.branch = branch
        self.pullRequestUrl = pullRequestUrl
    }
}

public struct DiffFileSummary: Codable, Equatable, Sendable, Identifiable {
    public let id: Int
    public let repo: String
    public let path: String
    public let oldPath: String?
    public let status: DiffFileStatus
    /// Nil for a binary file.
    public let additions: Int?
    public let deletions: Int?
    public let isBinary: Bool
    public let isTooLarge: Bool

    public var key: DiffFileKey { DiffFileKey(repo: repo, path: path) }

    public init(
        id: Int, repo: String, path: String, oldPath: String? = nil, status: DiffFileStatus,
        additions: Int? = nil, deletions: Int? = nil, isBinary: Bool = false, isTooLarge: Bool = false
    ) {
        self.id = id
        self.repo = repo
        self.path = path
        self.oldPath = oldPath
        self.status = status
        self.additions = additions
        self.deletions = deletions
        self.isBinary = isBinary
        self.isTooLarge = isTooLarge
    }
}

public struct ThreadDiff: Codable, Equatable, Sendable {
    public let baseSha: String
    public let headSha: String
    public let capturedAt: String
    public let isTruncated: Bool
    public let filesCount: Int
    public let additions: Int
    public let deletions: Int
    public let throwawayFilesCount: Int
    public let isTurnRunning: Bool
    public let repositories: [DiffRepository]
    public let files: [DiffFileSummary]

    public init(
        baseSha: String, headSha: String, capturedAt: String, isTruncated: Bool = false, filesCount: Int,
        additions: Int, deletions: Int, throwawayFilesCount: Int = 0, isTurnRunning: Bool = false,
        repositories: [DiffRepository] = [], files: [DiffFileSummary] = []
    ) {
        self.baseSha = baseSha
        self.headSha = headSha
        self.capturedAt = capturedAt
        self.isTruncated = isTruncated
        self.filesCount = filesCount
        self.additions = additions
        self.deletions = deletions
        self.throwawayFilesCount = throwawayFilesCount
        self.isTurnRunning = isTurnRunning
        self.repositories = repositories
        self.files = files
    }
}

/// `diff` is nil before the first capture.
public struct ThreadDiffResponse: Codable, Equatable, Sendable {
    public let diff: ThreadDiff?

    public init(diff: ThreadDiff?) { self.diff = diff }
}

public struct DiffFileContent: Codable, Equatable, Sendable {
    public let path: String
    public let oldPath: String?
    public let status: DiffFileStatus
    public let isBinary: Bool
    public let isTooLarge: Bool
    /// A unified diff, nil for a binary or too large file.
    public let patch: String?

    public init(
        path: String, oldPath: String? = nil, status: DiffFileStatus, isBinary: Bool = false,
        isTooLarge: Bool = false, patch: String? = nil
    ) {
        self.path = path
        self.oldPath = oldPath
        self.status = status
        self.isBinary = isBinary
        self.isTooLarge = isTooLarge
        self.patch = patch
    }
}
