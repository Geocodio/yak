import Foundation

/// The answer of `DELETE threads/{id}`: the thread's id and the word `deleted`.
public struct ThreadDeleted: Codable, Equatable, Sendable {
    public let id: String
    public let status: String

    public init(id: String, status: String = "deleted") {
        self.id = id
        self.status = status
    }
}

private struct StarBody: Encodable, Sendable { let starred: Bool }

/// Close and delete wait for sandboxes to be destroyed, which takes longer than the usual 15 seconds.
private let lifecycleTimeout = Duration.seconds(60)

extension Endpoint where Response == ThreadRow {
    /// Sets the star, so a repeat changes nothing. Answers the thread's list row.
    public static func starThread(_ id: String, starred: Bool) -> Endpoint<ThreadRow> {
        Endpoint(method: .post, path: "threads/\(segment(id))/star", body: StarBody(starred: starred))
    }

    /// Closes the thread for good: a closed thread never reopens. Answers the thread's list row, status `closed`.
    public static func closeThread(_ id: String) -> Endpoint<ThreadRow> {
        Endpoint(method: .post, path: "threads/\(segment(id))/close", timeout: lifecycleTimeout)
    }
}

extension Endpoint where Response == ThreadDeleted {
    /// Removes the thread, its sub-threads, sandboxes, runs and attachments. The server asks nothing more.
    public static func deleteThread(_ id: String) -> Endpoint<ThreadDeleted> {
        Endpoint(method: .delete, path: "threads/\(segment(id))", timeout: lifecycleTimeout)
    }
}

/// A change to the thread list that the person's own action already knows, applied to a list without asking the
/// server again.
public enum ThreadListEdit: Equatable, Sendable {
    case setStar(id: String, isStarred: Bool)
    /// The server's row for a thread, which replaces the row the list holds.
    case replace(ThreadRow)
    /// The thread, and every sub-thread below it, is gone.
    case remove(id: String)
}

extension ThreadRow {
    /// Every sub-thread below this row, at any depth.
    public var subThreadCount: Int { children.reduce(0) { $0 + 1 + $1.subThreadCount } }

    func with(isStarred: Bool? = nil, children: [ThreadRow]? = nil, review: ThreadReview?? = nil) -> ThreadRow {
        ThreadRow(
            id: id, title: title, repo: repo, status: status, needsYouReason: needsYouReason,
            needsYouLabel: needsYouLabel, needsYouDetail: needsYouDetail, isPrivate: isPrivate, isShared: isShared,
            isUnattended: isUnattended, isStarred: isStarred ?? self.isStarred, canDelete: canDelete,
            ownerInitials: ownerInitials, lastActivityAt: lastActivityAt, group: group,
            children: children ?? self.children, step: step, source: source, review: review ?? self.review)
    }

    /// This row with the server's answer for the same thread: the answer's facts, the Review facts and the
    /// sub-threads this list already knew when the answer names none.
    func replaced(by answer: ThreadRow) -> ThreadRow {
        answer.with(
            children: answer.children.isEmpty ? children : answer.children, review: .some(answer.review ?? review))
    }
}

extension ThreadList {
    /// The list after `edit`. A row is found at any depth; the order of the other rows does not change.
    public func applying(_ edit: ThreadListEdit) -> ThreadList {
        switch edit {
        case .setStar(let id, let isStarred): return starring(id, isStarred)
        case .replace(let row): return mapping(row.id) { $0.replaced(by: row) }
        case .remove(let id): return removing(id)
        }
    }

    private func rebuilt(
        starred: [ThreadRow], needsYou: [NeedsYouItem], threads: [ThreadRow], needsYouCount: Int? = nil,
        reviewCount: Int? = nil, reviewGroups: [ReviewGroup]? = nil
    ) -> ThreadList {
        ThreadList(
            starred: starred, needsYou: needsYou, threads: threads, needsYouCount: needsYouCount ?? self.needsYouCount,
            hasAnyThreads: hasAnyThreads, reviewCount: reviewCount ?? self.reviewCount,
            needsGitHubLink: needsGitHubLink, gitHubLinkPath: gitHubLinkPath,
            reviewGroups: reviewGroups ?? self.reviewGroups, facets: facets)
    }

