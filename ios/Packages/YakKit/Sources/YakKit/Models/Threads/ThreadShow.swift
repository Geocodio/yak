public struct ThreadShow: Codable, Equatable, Sendable {
    public let thread: ThreadDetail
    public let canDrive: Bool
    public let messages: [ThreadMessage]
    public let composer: Composer
    public let notice: ThreadNotice?
    public let panes: ThreadPanes
    public var question: ThreadQuestion?
    public let latestSequence: Int

    public init(
        thread: ThreadDetail,
        canDrive: Bool,
        messages: [ThreadMessage],
        composer: Composer,
        notice: ThreadNotice? = nil,
        panes: ThreadPanes,
        question: ThreadQuestion? = nil,
        latestSequence: Int
    ) {
        self.thread = thread
        self.canDrive = canDrive
        self.messages = messages
        self.composer = composer
        self.notice = notice
        self.panes = panes
        self.question = question
        self.latestSequence = latestSequence
    }
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

    public init(
        id: String,
        name: String,
        title: String,
        repo: String,
        kind: String,
        status: ThreadStatus,
        visibility: String,
        branch: String? = nil,
        sandboxState: String,
        runnerState: String,
        blockedReason: String? = nil,
        parentThreadId: String? = nil,
        rootThreadId: String? = nil,
        lastActivityAt: String? = nil,
        pullRequestCount: Int,
        isOwner: Bool,
        isStarred: Bool
    ) {
        self.id = id
        self.name = name
        self.title = title
        self.repo = repo
        self.kind = kind
        self.status = status
        self.visibility = visibility
        self.branch = branch
        self.sandboxState = sandboxState
        self.runnerState = runnerState
        self.blockedReason = blockedReason
        self.parentThreadId = parentThreadId
        self.rootThreadId = rootThreadId
        self.lastActivityAt = lastActivityAt
        self.pullRequestCount = pullRequestCount
        self.isOwner = isOwner
        self.isStarred = isStarred
    }

    public var isPrivate: Bool { visibility == "private" }
}

public struct ThreadPanes: Codable, Equatable, Sendable {
    public let chat: Bool
    public let preview: Bool
    public let terminal: Bool
    public let changes: Bool
    public let files: Bool

    public init(
        chat: Bool,
        preview: Bool,
        terminal: Bool,
        changes: Bool,
        files: Bool
    ) {
        self.chat = chat
        self.preview = preview
        self.terminal = terminal
        self.changes = changes
        self.files = files
    }
}

public struct ThreadNotice: Codable, Equatable, Sendable {
    public let kind: String
    public let title: String
    public let body: String
    public let resetsAt: String?
    public let canContinue: Bool

    public init(
        kind: String,
        title: String,
        body: String,
        resetsAt: String? = nil,
        canContinue: Bool
    ) {
        self.kind = kind
        self.title = title
        self.body = body
        self.resetsAt = resetsAt
        self.canContinue = canContinue
    }
}
