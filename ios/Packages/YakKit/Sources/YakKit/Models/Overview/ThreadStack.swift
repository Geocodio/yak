public struct StackChildState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    /// Open, no turn run yet, and not waiting for a free spot.
    public static let starting = StackChildState(rawValue: "starting")
    /// Waiting for a free spot; the web says "Waiting".
    public static let queued = StackChildState(rawValue: "queued")
    public static let working = StackChildState(rawValue: "working")
    /// The sub-thread's turn ended and it needs the person; the web says "Needs you".
    public static let waiting = StackChildState(rawValue: "waiting")
    public static let done = StackChildState(rawValue: "done")
    public static let failed = StackChildState(rawValue: "failed")
    public static let closed = StackChildState(rawValue: "closed")
}

public struct StackChildKind: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let research = StackChildKind(rawValue: "research")
    public static let implement = StackChildKind(rawValue: "implement")
    public static let mockup = StackChildKind(rawValue: "mockup")
    public static let general = StackChildKind(rawValue: "general")
}

public struct StackMessageKind: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let message = StackMessageKind(rawValue: "message")
    public static let question = StackMessageKind(rawValue: "question")
    public static let answer = StackMessageKind(rawValue: "answer")
    public static let result = StackMessageKind(rawValue: "result")
    public static let failure = StackMessageKind(rawValue: "failure")
}

public struct StackParent: Codable, Equatable, Sendable {
    public let id: String
    public let name: String
    public let title: String

    public init(id: String, name: String, title: String) {
        self.id = id
        self.name = name
        self.title = title
    }
}

public struct StackChild: Codable, Equatable, Sendable, Identifiable {
    public let id: String
    public let name: String
    public let title: String
    public let kind: StackChildKind
    public let model: String?
    public let state: StackChildState
    public let step: String?
    public let resultSummary: String?
    public let branch: String?
    public let repo: String?
    public let pullRequestUrl: String?
    public let ciStatus: String?
    public let filesChanged: Int?
    public let queued: Bool
    public let messagesPaused: Bool
    public let takenOver: Bool
    public let canOverride: Bool

    public init(
        id: String,
        name: String,
        title: String,
        kind: StackChildKind,
        model: String? = nil,
        state: StackChildState,
        step: String? = nil,
        resultSummary: String? = nil,
        branch: String? = nil,
        repo: String? = nil,
        pullRequestUrl: String? = nil,
        ciStatus: String? = nil,
        filesChanged: Int? = nil,
        queued: Bool = false,
        messagesPaused: Bool = false,
        takenOver: Bool = false,
        canOverride: Bool = false
    ) {
        self.id = id
        self.name = name
        self.title = title
        self.kind = kind
        self.model = model
        self.state = state
        self.step = step
        self.resultSummary = resultSummary
        self.branch = branch
        self.repo = repo
        self.pullRequestUrl = pullRequestUrl
        self.ciStatus = ciStatus
        self.filesChanged = filesChanged
        self.queued = queued
        self.messagesPaused = messagesPaused
        self.takenOver = takenOver
        self.canOverride = canOverride
    }
}

public struct StackLimit: Codable, Equatable, Sendable {
    public let current: Int
    public let max: Int
    public let awake: Int
    public let waiting: Int

    public init(current: Int, max: Int, awake: Int, waiting: Int) {
        self.current = current
        self.max = max
        self.awake = awake
        self.waiting = waiting
    }
}

public struct StackUsage: Codable, Equatable, Sendable {
    public let turnCount: Int
    public let costUsd: Double
    public let activeHours: Double
    public let note: String?

    public init(turnCount: Int, costUsd: Double, activeHours: Double, note: String? = nil) {
        self.turnCount = turnCount
        self.costUsd = costUsd
        self.activeHours = activeHours
        self.note = note
    }
}

public struct StackMessage: Codable, Equatable, Sendable, Identifiable {
    public let id: LenientID
    public let fromName: String
    public let toName: String
    public let kind: StackMessageKind
    public let text: String
    public let createdAt: String

    public init(
        id: LenientID, fromName: String, toName: String, kind: StackMessageKind, text: String, createdAt: String
    ) {
        self.id = id
        self.fromName = fromName
        self.toName = toName
        self.kind = kind
        self.text = text
        self.createdAt = createdAt
    }
}

/// Whether adding a sub-thread would be accepted now. `message` is the server's sentence for a refusal.
public struct AddChildAvailability: Codable, Equatable, Sendable {
    public let allowed: Bool
    public let error: String?
    public let message: String?

    public init(allowed: Bool, error: String? = nil, message: String? = nil) {
        self.allowed = allowed
        self.error = error
        self.message = message
    }
}

public struct ThreadStack: Codable, Equatable, Sendable {
    public let rootId: String
    public let isRoot: Bool
    public let parent: StackParent?
    public let children: [StackChild]
    public let limit: StackLimit
    public let maxChildren: Int
    public let models: [ModelFamily]
    public let parentAccountName: String?
    public let usage: StackUsage
    public let messages: [StackMessage]
    public let canDrive: Bool
    public let canStop: Bool
    public let stoppedAt: String?
    public let addChild: AddChildAvailability

    public init(
        rootId: String,
        isRoot: Bool,
        parent: StackParent? = nil,
        children: [StackChild],
        limit: StackLimit,
        maxChildren: Int,
        models: [ModelFamily],
        parentAccountName: String? = nil,
        usage: StackUsage,
        messages: [StackMessage],
        canDrive: Bool,
        canStop: Bool,
        stoppedAt: String? = nil,
        addChild: AddChildAvailability
    ) {
        self.rootId = rootId
        self.isRoot = isRoot
        self.parent = parent
        self.children = children
        self.limit = limit
        self.maxChildren = maxChildren
        self.models = models
        self.parentAccountName = parentAccountName
        self.usage = usage
        self.messages = messages
        self.canDrive = canDrive
        self.canStop = canStop
        self.stoppedAt = stoppedAt
        self.addChild = addChild
    }
}

public struct SubThreadCreated: Codable, Equatable, Sendable {
    public let id: String
    public let name: String

    public init(id: String, name: String) {
        self.id = id
        self.name = name
    }
}

public struct AwakeLimitUpdated: Codable, Equatable, Sendable {
    public let status: String
    public let limit: StackLimit

    public init(status: String, limit: StackLimit) {
        self.status = status
        self.limit = limit
    }
}
