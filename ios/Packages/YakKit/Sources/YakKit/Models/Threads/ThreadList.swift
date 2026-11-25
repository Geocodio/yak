#if canImport(os)
    import os
#endif

private func logDroppedEntry(_ typeName: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "ThreadList").error(
            "dropped an undecodable \(typeName, privacy: .public)")
    #endif
}

/// The tabs of the thread list, by the word the server takes in `filter`. `yak` is the old chip's choice: it is
/// still decoded (a saved window, an older server) but a current server is asked for `mine` with a starter instead.
public enum ThreadListFilter: String, Codable, Sendable, CaseIterable { case mine, yak, everyone, closed, review }

/// One source or starter the Filter sheet offers, with how many threads of the selected tab it covers.
public struct ThreadFacetOption: Codable, Equatable, Sendable {
    public let key: String
    public let label: String
    public let count: Int

    public init(key: String, label: String, count: Int) {
        self.key = key
        self.label = label
        self.count = count
    }

    private enum CodingKeys: String, CodingKey { case key, label, name, count }

    /// A source has a `label`, a starter a `name`; an entry with no key is dropped by the caller.
    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        key = try container.decode(String.self, forKey: .key)
        let text =
            (try? container.decodeIfPresent(String.self, forKey: .label))
            ?? (try? container.decodeIfPresent(String.self, forKey: .name))
        label = text ?? key
        count = (try? container.decodeIfPresent(Int.self, forKey: .count)) ?? 0
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(key, forKey: .key)
        try container.encode(label, forKey: .label)
        try container.encode(count, forKey: .count)
    }
}

/// The sources and starters of the selected tab, before the person's own filters apply.
public struct ThreadFacets: Codable, Equatable, Sendable {
    public let sources: [ThreadFacetOption]
    public let starters: [ThreadFacetOption]

    public init(sources: [ThreadFacetOption], starters: [ThreadFacetOption]) {
        self.sources = sources
        self.starters = starters
    }

    private enum CodingKeys: String, CodingKey { case sources, starters }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        sources =
            ((try? container.decodeIfPresent([Lossy<ThreadFacetOption>].self, forKey: .sources)) ?? nil)?
            .compactMap(\.value) ?? []
        starters =
            ((try? container.decodeIfPresent([Lossy<ThreadFacetOption>].self, forKey: .starters)) ?? nil)?
            .compactMap(\.value) ?? []
    }
}

/// A list entry that is dropped, not fatal, when it does not decode. A dropped entry is logged by its type only, so
/// a broken required field never empties a list without a trace.
struct Lossy<Value: Codable>: Codable {
    let value: Value?
    init(from decoder: any Decoder) throws {
        do {
            value = try Value(from: decoder)
        } catch {
            value = nil
            logDroppedEntry("\(Value.self)")
        }
    }
    func encode(to encoder: any Encoder) throws { try value?.encode(to: encoder) }
}

/// One group of the Review tab. An unknown `key` is one more group that keeps the server's `label`.
public struct ReviewGroup: Codable, Equatable, Sendable {
    public let key: String
    public let label: String
    public let rows: [ThreadRow]

    public init(key: String, label: String, rows: [ThreadRow]) {
        self.key = key
        self.label = label
        self.rows = rows
    }

    private enum CodingKeys: String, CodingKey { case key, label, rows }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        key = try container.decode(String.self, forKey: .key)
        label = (try? container.decodeIfPresent(String.self, forKey: .label)) ?? key
        rows =
            ((try? container.decodeIfPresent([Lossy<ThreadRow>].self, forKey: .rows)) ?? nil)?
            .compactMap(\.value) ?? []
    }
}

/// An open pull request of a thread in the Review tab. `mergeability` and `checks` stay the server's words, so a
/// value added later is shown or hidden by the view, never an error.
public struct ReviewPullRequest: Codable, Equatable, Sendable {
    public let number: Int
    public let url: String
    public let repository: String
    public let isDraft: Bool
    /// `conflicts`, `no_conflicts`, `unknown` or a word this build does not know.
    public let mergeability: String
    /// `passing`, `failing`, `pending`, nil or a word this build does not know.
    public let checks: String?
    public let approvedByYou: Bool

