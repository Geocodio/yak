import Foundation

/// Where a request came from. `key` is an open enum; the app shows `label`.
public struct ThreadSource: Codable, Equatable, Sendable {
    public let key: SourceKey
    public let label: String
    public let reference: String?
    public let url: String?

    public init(key: SourceKey, label: String, reference: String? = nil, url: String? = nil) {
        self.key = key
        self.label = label
        self.reference = reference
        self.url = url
    }

    private enum CodingKeys: String, CodingKey { case key, label, reference, url }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        key = (try? container.decodeIfPresent(SourceKey.self, forKey: .key)) ?? SourceKey(rawValue: "")
        label = (try? container.decodeIfPresent(String.self, forKey: .label)) ?? ""
        reference = try? container.decodeIfPresent(String.self, forKey: .reference)
        url = try? container.decodeIfPresent(String.self, forKey: .url)
    }

    /// What a badge says: the label, or the key when the server sent no label. Nil when there is neither. Every field
    /// is read leniently, so an older or newer server never makes a row fail to decode.
    public var displayName: String? {
        let name = label.trimmingCharacters(in: .whitespacesAndNewlines)
        if !name.isEmpty { return name }
        let fallback = key.rawValue.trimmingCharacters(in: .whitespacesAndNewlines)
        return fallback.isEmpty ? nil : fallback
    }
}

/// The channel a message came from; the same shape as a request's source.
public typealias MessageVia = ThreadSource

public struct ThreadStarter: Codable, Equatable, Sendable {
    public let key: String
    public let name: String
    public let avatarUrl: String?

    public init(key: String, name: String, avatarUrl: String? = nil) {
        self.key = key
        self.name = name
        self.avatarUrl = avatarUrl
    }

    private enum CodingKeys: String, CodingKey { case key, name, avatarUrl }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        key = try container.decode(String.self, forKey: .key)
        name = try container.decode(String.self, forKey: .name)
        avatarUrl = try container.decodeIfPresent(String.self, forKey: .avatarUrl)
    }
}

public struct ThreadRequestStatus: Codable, Equatable, Sendable {
    public let key: RequestStatusKey
    public let label: String
    public let tone: Tone

    public init(key: RequestStatusKey, label: String, tone: Tone) {
        self.key = key
        self.label = label
        self.tone = tone
    }
}

public struct ThreadRequest: Codable, Equatable, Sendable {
    public let source: ThreadSource
    public let starter: ThreadStarter
    public let responsibleName: String?
    public let status: ThreadRequestStatus

    public init(
        source: ThreadSource,
        starter: ThreadStarter,
        responsibleName: String? = nil,
        status: ThreadRequestStatus
    ) {
        self.source = source
        self.starter = starter
        self.responsibleName = responsibleName
        self.status = status
    }

    private enum CodingKeys: String, CodingKey { case source, starter, responsibleName, status }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        source = try container.decode(ThreadSource.self, forKey: .source)
        starter = try container.decode(ThreadStarter.self, forKey: .starter)
        responsibleName = try container.decodeIfPresent(String.self, forKey: .responsibleName)
        status = try container.decode(ThreadRequestStatus.self, forKey: .status)
    }
}

public struct ThreadProgressStep: Codable, Equatable, Sendable, Identifiable {
    public let key: ProgressStepKey
    public let label: String
    public let tooltip: String
    public let state: StepState

    public var id: String { key.rawValue }

    public init(key: ProgressStepKey, label: String, tooltip: String = "", state: StepState) {
        self.key = key
        self.label = label
        self.tooltip = tooltip
        self.state = state
    }

    private enum CodingKeys: String, CodingKey { case key, label, tooltip, state }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        key = try container.decode(ProgressStepKey.self, forKey: .key)
        label = try container.decode(String.self, forKey: .label)
        tooltip = try container.decodeIfPresent(String.self, forKey: .tooltip) ?? ""
        state = try container.decode(StepState.self, forKey: .state)
    }
}

/// The steps of a request. The list has seven or eight steps (the co-owner slot comes and goes), so a step is
/// always found by its key, never by position.
public struct ThreadProgress: Codable, Equatable, Sendable {
    public let steps: [ThreadProgressStep]

