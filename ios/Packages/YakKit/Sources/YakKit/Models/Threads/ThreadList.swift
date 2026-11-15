public enum ThreadListFilter: String, Sendable, CaseIterable { case mine, yak, everyone }

public struct ThreadList: Codable, Equatable, Sendable {
    public let starred: [ThreadRow]
    public let needsYou: [NeedsYouItem]
    public let threads: [ThreadRow]
    public let needsYouCount: Int
    public let hasAnyThreads: Bool

    public init(
        starred: [ThreadRow],
        needsYou: [NeedsYouItem],
        threads: [ThreadRow],
        needsYouCount: Int,
        hasAnyThreads: Bool
    ) {
        self.starred = starred
        self.needsYou = needsYou
        self.threads = threads
        self.needsYouCount = needsYouCount
        self.hasAnyThreads = hasAnyThreads
    }

    private enum CodingKeys: String, CodingKey { case starred, needsYou, threads, needsYouCount, hasAnyThreads }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        starred = try container.decodeIfPresent([ThreadRow].self, forKey: .starred) ?? []
        needsYou = try container.decode([NeedsYouItem].self, forKey: .needsYou)
        threads = try container.decode([ThreadRow].self, forKey: .threads)
        needsYouCount = try container.decode(Int.self, forKey: .needsYouCount)
        hasAnyThreads = try container.decode(Bool.self, forKey: .hasAnyThreads)
    }
}

public struct ThreadRow: Codable, Equatable, Sendable {
    public let id: String
    public let title: String
    public let repo: String
    public let status: ThreadRowStatus
    public let needsYouReason: NeedsYouReason?
    public let needsYouLabel: String?
    public let isPrivate: Bool
    public let isShared: Bool
    public let isUnattended: Bool
    public let isStarred: Bool
    public let canDelete: Bool
    public let ownerInitials: String?
    public let lastActivityAt: String?
    public let group: ThreadRowGroup
    public let children: [ThreadRow]
    public let step: String?

    public init(
        id: String,
        title: String,
        repo: String,
        status: ThreadRowStatus,
        needsYouReason: NeedsYouReason? = nil,
        needsYouLabel: String? = nil,
        isPrivate: Bool,
        isShared: Bool,
        isUnattended: Bool,
        isStarred: Bool,
        canDelete: Bool,
        ownerInitials: String? = nil,
        lastActivityAt: String? = nil,
        group: ThreadRowGroup,
        children: [ThreadRow],
        step: String? = nil
    ) {
        self.id = id
        self.title = title
        self.repo = repo
        self.status = status
        self.needsYouReason = needsYouReason
        self.needsYouLabel = needsYouLabel
        self.isPrivate = isPrivate
        self.isShared = isShared
        self.isUnattended = isUnattended
        self.isStarred = isStarred
        self.canDelete = canDelete
        self.ownerInitials = ownerInitials
        self.lastActivityAt = lastActivityAt
        self.group = group
        self.children = children
        self.step = step
    }
}

public struct TaskNeedsYou: Codable, Equatable, Sendable {
    public let taskId: LenientID
    public let title: String
    public let needsYouReason: NeedsYouReason
    public let needsYouLabel: String?
    /// True when the task's thread is private. Absent from older servers, so it defaults to false.
    public let isPrivate: Bool
    public let lastActivityAt: String?

    public init(
        taskId: LenientID, title: String, needsYouReason: NeedsYouReason, needsYouLabel: String? = nil,
        isPrivate: Bool = false, lastActivityAt: String? = nil
    ) {
        self.taskId = taskId
        self.title = title
        self.needsYouReason = needsYouReason
        self.needsYouLabel = needsYouLabel
        self.isPrivate = isPrivate
        self.lastActivityAt = lastActivityAt
    }

    private enum CodingKeys: String, CodingKey {
        case taskId, title, needsYouReason, needsYouLabel, isPrivate, lastActivityAt
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        taskId = try container.decode(LenientID.self, forKey: .taskId)
        title = try container.decode(String.self, forKey: .title)
        needsYouReason = try container.decode(NeedsYouReason.self, forKey: .needsYouReason)
        needsYouLabel = try container.decodeIfPresent(String.self, forKey: .needsYouLabel)
        isPrivate = try container.decodeIfPresent(Bool.self, forKey: .isPrivate) ?? false
        lastActivityAt = try container.decodeIfPresent(String.self, forKey: .lastActivityAt)
    }
}

/// One entry of the needs-you list. A missing `type` means a thread.
public enum NeedsYouItem: Codable, Equatable, Sendable {
    case thread(ThreadRow)
    case task(TaskNeedsYou)
    case unknown(type: String)

    private enum CodingKeys: String, CodingKey { case type }

    public init(from decoder: any Decoder) throws {
        let type =
            try decoder.container(keyedBy: CodingKeys.self).decodeIfPresent(String.self, forKey: .type) ?? "thread"
        switch type {
        case "thread": self = .thread(try ThreadRow(from: decoder))
        case "task": self = .task(try TaskNeedsYou(from: decoder))
        default: self = .unknown(type: type)
        }
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        switch self {
        case .thread(let row):
            try container.encode("thread", forKey: .type)
            try row.encode(to: encoder)
        case .task(let task):
            try container.encode("task", forKey: .type)
            try task.encode(to: encoder)
        case .unknown(let type):
            try container.encode(type, forKey: .type)
        }
    }
}
