public struct TaskStatus: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let pending = TaskStatus(rawValue: "pending")
    public static let running = TaskStatus(rawValue: "running")
    public static let awaitingClarification = TaskStatus(rawValue: "awaiting_clarification")
    public static let awaitingCI = TaskStatus(rawValue: "awaiting_ci")
    public static let retrying = TaskStatus(rawValue: "retrying")
    public static let awaitingCoOwner = TaskStatus(rawValue: "awaiting_co_owner")
    public static let success = TaskStatus(rawValue: "success")
    public static let failed = TaskStatus(rawValue: "failed")
    public static let expired = TaskStatus(rawValue: "expired")
    public static let cancelled = TaskStatus(rawValue: "cancelled")
}

public enum TaskTab: String, CaseIterable, Codable, Sendable { case tasks, reviews, setup }

/// The filters of the Tasks list. Sent as query items by `Endpoint.tasks`.
public struct TaskListQuery: Equatable, Codable, Sendable {
    public var tab: TaskTab = .tasks
    public var status: TaskStatus?
    /// Sent on tab tasks only.
    public var source: String?
    public var repo: String?
    /// open, merged, closed or none; sent as `pr`.
    public var pullRequest: String?
    public var search: String?

    public init() {}
}

public struct TaskRowPullRequest: Codable, Equatable, Sendable {
    public let number: Int?
    public let state: String
    /// nil: shown as plain text, not a link.
    public let url: String?
}

public struct TaskFollowUp: Codable, Equatable, Sendable {
    public let id: String
    public let status: TaskStatus
    public let description: String
    public let externalId: String?
    public let createdAgo: String
}

public struct TaskRow: Codable, Equatable, Sendable, Identifiable {
    public let id: String
    public let status: TaskStatus
    public let source: String
    public let sourceLabel: String
    public let by: String?
    public let repo: String
    public let description: String
    public let externalId: String?
    public let externalUrl: String?
    public let pr: TaskRowPullRequest?
    public let previewUrl: String?
    public let cost: String?
    public let createdAgo: String
    public let createdAt: String?
    public let followUps: [TaskFollowUp]
    public let askedFirstName: String?

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        status = try container.decode(TaskStatus.self, forKey: .status)
        source = try container.decode(String.self, forKey: .source)
        sourceLabel = try container.decode(String.self, forKey: .sourceLabel)
        by = try container.decodeIfPresent(String.self, forKey: .by)
        repo = try container.decode(String.self, forKey: .repo)
        description = try container.decode(String.self, forKey: .description)
        externalId = try container.decodeIfPresent(String.self, forKey: .externalId)
        externalUrl = try container.decodeIfPresent(String.self, forKey: .externalUrl)
        pr = try container.decodeIfPresent(TaskRowPullRequest.self, forKey: .pr)
        previewUrl = try container.decodeIfPresent(String.self, forKey: .previewUrl)
        cost = try container.decodeIfPresent(String.self, forKey: .cost)
        createdAgo = try container.decode(String.self, forKey: .createdAgo)
        createdAt = try container.decodeIfPresent(String.self, forKey: .createdAt)
        followUps = try container.decodeIfPresent([TaskFollowUp].self, forKey: .followUps) ?? []
        askedFirstName = try container.decodeIfPresent(String.self, forKey: .askedFirstName)
    }
}

public struct TaskCounts: Codable, Equatable, Sendable {
    public let tasks: Int
    public let reviews: Int
    public let setup: Int
    public let needsCoOwner: Int
}

public struct TaskListOptions: Codable, Equatable, Sendable {
    public let repos: [String]
    public let sources: [String]
}

public struct TaskListPage: Codable, Equatable, Sendable {
    public let tasks: [TaskRow]
    public let nextPage: Int?
    public let counts: TaskCounts
    public let options: TaskListOptions
}
