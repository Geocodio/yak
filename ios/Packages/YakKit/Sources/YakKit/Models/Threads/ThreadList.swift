public enum ThreadListFilter: String, Codable, Sendable, CaseIterable { case mine, yak, everyone }

public struct ThreadList: Codable, Equatable, Sendable {
    public let starred: [ThreadRow]
    public let needsYou: [NeedsYouItem]
    public let threads: [ThreadRow]
    public let needsYouCount: Int
    public let hasAnyThreads: Bool

    /// Folds the task items of `needsYou` into their threads (see `folded`), so no caller can bypass the fold.
    public init(
        starred: [ThreadRow],
        needsYou: [NeedsYouItem],
        threads: [ThreadRow],
        needsYouCount: Int,
        hasAnyThreads: Bool
    ) {
        let folded = Self.folded(starred: starred, needsYou: needsYou, threads: threads)
        self.starred = folded.starred
        self.needsYou = folded.needsYou
        self.threads = folded.threads
        self.needsYouCount = needsYouCount
        self.hasAnyThreads = hasAnyThreads
    }

    private enum CodingKeys: String, CodingKey { case starred, needsYou, threads, needsYouCount, hasAnyThreads }

    /// One item per thread, so nothing that needs the person is listed twice or missing.
    /// A task item names a thread. When that thread (or the root that holds it as a sub-thread) has a row in the
    /// list, the row takes the task's reason if it asks more of the person, and moves into needs-you if it sat in
    /// the starred or the other threads. When no row has the thread, the item becomes a closed thread row. A task item
    /// without a thread stays a task item. Runs when the list is read, from the server or from the saved copy.
    static func folded(starred: [ThreadRow], needsYou: [NeedsYouItem], threads: [ThreadRow]) -> (
        starred: [ThreadRow], needsYou: [NeedsYouItem], threads: [ThreadRow]
    ) {
        var starred = starred
        var threads = threads
        var items = needsYou
        var index = 0
        while index < items.count {
            guard case .task(let task) = items[index], let threadID = task.threadId, !threadID.isEmpty else {
                index += 1
                continue
            }
            if let place = items.indices.first(where: { rowContains(items[$0], threadID) }),
                case .thread(let row) = items[place]
            {
                items[place] = .thread(row.applying(task, to: threadID))
                items.remove(at: index)
                continue
            } else if let place = starred.firstIndex(where: { $0.idsIncludingDescendants.contains(threadID) }) {
                items[index] = .thread(starred.remove(at: place).applying(task, to: threadID))
            } else if let place = threads.firstIndex(where: { $0.idsIncludingDescendants.contains(threadID) }) {
                items[index] = .thread(threads.remove(at: place).applying(task, to: threadID))
            } else {
                items[index] = .thread(ThreadRow(task: task, threadID: threadID))
            }
            index += 1
        }
        return (starred, items, threads)
    }

    private static func rowContains(_ item: NeedsYouItem, _ threadID: String) -> Bool {
        if case .thread(let row) = item { row.idsIncludingDescendants.contains(threadID) } else { false }
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        self.init(
            starred: try container.decodeIfPresent([ThreadRow].self, forKey: .starred) ?? [],
            needsYou: try container.decode([NeedsYouItem].self, forKey: .needsYou),
            threads: try container.decode([ThreadRow].self, forKey: .threads),
            needsYouCount: try container.decode(Int.self, forKey: .needsYouCount),
            hasAnyThreads: try container.decode(Bool.self, forKey: .hasAnyThreads))
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
    /// Where the thread started (Slack, Linear, the dashboard...); nil from an older server, when it sent none, and
    /// when it sent something that is not a source object. A bad `source` never fails the row.
    public let source: ThreadSource?

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
        step: String? = nil,
        source: ThreadSource? = nil
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
        self.source = source
    }

    private enum CodingKeys: String, CodingKey {
        case id, title, repo, status, needsYouReason, needsYouLabel, isPrivate, isShared, isUnattended, isStarred
        case canDelete, ownerInitials, lastActivityAt, group, children, step, source
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        title = try container.decode(String.self, forKey: .title)
        repo = try container.decode(String.self, forKey: .repo)
        status = try container.decode(ThreadRowStatus.self, forKey: .status)
        needsYouReason = try container.decodeIfPresent(NeedsYouReason.self, forKey: .needsYouReason)
        needsYouLabel = try container.decodeIfPresent(String.self, forKey: .needsYouLabel)
        isPrivate = try container.decode(Bool.self, forKey: .isPrivate)
        isShared = try container.decode(Bool.self, forKey: .isShared)
        isUnattended = try container.decode(Bool.self, forKey: .isUnattended)
        isStarred = try container.decode(Bool.self, forKey: .isStarred)
        canDelete = try container.decode(Bool.self, forKey: .canDelete)
        ownerInitials = try container.decodeIfPresent(String.self, forKey: .ownerInitials)
        lastActivityAt = try container.decodeIfPresent(String.self, forKey: .lastActivityAt)
        group = try container.decode(ThreadRowGroup.self, forKey: .group)
        children = try container.decode([ThreadRow].self, forKey: .children)
        step = try container.decodeIfPresent(String.self, forKey: .step)
        source = (try? container.decodeIfPresent(ThreadSource.self, forKey: .source)) ?? nil
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

    /// The row with the task's reason on the row of `threadID` (this row or a sub-thread), and on this row when the
    /// reason asks more of the person than the one it has.
    func applying(_ task: TaskNeedsYou, to threadID: String) -> ThreadRow {
        let reason = task.needsYouReason
        let asksMore = needsYouReason == nil || reason.urgency > (needsYouReason?.urgency ?? 0)
        return ThreadRow(
            id: id, title: title, repo: repo, status: status,
            needsYouReason: asksMore ? reason : needsYouReason,
            needsYouLabel: asksMore ? task.needsYouLabel : needsYouLabel,
            isPrivate: isPrivate, isShared: isShared, isUnattended: isUnattended, isStarred: isStarred,
            canDelete: canDelete, ownerInitials: ownerInitials, lastActivityAt: lastActivityAt, group: group,
            children: children.map {
                $0.idsIncludingDescendants.contains(threadID) ? $0.applying(task, to: threadID) : $0
            },
            step: step, source: source)
    }

    /// The row of a task item whose thread is not in the list.
    init(task: TaskNeedsYou, threadID: String) {
        self.init(
            id: threadID, title: task.title, repo: "", status: .closed, needsYouReason: task.needsYouReason,
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
