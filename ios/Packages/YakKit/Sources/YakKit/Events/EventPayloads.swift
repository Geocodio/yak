/// A chunk of streaming text that is not stored; the stored `entry` replaces it.
public struct StreamDelta: Decodable, Equatable, Sendable {
    public let blockKey: String
    public let kind: String
    public let text: String

    public init(blockKey: String, kind: String, text: String) {
        self.blockKey = blockKey
        self.kind = kind
        self.text = text
    }
}

public struct TurnChange: Decodable, Equatable, Sendable {
    public let turnId: LenientID
    public let state: TurnState

    public init(turnId: LenientID, state: TurnState) {
        self.turnId = turnId
        self.state = state
    }
}

public struct ThreadStateChange: Decodable, Equatable, Sendable {
    public let status: ThreadStatus
    public let sandboxState: String
    public let runnerState: String
    public let blockedReason: String?
    public let pullRequestOpening: String?
    /// Present only when a card changed; the show is refetched for every newer `thread` event regardless.
    public let showKey: String?

    public init(
        status: ThreadStatus,
        sandboxState: String,
        runnerState: String,
        blockedReason: String? = nil,
        pullRequestOpening: String? = nil,
        showKey: String? = nil
    ) {
        self.status = status
        self.sandboxState = sandboxState
        self.runnerState = runnerState
        self.blockedReason = blockedReason
        self.pullRequestOpening = pullRequestOpening
        self.showKey = showKey
    }
}

public struct MessageStateChange: Decodable, Equatable, Sendable {
    public let uuid: String
    public let state: String

    public init(uuid: String, state: String) {
        self.uuid = uuid
        self.state = state
    }
}

/// The latest state of one sub-thread. Optional fields use `decodeIfPresent` through the synthesized
/// decoder, so the server can add or leave out fields.
public struct ChildCard: Codable, Equatable, Sendable {
    public let childThreadId: LenientID
    public let name: String
    public let kind: String
    public let model: String?
    public let state: String
    public let step: String?
    public let resultSummary: String?
    public let branch: String?
    public let messagesPaused: Bool
    public let question: String?
    public let filesChanged: Int?
    public let additions: Int?
    public let deletions: Int?
    public let decision: String?

    public init(
        childThreadId: LenientID,
        name: String,
        kind: String,
        model: String? = nil,
        state: String,
        step: String? = nil,
        resultSummary: String? = nil,
        branch: String? = nil,
        messagesPaused: Bool,
        question: String? = nil,
        filesChanged: Int? = nil,
        additions: Int? = nil,
        deletions: Int? = nil,
        decision: String? = nil
    ) {
        self.childThreadId = childThreadId
        self.name = name
        self.kind = kind
        self.model = model
        self.state = state
        self.step = step
        self.resultSummary = resultSummary
        self.branch = branch
        self.messagesPaused = messagesPaused
        self.question = question
        self.filesChanged = filesChanged
        self.additions = additions
        self.deletions = deletions
        self.decision = decision
    }
}

/// The latest state of one repository Claude added to the thread.
public struct RepositoryCard: Codable, Equatable, Sendable {
    public let id: LenientID?
    public let repo: String
    public let role: String
    public let state: String
    public let path: String?
    public let reason: String?
    public let error: String?

    public init(
        id: LenientID? = nil,
        repo: String,
        role: String,
        state: String,
        path: String? = nil,
        reason: String? = nil,
        error: String? = nil
    ) {
        self.id = id
        self.repo = repo
        self.role = role
        self.state = state
        self.path = path
        self.reason = reason
        self.error = error
    }
}

public struct QuestionChange: Decodable, Equatable, Sendable {
    public let open: Bool

    public init(open: Bool) {
        self.open = open
    }
}
