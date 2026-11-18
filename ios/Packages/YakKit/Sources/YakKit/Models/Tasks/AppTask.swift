/// The `task` key of the task detail; keys the app does not use are ignored.
public struct AppTaskDetail: Codable, Equatable, Sendable {
    public let id: String
    public let status: TaskStatus
    public let statusLabel: String
    public let mode: String
    public let headline: String?
    public let summary: String
    public let repo: String
    public let sourceLabel: String
    public let sourceUrl: String?
    public let startedBy: String?
    public let pr: TaskRowPullRequest?
    public let nextSteps: String?
    public let branch: String?
    public let cost: String?
}

/// One conversation entry; `bodyHtml` is server-rendered and other keys are ignored.
public struct TaskConversationEntry: Codable, Equatable, Sendable {
    public let kind: String
    public let bodyHtml: String
}

public struct TaskProgressStep: Codable, Equatable, Sendable {
    public let label: String
    public let done: Bool
    public let current: Bool
}

public struct TaskProgress: Codable, Equatable, Sendable {
    public let steps: [TaskProgressStep]
}

public struct CoOwnerViewer: Codable, Equatable, Sendable {
    public let isRequester: Bool
    public let isLinked: Bool
    public let login: String?
    /// write, read, unlinked or error.
    public let access: String
}

public struct CoOwnerStat: Codable, Equatable, Sendable {
    public let additions: Int
    public let deletions: Int
    public let files: Int
}

public struct CoOwnerPanel: Codable, Equatable, Sendable {
    public let id: Int
    public let status: String
    public let decidedBy: String?
    public let feedback: String?
    public let prUrl: String?
    public let holdReasons: [String]
    public let asked: [String]
    public let stat: CoOwnerStat?
    public let viewer: CoOwnerViewer
    public let connectUrl: String
    public let requesterName: String
    /// Thread route only: the viewer can drive the thread. Nil on the task route.
    public let canDecide: Bool?
    /// Thread route only: the viewer is one of the people asked. Nil on the task route.
    public let viewerIsAsked: Bool?

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(Int.self, forKey: .id)
        status = try container.decode(String.self, forKey: .status)
        decidedBy = try container.decodeIfPresent(String.self, forKey: .decidedBy)
        feedback = try container.decodeIfPresent(String.self, forKey: .feedback)
        prUrl = try container.decodeIfPresent(String.self, forKey: .prUrl)
        holdReasons = try container.decodeIfPresent([String].self, forKey: .holdReasons) ?? []
        asked = try container.decodeIfPresent([String].self, forKey: .asked) ?? []
        stat = try container.decodeIfPresent(CoOwnerStat.self, forKey: .stat)
        viewer = try container.decode(CoOwnerViewer.self, forKey: .viewer)
        connectUrl = try container.decode(String.self, forKey: .connectUrl)
        requesterName = try container.decode(String.self, forKey: .requesterName)
        canDecide = try container.decodeIfPresent(Bool.self, forKey: .canDecide)
        viewerIsAsked = try container.decodeIfPresent(Bool.self, forKey: .viewerIsAsked)
    }
}

public struct TaskWalkthrough: Codable, Equatable, Sendable {
    public let status: String
    public let videoUrl: String?
    public let posterUrl: String?
    public let error: String?
}

public struct TaskComposer: Codable, Equatable, Sendable {
    public let state: String
}

public struct TaskActionsAvailable: Codable, Equatable, Sendable {
    public let canRetry: Bool
    public let canCancel: Bool
    public let canRerunReview: Bool
    public let canRequestReview: Bool
    public let canRetryRender: Bool
    public let canReroute: Bool
    public let rerouteTargets: [String]
}

public struct EarlyWarningArea: Codable, Equatable, Sendable {
    public let name: String
    /// high, medium or low; any other word is kept.
    public let risk: String

    public init(name: String, risk: String) {
        self.name = name
        self.risk = risk
    }
}

/// The server's `EarlyWarningData` notice; keys the app does not use are ignored.
public struct EarlyWarningNotice: Codable, Equatable, Sendable {
    public let areas: [EarlyWarningArea]
    /// shown, tagged or declined; any other word is kept.
    public let state: String
    /// The server's rule: state shown and the viewer is the requester.
    public let canPick: Bool
    public let taggedName: String?
    public let fallbackName: String?
    public let repoName: String

    public init(
        areas: [EarlyWarningArea] = [],
        state: String,
        canPick: Bool,
        taggedName: String? = nil,
        fallbackName: String? = nil,
        repoName: String
    ) {
        self.areas = areas
        self.state = state
        self.canPick = canPick
        self.taggedName = taggedName
        self.fallbackName = fallbackName
        self.repoName = repoName
    }

    private enum CodingKeys: String, CodingKey { case areas, state, canPick, taggedName, fallbackName, repoName }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        areas = try container.decodeIfPresent([EarlyWarningArea].self, forKey: .areas) ?? []
        state = try container.decode(String.self, forKey: .state)
        canPick = try container.decode(Bool.self, forKey: .canPick)
        taggedName = try container.decodeIfPresent(String.self, forKey: .taggedName)
        fallbackName = try container.decodeIfPresent(String.self, forKey: .fallbackName)
        repoName = try container.decode(String.self, forKey: .repoName)
    }
}

public struct AppTask: Codable, Equatable, Sendable {
    public let task: AppTaskDetail
    public let threadId: String?
    public let canDrive: Bool
    public let thread: [TaskConversationEntry]
    public let progress: TaskProgress?
    public let coOwner: CoOwnerPanel?
    public let earlyWarning: EarlyWarningNotice?
    public let walkthrough: TaskWalkthrough?
    public let composer: TaskComposer?
    public let questions: [ClarificationQuestion]?
    public let actions: TaskActionsAvailable
    public let pollInterval: Int

    /// The open round of questions, when there are some.
    public var questionRound: ThreadQuestion? {
        guard let questions, !questions.isEmpty else { return nil }
        return ThreadQuestion(taskId: LenientID(task.id), questions: questions)
    }
}

public struct CoOwnerCandidate: Codable, Equatable, Sendable, Identifiable {
    public let id: Int
    public let name: String
}

public struct CoOwnerCandidates: Codable, Equatable, Sendable {
    public let candidates: [CoOwnerCandidate]
    /// False when the server stopped checking GitHub before it reached everyone.
    public let isComplete: Bool

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        candidates = try container.decode([CoOwnerCandidate].self, forKey: .candidates)
        isComplete = try container.decodeIfPresent(Bool.self, forKey: .isComplete) ?? true
    }
}
