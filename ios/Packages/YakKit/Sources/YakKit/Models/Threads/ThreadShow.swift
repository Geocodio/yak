public struct ThreadShow: Codable, Equatable, Sendable {
    public let thread: ThreadDetail
    public let canDrive: Bool
    public let messages: [ThreadMessage]
    public let composer: Composer
    public let notice: ThreadNotice?
    public let panes: ThreadPanes
    public let question: ThreadQuestion?
    public let latestSequence: Int
}

public struct ThreadDetail: Codable, Equatable, Sendable {
    public let id: String
    public let name: String
    public let title: String
    public let repo: String
    public let kind: String
    public let status: ThreadStatus
    public let visibility: String
    public let branch: String?
    public let sandboxState: String
    public let runnerState: String
    public let blockedReason: String?
    public let parentThreadId: String?
    public let rootThreadId: String?
    public let lastActivityAt: String?
    public let pullRequestCount: Int
    public let isOwner: Bool
    public let isStarred: Bool

    public var isPrivate: Bool { visibility == "private" }
}

public struct ThreadPanes: Codable, Equatable, Sendable {
    public let chat: Bool
    public let preview: Bool
    public let terminal: Bool
    public let changes: Bool
    public let files: Bool
}

public struct ThreadNotice: Codable, Equatable, Sendable {
    public let kind: String
    public let title: String
    public let body: String
    public let resetsAt: String?
    public let canContinue: Bool
}