    /// Changes the row `id` wherever it is, a sub-thread included.
    private func mapping(_ id: String, _ change: (ThreadRow) -> ThreadRow) -> ThreadList {
        func apply(_ rows: [ThreadRow]) -> [ThreadRow] {
            rows.map { row in
                if row.id == id { return change(row) }
                return row.children.isEmpty ? row : row.with(children: apply(row.children))
            }
        }
        let items = needsYou.map { item -> NeedsYouItem in
            if case .thread(let row) = item { return .thread(apply([row])[0]) }
            return item
        }
        let groups = reviewGroups.map { ReviewGroup(key: $0.key, label: $0.label, rows: apply($0.rows)) }
        return rebuilt(starred: apply(starred), needsYou: items, threads: apply(threads), reviewGroups: groups)
    }

    /// A starred thread leaves its day group for the top of Starred, and goes back to the day group of its last
    /// activity when the star is removed. A thread that waits for the person stays under Needs you.
    private func starring(_ id: String, _ isStarred: Bool) -> ThreadList {
        let flagged = mapping(id) { $0.with(isStarred: isStarred) }
        if isStarred, let place = flagged.threads.firstIndex(where: { $0.id == id }) {
            var threads = flagged.threads
            let row = threads.remove(at: place)
            return flagged.rebuilt(starred: [row] + flagged.starred, needsYou: flagged.needsYou, threads: threads)
        }
        if !isStarred, let place = flagged.starred.firstIndex(where: { $0.id == id }) {
            var starred = flagged.starred
            let row = starred.remove(at: place)
            var threads = flagged.threads
            let activity = RelativeAge.parse(row.lastActivityAt) ?? .distantPast
            let position = threads.firstIndex { (RelativeAge.parse($0.lastActivityAt) ?? .distantPast) < activity }
            threads.insert(row, at: position ?? threads.count)
            return flagged.rebuilt(starred: starred, needsYou: flagged.needsYou, threads: threads)
        }
        return flagged
    }

    private func removing(_ id: String) -> ThreadList {
        func without(_ rows: [ThreadRow]) -> [ThreadRow] {
            rows.filter { $0.id != id }.map { $0.children.isEmpty ? $0 : $0.with(children: without($0.children)) }
        }
        var removedNeedy = 0
        var items: [NeedsYouItem] = []
        for item in needsYou {
            guard case .thread(let row) = item else {
                items.append(item)
                continue
            }
            if row.id == id {
                removedNeedy += 1
            } else {
                items.append(.thread(row.with(children: without(row.children))))
            }
        }
        var removedReview = 0
        let groups = reviewGroups.map { group -> ReviewGroup in
            let rows = without(group.rows)
            removedReview += group.rows.count - rows.count
            return ReviewGroup(key: group.key, label: group.label, rows: rows)
        }
        return rebuilt(
            starred: without(starred), needsYou: items, threads: without(threads),
            needsYouCount: max(needsYouCount - removedNeedy, 0), reviewCount: max(reviewCount - removedReview, 0),
            reviewGroups: groups)
    }

    /// The row of a thread anywhere in the list, the Review groups and sub-threads included.
    public func row(ofThread id: String) -> ThreadRow? {
        func find(_ rows: [ThreadRow]) -> ThreadRow? {
            for row in rows {
                if row.id == id { return row }
                if let child = find(row.children) { return child }
            }
            return nil
        }
        let needy = needsYou.compactMap { item -> ThreadRow? in
            if case .thread(let row) = item { row } else { nil }
        }
        return find(needy) ?? find(starred) ?? find(threads) ?? find(reviewGroups.flatMap(\.rows))
    }
}