    public init(steps: [ThreadProgressStep]) { self.steps = steps }

    public func step(_ key: ProgressStepKey) -> ThreadProgressStep? { steps.first { $0.key == key } }

    /// The failed step, else the current one.
    public var attentionStep: ThreadProgressStep? {
        steps.first { $0.state == .failed } ?? steps.first { $0.state == .current }
    }
}

/// A one-line event in the conversation, such as "CI passed". It sits after the events of its run up to
/// `afterSequence`.
public struct ThreadMilestone: Codable, Equatable, Sendable, Identifiable {
    public let key: MilestoneKey
    public let label: String
    public let tone: Tone
    public let url: String?
    /// A run id, or the old task's public id on a legacy thread.
    public let runId: String
    public let afterSequence: Int

    public var id: String { "\(runId):\(key.rawValue)" }

    public init(
        key: MilestoneKey,
        label: String,
        tone: Tone,
        url: String? = nil,
        runId: String,
        afterSequence: Int
    ) {
        self.key = key
        self.label = label
        self.tone = tone
        self.url = url
        self.runId = runId
        self.afterSequence = afterSequence
    }
}

public struct RepositoryQuestion: Codable, Equatable, Sendable {
    public let prompt: String
    public let options: [String]
    public let canAnswer: Bool

    public init(prompt: String, options: [String], canAnswer: Bool) {
        self.prompt = prompt
        self.options = options
        self.canAnswer = canAnswer
    }

    private enum CodingKeys: String, CodingKey { case prompt, options, canAnswer }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        prompt = try container.decode(String.self, forKey: .prompt)
        options = try container.decode([String].self, forKey: .options)
        canAnswer = try container.decodeIfPresent(Bool.self, forKey: .canAnswer) ?? false
    }
}

public struct ThreadStop: Codable, Equatable, Sendable {
    public let kind: StopKind
    public let summary: String
    public let detail: String?
    public let nextStep: String
    public let isWaitingForSetup: Bool

    public init(
        kind: StopKind,
        summary: String,
        detail: String? = nil,
        nextStep: String,
        isWaitingForSetup: Bool = false
    ) {
        self.kind = kind
        self.summary = summary
        self.detail = detail
        self.nextStep = nextStep
        self.isWaitingForSetup = isWaitingForSetup
    }

    private enum CodingKeys: String, CodingKey { case kind, summary, detail, nextStep, isWaitingForSetup }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        kind = try container.decode(StopKind.self, forKey: .kind)
        summary = try container.decode(String.self, forKey: .summary)
        detail = try container.decodeIfPresent(String.self, forKey: .detail)
        nextStep = try container.decode(String.self, forKey: .nextStep)
        isWaitingForSetup = try container.decodeIfPresent(Bool.self, forKey: .isWaitingForSetup) ?? false
    }
}

/// What the person may do to the work of a thread. Every flag is false for a reader, a closed thread, a legacy
/// thread and a thread with no task.
public struct ThreadActionFlags: Codable, Equatable, Sendable {
    public let canStop: Bool
    public let canRetry: Bool
    public let canCancel: Bool
    public let canMove: Bool
    public let moveTargets: [String]
    public let canRequestReview: Bool
    public let canRetryRender: Bool

    public static let none = ThreadActionFlags()

    public init(
        canStop: Bool = false,
        canRetry: Bool = false,
        canCancel: Bool = false,
        canMove: Bool = false,
        moveTargets: [String] = [],
        canRequestReview: Bool = false,
        canRetryRender: Bool = false
    ) {
        self.canStop = canStop
        self.canRetry = canRetry
        self.canCancel = canCancel
        self.canMove = canMove
        self.moveTargets = moveTargets
        self.canRequestReview = canRequestReview
        self.canRetryRender = canRetryRender
    }

