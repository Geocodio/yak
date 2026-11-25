public struct ThreadShow: Codable, Equatable, Sendable {
    public let thread: ThreadDetail
    public var canDrive: Bool
    public let messages: [ThreadMessage]
    public let composer: Composer
    public let notice: ThreadNotice?
    public let panes: ThreadPanes
    public var question: ThreadQuestion?
    public let latestSequence: Int
    public let stackParent: StackParent?
    public let capacity: ThreadCapacity?
    /// Whether the server lets this person open a pull request now. The app never works it out itself.
    public let canOpenPullRequest: Bool
    public let diffTotals: DiffTotals?
    public let pullRequest: ThreadPullRequestLink?
    /// The thread is private or shared, so opening a pull request publishes its conversation.
    public let publishWarning: Bool
    /// The broadcast channel for this thread's Live Activity; nil when the server has none.
    public let liveActivityChannel: String?
    /// Who asked for the work and how far it is. Nil from a server that does not send it.
    public let request: ThreadRequest?
    /// Nil when the thread is closed, has no task or the work succeeded.
    public let progress: ThreadProgress?
    public let milestones: [ThreadMilestone]
    /// The one card to show above the composer. Nil from an older server.
    public let card: ThreadCard?
    public let repositoryQuestion: RepositoryQuestion?
    public let earlyWarning: EarlyWarningNotice?
    public let coOwner: CoOwnerPanel?
    public let stopped: ThreadStop?
    public let actions: ThreadActionFlags
    /// A thread made from an old task: closed, no sandbox.
    public let isLegacy: Bool
    public let legacyAnswers: [LegacyAnswer]
    /// The composer's slash commands. Nil from an older server, which has no menu.
    public let slashCommands: SlashCommands?

    public init(
        thread: ThreadDetail,
        canDrive: Bool,
        messages: [ThreadMessage],
        composer: Composer,
        notice: ThreadNotice? = nil,
        panes: ThreadPanes,
        question: ThreadQuestion? = nil,
        latestSequence: Int,
        stackParent: StackParent? = nil,
        capacity: ThreadCapacity? = nil,
        canOpenPullRequest: Bool = false,
        diffTotals: DiffTotals? = nil,
        pullRequest: ThreadPullRequestLink? = nil,
        publishWarning: Bool = false,
        liveActivityChannel: String? = nil,
        request: ThreadRequest? = nil,
        progress: ThreadProgress? = nil,
        milestones: [ThreadMilestone] = [],
        card: ThreadCard? = nil,
        repositoryQuestion: RepositoryQuestion? = nil,
        earlyWarning: EarlyWarningNotice? = nil,
        coOwner: CoOwnerPanel? = nil,
        stopped: ThreadStop? = nil,
        actions: ThreadActionFlags = .none,
        isLegacy: Bool = false,
        legacyAnswers: [LegacyAnswer] = [],
        slashCommands: SlashCommands? = nil
    ) {
        self.thread = thread
        self.canDrive = canDrive
        self.messages = messages
        self.composer = composer
        self.notice = notice
        self.panes = panes
        self.question = question
        self.latestSequence = latestSequence
        self.stackParent = stackParent
        self.capacity = capacity
        self.canOpenPullRequest = canOpenPullRequest
        self.diffTotals = diffTotals
        self.pullRequest = pullRequest
        self.publishWarning = publishWarning
        self.liveActivityChannel = liveActivityChannel
        self.request = request
        self.progress = progress
        self.milestones = milestones
        self.card = card
        self.repositoryQuestion = repositoryQuestion
        self.earlyWarning = earlyWarning
        self.coOwner = coOwner
        self.stopped = stopped
        self.actions = actions
        self.isLegacy = isLegacy
        self.legacyAnswers = legacyAnswers
        self.slashCommands = slashCommands
    }

    private enum CodingKeys: String, CodingKey {
        case thread, canDrive, messages, composer, notice, panes, question, latestSequence
        case stackParent, capacity
        case canOpenPullRequest, diffTotals, pullRequest, publishWarning
        case liveActivityChannel
        case request, progress, milestones, card, repositoryQuestion, earlyWarning, coOwner, stopped
        case actions, isLegacy, legacyAnswers, slashCommands
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        thread = try container.decode(ThreadDetail.self, forKey: .thread)
        canDrive = try container.decode(Bool.self, forKey: .canDrive)
        messages = try container.decode([ThreadMessage].self, forKey: .messages)
        composer = try container.decode(Composer.self, forKey: .composer)
        notice = try container.decodeIfPresent(ThreadNotice.self, forKey: .notice)
        panes = try container.decode(ThreadPanes.self, forKey: .panes)
        question = try container.decodeIfPresent(ThreadQuestion.self, forKey: .question)
        latestSequence = try container.decode(Int.self, forKey: .latestSequence)
        stackParent = try container.decodeIfPresent(StackParent.self, forKey: .stackParent)
        capacity = try container.decodeIfPresent(ThreadCapacity.self, forKey: .capacity)
        canOpenPullRequest = try container.decodeIfPresent(Bool.self, forKey: .canOpenPullRequest) ?? false
        diffTotals = try container.decodeIfPresent(DiffTotals.self, forKey: .diffTotals)
        pullRequest = try container.decodeIfPresent(ThreadPullRequestLink.self, forKey: .pullRequest)
        publishWarning = try container.decodeIfPresent(Bool.self, forKey: .publishWarning) ?? false
        liveActivityChannel = try container.decodeIfPresent(String.self, forKey: .liveActivityChannel)
        request = try container.decodeIfPresent(ThreadRequest.self, forKey: .request)
        progress = try container.decodeIfPresent(ThreadProgress.self, forKey: .progress)
        milestones = try container.decodeIfPresent([ThreadMilestone].self, forKey: .milestones) ?? []
        card = try container.decodeIfPresent(ThreadCard.self, forKey: .card)
        repositoryQuestion = try container.decodeIfPresent(RepositoryQuestion.self, forKey: .repositoryQuestion)
        earlyWarning = try container.decodeIfPresent(EarlyWarningNotice.self, forKey: .earlyWarning)
        coOwner = try container.decodeIfPresent(CoOwnerPanel.self, forKey: .coOwner)
        stopped = try container.decodeIfPresent(ThreadStop.self, forKey: .stopped)
        actions = try container.decodeIfPresent(ThreadActionFlags.self, forKey: .actions) ?? .none
        isLegacy = try container.decodeIfPresent(Bool.self, forKey: .isLegacy) ?? false
        legacyAnswers = try container.decodeIfPresent([LegacyAnswer].self, forKey: .legacyAnswers) ?? []
        slashCommands = (try? container.decodeIfPresent(SlashCommands.self, forKey: .slashCommands)) ?? nil
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
    public let ownerSide: String?
    public let preview: ThreadPreviewHints?
    public let sharedWithCount: Int?
    public let startedOnDevice: Bool?
    public let pullRequestOpening: PullRequestOpening?
    /// False means `repo` is the placeholder `unknown`, which is never shown.
    public let hasRepository: Bool

    /// The repository to show a person: nil while `repo` is the placeholder.
    public var shownRepo: String? { hasRepository ? repo : nil }

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
        isStarred: Bool,
        ownerSide: String? = nil,
        preview: ThreadPreviewHints? = nil,
        sharedWithCount: Int? = nil,
        startedOnDevice: Bool? = nil,
        pullRequestOpening: PullRequestOpening? = nil,
        hasRepository: Bool = true
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
        self.ownerSide = ownerSide
        self.preview = preview
        self.sharedWithCount = sharedWithCount
        self.startedOnDevice = startedOnDevice
        self.pullRequestOpening = pullRequestOpening
        self.hasRepository = hasRepository
    }

    private enum CodingKeys: String, CodingKey {
        case hasRepository
        case id, name, title, repo, kind, status, visibility, branch, sandboxState, runnerState, blockedReason
        case parentThreadId, rootThreadId, lastActivityAt, pullRequestCount, isOwner, isStarred, pullRequestOpening
        case ownerSide, preview, sharedWithCount, startedOnDevice
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        name = try container.decode(String.self, forKey: .name)
        title = try container.decode(String.self, forKey: .title)
        repo = try container.decode(String.self, forKey: .repo)
        kind = try container.decode(String.self, forKey: .kind)
        status = try container.decode(ThreadStatus.self, forKey: .status)
        visibility = try container.decode(String.self, forKey: .visibility)
        branch = try container.decodeIfPresent(String.self, forKey: .branch)
        sandboxState = try container.decode(String.self, forKey: .sandboxState)
        runnerState = try container.decode(String.self, forKey: .runnerState)
        blockedReason = try container.decodeIfPresent(String.self, forKey: .blockedReason)
        parentThreadId = try container.decodeIfPresent(String.self, forKey: .parentThreadId)
        rootThreadId = try container.decodeIfPresent(String.self, forKey: .rootThreadId)
        lastActivityAt = try container.decodeIfPresent(String.self, forKey: .lastActivityAt)
        pullRequestCount = try container.decode(Int.self, forKey: .pullRequestCount)
        isOwner = try container.decode(Bool.self, forKey: .isOwner)
        isStarred = try container.decode(Bool.self, forKey: .isStarred)
        ownerSide = try container.decodeIfPresent(String.self, forKey: .ownerSide)
        preview = try container.decodeIfPresent(ThreadPreviewHints.self, forKey: .preview)
        sharedWithCount = try container.decodeIfPresent(Int.self, forKey: .sharedWithCount)
        startedOnDevice = try container.decodeIfPresent(Bool.self, forKey: .startedOnDevice)
        pullRequestOpening = try container.decodeIfPresent(PullRequestOpening.self, forKey: .pullRequestOpening)
        hasRepository = try container.decodeIfPresent(Bool.self, forKey: .hasRepository) ?? true
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
    /// The `moved` notice names the thread the work continues in; all three are nil when the viewer cannot see it.
    public let repo: String?
    public let threadId: String?
    public let threadTitle: String?

    public init(
        kind: String,
        title: String,
        body: String,
        resetsAt: String? = nil,
        canContinue: Bool,
        repo: String? = nil,
        threadId: String? = nil,
        threadTitle: String? = nil
    ) {
        self.kind = kind
        self.title = title
        self.body = body
        self.resetsAt = resetsAt
        self.canContinue = canContinue
        self.repo = repo
        self.threadId = threadId
        self.threadTitle = threadTitle
    }
}

/// The parts of `thread.preview` the terminal reads: the dev server's port and the per-person awake cap.
public struct ThreadPreviewHints: Codable, Equatable, Sendable {
    public let port: Int?
    public let personAwakeCap: Int?

    public init(port: Int? = nil, personAwakeCap: Int? = nil) {
        self.port = port
        self.personAwakeCap = personAwakeCap
    }
}

public struct CapacityCounter: Codable, Equatable, Sendable {
    public let used: Int
    public let limit: Int

    public init(used: Int, limit: Int) {
        self.used = used
        self.limit = limit
    }
}

public struct ThreadCapacity: Codable, Equatable, Sendable {
    public let turns: CapacityCounter
    public let sandboxes: CapacityCounter
    public let share: CapacityCounter

    public init(turns: CapacityCounter, sandboxes: CapacityCounter, share: CapacityCounter) {
        self.turns = turns
        self.sandboxes = sandboxes
        self.share = share
    }
}
