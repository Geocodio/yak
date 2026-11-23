public enum ThreadListFilter: String, Codable, Sendable, CaseIterable { case mine, yak, everyone }

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

    /// One item per thread. A task item whose thread has a row in the list lends its reason to that row when the
    /// reason is more urgent, and leaves the list. A task item whose thread has no row becomes a thread row. A task
    /// item without a thread stays a task item. Runs when the list is read, from the server or from the saved copy.
    static func foldingTasks(into items: [NeedsYouItem], others: [ThreadRow]) -> [NeedsYouItem] {
        var rows: [String: Int] = [:]
        for (index, item) in items.enumerated() {
            if case .thread(let row) = item { rows[row.id] = index }
        }
        let elsewhere = Set(others.flatMap(\.idsIncludingDescendants)).union(
            items.flatMap { item -> [String] in
                if case .thread(let row) = item { row.idsIncludingDescendants.filter { $0 != row.id } } else { [] }
            })
        var result = items
        var removed: Set<Int> = []
        for (index, item) in items.enumerated() {
            guard case .task(let task) = item, let threadID = task.threadId, !threadID.isEmpty else { continue }
            if let rowIndex = rows[threadID], case .thread(let row) = result[rowIndex] {
                if task.needsYouReason.urgency > (row.needsYouReason?.urgency ?? 0) {
                    result[rowIndex] = .thread(row.withReason(task.needsYouReason, label: task.needsYouLabel))
                }
                removed.insert(index)
            } else if elsewhere.contains(threadID) {
                removed.insert(index)
            } else {
                result[index] = .thread(ThreadRow(task: task, threadID: threadID))
                rows[threadID] = index
            }
        }
        return result.enumerated().filter { !removed.contains($0.offset) }.map(\.element)
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        starred = try container.decodeIfPresent([ThreadRow].self, forKey: .starred) ?? []
        threads = try container.decode([ThreadRow].self, forKey: .threads)
        needsYou = Self.foldingTasks(
            into: try container.decode([NeedsYouItem].self, forKey: .needsYou), others: starred + threads)
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

extension NeedsYouReason {
    /// How much the reason asks of the person: a decision or an answer, then a blocked thread, then a finished
    /// thread not seen yet. Another reason, or none, counts as 0.
    var urgency: Int {
        switch self {
        case .coOwner: 4
        case .question: 3
        case .blocked: 2
        case .unseen: 1
        default: 0
        }
    }
}

extension ThreadRow {
    var idsIncludingDescendants: [String] { [id] + children.flatMap(\.idsIncludingDescendants) }

    func withReason(_ reason: NeedsYouReason, label: String?) -> ThreadRow {
        ThreadRow(
            id: id, title: title, repo: repo, status: status, needsYouReason: reason, needsYouLabel: label,
            isPrivate: isPrivate, isShared: isShared, isUnattended: isUnattended, isStarred: isStarred,
            canDelete: canDelete, ownerInitials: ownerInitials, lastActivityAt: lastActivityAt, group: group,
            children: children, step: step)
    }

    /// The row of a task item whose thread is not in the list.
    init(task: TaskNeedsYou, threadID: String) {
        self.init(
            id: threadID, title: task.title, repo: "", status: .stopped, needsYouReason: task.needsYouReason,
            needsYouLabel: task.needsYouLabel, isPrivate: task.isPrivate, isShared: false, isUnattended: false,
            isStarred: false, canDelete: false, lastActivityAt: task.lastActivityAt, group: .today, children: [])
    }
}

public struct TaskNeedsYou: Codable, Equatable, Sendable {
    public let taskId: LenientID
    /// The thread the work lives on; nil for a task without one (a review, a setup run) and from older servers.
    public let threadId: String?
    public let title: String
    public let needsYouReason: NeedsYouReason
    public let needsYouLabel: String?
    /// True when the task's thread is private. Absent from older servers, so it defaults to false.
    public let isPrivate: Bool
    public let lastActivityAt: String?

    public init(
        taskId: LenientID, threadId: String? = nil, title: String, needsYouReason: NeedsYouReason,
        needsYouLabel: String? = nil, isPrivate: Bool = false, lastActivityAt: String? = nil
    ) {
        self.taskId = taskId
        self.threadId = threadId
        self.title = title
        self.needsYouReason = needsYouReason
        self.needsYouLabel = needsYouLabel
        self.isPrivate = isPrivate
        self.lastActivityAt = lastActivityAt
    }

    private enum CodingKeys: String, CodingKey {
        case taskId, threadId, title, needsYouReason, needsYouLabel, isPrivate, lastActivityAt
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        taskId = try container.decode(LenientID.self, forKey: .taskId)
        threadId = try? container.decodeIfPresent(LenientID.self, forKey: .threadId)?.value
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
