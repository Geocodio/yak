public enum ThreadListFilter: String, Sendable, CaseIterable { case mine, yak, everyone }

public struct ThreadList: Codable, Equatable, Sendable {
    public let starred: [ThreadRow]
    public let needsYou: [NeedsYouItem]
    public let threads: [ThreadRow]
    public let needsYouCount: Int
    public let hasAnyThreads: Bool

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
}

public struct TaskNeedsYou: Codable, Equatable, Sendable {
    public let taskId: LenientID
    public let title: String
    public let needsYouReason: NeedsYouReason
    public let needsYouLabel: String?
    public let lastActivityAt: String?
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