    public init(
        number: Int, url: String, repository: String, isDraft: Bool = false, mergeability: String = "unknown",
        checks: String? = nil, approvedByYou: Bool = false
    ) {
        self.number = number
        self.url = url
        self.repository = repository
        self.isDraft = isDraft
        self.mergeability = mergeability
        self.checks = checks
        self.approvedByYou = approvedByYou
    }

    private enum CodingKeys: String, CodingKey {
        case number, url, repository, isDraft, mergeability, checks, approvedByYou
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        number = try container.decode(Int.self, forKey: .number)
        url = (try? container.decodeIfPresent(String.self, forKey: .url)) ?? ""
        repository = (try? container.decodeIfPresent(String.self, forKey: .repository)) ?? ""
        isDraft = (try? container.decodeIfPresent(Bool.self, forKey: .isDraft)) ?? false
        mergeability = (try? container.decodeIfPresent(String.self, forKey: .mergeability)) ?? "unknown"
        checks = (try? container.decodeIfPresent(String.self, forKey: .checks)) ?? nil
        approvedByYou = (try? container.decodeIfPresent(Bool.self, forKey: .approvedByYou)) ?? false
    }
}

/// What the Review tab adds to a row.
public struct ThreadReview: Codable, Equatable, Sendable {
    /// In merge order.
    public let pullRequests: [ReviewPullRequest]
    public let hasNewCommitsSinceApproval: Bool
    public let otherApprovals: Int

    public init(pullRequests: [ReviewPullRequest], hasNewCommitsSinceApproval: Bool = false, otherApprovals: Int = 0) {
        self.pullRequests = pullRequests
        self.hasNewCommitsSinceApproval = hasNewCommitsSinceApproval
        self.otherApprovals = otherApprovals
    }

    private enum CodingKeys: String, CodingKey { case pullRequests, hasNewCommitsSinceApproval, otherApprovals }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        pullRequests =
            ((try? container.decodeIfPresent([Lossy<ReviewPullRequest>].self, forKey: .pullRequests))
            ?? nil)?.compactMap(\.value) ?? []
        hasNewCommitsSinceApproval =
            (try? container.decodeIfPresent(Bool.self, forKey: .hasNewCommitsSinceApproval)) ?? false
        otherApprovals = (try? container.decodeIfPresent(Int.self, forKey: .otherApprovals)) ?? 0
    }
}

public struct ThreadList: Codable, Equatable, Sendable {
    public let starred: [ThreadRow]
    public let needsYou: [NeedsYouItem]
    public let threads: [ThreadRow]
    public let needsYouCount: Int
    public let hasAnyThreads: Bool
    /// The Review tab's badge; 0 from an older server.
    public let reviewCount: Int
    /// The person's GitHub login is not linked, so the Review tab is empty.
    public let needsGitHubLink: Bool
    /// The web path where the login is linked.
    public let gitHubLinkPath: String?
    /// The Review tab's groups in the server's order; empty on every other tab and from an older server.
    public let reviewGroups: [ReviewGroup]
    /// Nil from an older server.
    public let facets: ThreadFacets?

    /// Folds the task items of `needsYou` into their threads (see `folded`), so no caller can bypass the fold.
    public init(
        starred: [ThreadRow],
        needsYou: [NeedsYouItem],
        threads: [ThreadRow],
        needsYouCount: Int,
        hasAnyThreads: Bool,
        reviewCount: Int = 0,
        needsGitHubLink: Bool = false,
        gitHubLinkPath: String? = nil,
        reviewGroups: [ReviewGroup] = [],
        facets: ThreadFacets? = nil
    ) {
        let folded = Self.folded(starred: starred, needsYou: needsYou, threads: threads)
        self.starred = folded.starred
        self.needsYou = folded.needsYou
        self.threads = folded.threads
        self.needsYouCount = needsYouCount
        self.hasAnyThreads = hasAnyThreads
        self.reviewCount = reviewCount
        self.needsGitHubLink = needsGitHubLink
        self.gitHubLinkPath = gitHubLinkPath
        self.reviewGroups = reviewGroups
        self.facets = facets
    }