    private enum CodingKeys: String, CodingKey {
        case canStop, canRetry, canCancel, canMove, moveTargets, canRequestReview, canRetryRender
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        canStop = try container.decodeIfPresent(Bool.self, forKey: .canStop) ?? false
        canRetry = try container.decodeIfPresent(Bool.self, forKey: .canRetry) ?? false
        canCancel = try container.decodeIfPresent(Bool.self, forKey: .canCancel) ?? false
        canMove = try container.decodeIfPresent(Bool.self, forKey: .canMove) ?? false
        moveTargets = try container.decodeIfPresent([String].self, forKey: .moveTargets) ?? []
        canRequestReview = try container.decodeIfPresent(Bool.self, forKey: .canRequestReview) ?? false
        canRetryRender = try container.decodeIfPresent(Bool.self, forKey: .canRetryRender) ?? false
    }
}

/// One answer of a closed thread that was made from an old task. `html` is server rendered.
public struct LegacyAnswer: Codable, Equatable, Sendable, Identifiable {
    public let runTaskId: String
    public let html: String
    public let at: String

    public var id: String { runTaskId }

    public init(runTaskId: String, html: String, at: String) {
        self.runTaskId = runTaskId
        self.html = html
        self.at = at
    }
}

public struct SourceKey: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let slack = SourceKey(rawValue: "slack")
    public static let linear = SourceKey(rawValue: "linear")
    public static let sentry = SourceKey(rawValue: "sentry")
    public static let github = SourceKey(rawValue: "github")
    public static let email = SourceKey(rawValue: "email")
    public static let dashboard = SourceKey(rawValue: "dashboard")
}

public struct RequestStatusKey: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let closed = RequestStatusKey(rawValue: "closed")
    public static let waitingForCoOwner = RequestStatusKey(rawValue: "waiting_for_co_owner")
    public static let needsYou = RequestStatusKey(rawValue: "needs_you")
    public static let stopped = RequestStatusKey(rawValue: "stopped")
    public static let working = RequestStatusKey(rawValue: "working")
    public static let done = RequestStatusKey(rawValue: "done")
}

/// ok, warn, bad, info, neutral. Any other word is kept.
public struct Tone: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let neutral = Tone(rawValue: "neutral")
    public static let warn = Tone(rawValue: "warn")
    public static let bad = Tone(rawValue: "bad")
    public static let info = Tone(rawValue: "info")
    public static let ok = Tone(rawValue: "ok")
}

public struct ProgressStepKey: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let received = ProgressStepKey(rawValue: "received")
    public static let pickedUp = ProgressStepKey(rawValue: "picked_up")
    public static let working = ProgressStepKey(rawValue: "working")
    public static let pushed = ProgressStepKey(rawValue: "pushed")
    public static let ci = ProgressStepKey(rawValue: "ci")
    public static let coOwner = ProgressStepKey(rawValue: "co_owner")
    public static let pullRequest = ProgressStepKey(rawValue: "pull_request")
    public static let done = ProgressStepKey(rawValue: "done")
}

public struct StepState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let done = StepState(rawValue: "done")
    public static let current = StepState(rawValue: "current")
    public static let failed = StepState(rawValue: "failed")
    public static let upcoming = StepState(rawValue: "upcoming")
}

public struct MilestoneKey: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let pushed = MilestoneKey(rawValue: "pushed")
    public static let ciPassed = MilestoneKey(rawValue: "ci_passed")
    public static let ciFailed = MilestoneKey(rawValue: "ci_failed")
    public static let pullRequestOpened = MilestoneKey(rawValue: "pull_request_opened")
    public static let pullRequestHeld = MilestoneKey(rawValue: "pull_request_held")
    public static let walkthroughReady = MilestoneKey(rawValue: "walkthrough_ready")
    public static let researchReport = MilestoneKey(rawValue: "research_report")
}

/// Which card the server wants above the composer. Only the named card shows.
public struct ThreadCard: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let question = ThreadCard(rawValue: "question")
    public static let repositoryQuestion = ThreadCard(rawValue: "repository_question")
    public static let earlyWarning = ThreadCard(rawValue: "early_warning")
    public static let coOwner = ThreadCard(rawValue: "co_owner")
    public static let stopped = ThreadCard(rawValue: "stopped")
}

public struct StopKind: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let needsYou = StopKind(rawValue: "needs_you")
    public static let yakRetrying = StopKind(rawValue: "yak_retrying")
    public static let yakStopped = StopKind(rawValue: "yak_stopped")
    public static let cancelled = StopKind(rawValue: "cancelled")
}