    private enum CodingKeys: String, CodingKey {
        case starred, needsYou, threads, needsYouCount, hasAnyThreads
        case reviewCount, needsGitHubLink, gitHubLinkPath, reviewGroups, facets
    }

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
            hasAnyThreads: try container.decode(Bool.self, forKey: .hasAnyThreads),
            reviewCount: (try? container.decodeIfPresent(Int.self, forKey: .reviewCount)) ?? 0,
            needsGitHubLink: (try? container.decodeIfPresent(Bool.self, forKey: .needsGitHubLink)) ?? false,
            gitHubLinkPath: (try? container.decodeIfPresent(String.self, forKey: .gitHubLinkPath)) ?? nil,
            reviewGroups: ((try? container.decodeIfPresent([Lossy<ReviewGroup>].self, forKey: .reviewGroups)) ?? nil)?
                .compactMap(\.value) ?? [],
            facets: (try? container.decodeIfPresent(ThreadFacets.self, forKey: .facets)) ?? nil)
    }
}

public struct ThreadRow: Codable, Equatable, Sendable {
    public let id: String
    public let title: String
    public let repo: String
    public let status: ThreadRowStatus
    public let needsYouReason: NeedsYouReason?
    public let needsYouLabel: String?
    /// The first 200 characters of the error of a failed thread's latest task; nil on any other row and from an older
    /// server.
    public let needsYouDetail: String?
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
    /// The Review tab's pull request facts; nil on every other tab and from an older server.
    public let review: ThreadReview?
    /// The state of a sub-thread as the stack listing resolves it (`starting`, `queued`, `working`...), kept as the
    /// server's string. Nil on a root row and from a server that does not send it.
    public let stackState: String?

    public init(
        id: String,
        title: String,
        repo: String,
        status: ThreadRowStatus,
        needsYouReason: NeedsYouReason? = nil,
        needsYouLabel: String? = nil,
        needsYouDetail: String? = nil,
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
        source: ThreadSource? = nil,
        review: ThreadReview? = nil,
        stackState: String? = nil
    ) {
        self.id = id
        self.title = title
        self.repo = repo
        self.status = status
        self.needsYouReason = needsYouReason
        self.needsYouLabel = needsYouLabel
        self.needsYouDetail = needsYouDetail
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
        self.review = review
        self.stackState = stackState
    }

    private enum CodingKeys: String, CodingKey {
        case id, title, repo, status, needsYouReason, needsYouLabel, needsYouDetail, isPrivate, isShared
        case isUnattended, isStarred, canDelete, ownerInitials, lastActivityAt, group, children, step, source, review
        case stackState
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        title = try container.decode(String.self, forKey: .title)
        repo = try container.decode(String.self, forKey: .repo)
        status = try container.decode(ThreadRowStatus.self, forKey: .status)
        needsYouReason = try container.decodeIfPresent(NeedsYouReason.self, forKey: .needsYouReason)
        needsYouLabel = try container.decodeIfPresent(String.self, forKey: .needsYouLabel)
        needsYouDetail = (try? container.decodeIfPresent(String.self, forKey: .needsYouDetail)) ?? nil
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
        review = (try? container.decodeIfPresent(ThreadReview.self, forKey: .review)) ?? nil
        stackState = (try? container.decodeIfPresent(String.self, forKey: .stackState)) ?? nil
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
            needsYouDetail: asksMore ? nil : needsYouDetail,
            isPrivate: isPrivate, isShared: isShared, isUnattended: isUnattended, isStarred: isStarred,
            canDelete: canDelete, ownerInitials: ownerInitials, lastActivityAt: lastActivityAt, group: group,
            children: children.map {
                $0.idsIncludingDescendants.contains(threadID) ? $0.applying(task, to: threadID) : $0
            },
            step: step, source: source, review: review, stackState: stackState)
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

extension ThreadList {
    /// The title of a thread row anywhere in the list, the Review groups and sub-threads included.
    public func title(ofThread id: String) -> String? {
        func find(_ rows: [ThreadRow]) -> String? {
            for row in rows {
                if row.id == id { return row.title }
                if let title = find(row.children) { return title }
            }
            return nil
        }
        let needy = needsYou.compactMap { item -> ThreadRow? in
            if case .thread(let row) = item { row } else { nil }
        }
        return find(needy) ?? find(starred) ?? find(threads) ?? find(reviewGroups.flatMap(\.rows))
    }
}
