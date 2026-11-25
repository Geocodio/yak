import Foundation

/// One line of the threads drawer: a thread, a task that needs the person, or a new thread not sent yet.
public struct DrawerRow: Equatable, Sendable, Identifiable {
    public enum Target: Equatable, Sendable {
        case thread(String)
        case task(String)
        case unsentThread(UUID)
    }
    public enum Indicator: Equatable, Sendable {
        case needsYou, unseen, running, blocked, paused, stopped, closed, waiting, notSent, other
    }

    /// `thread:<id>`, `task:<id>` or `outbox:<uuid lowercase>`.
    public let id: String
    public let target: Target
    public let title: String
    public let subtitle: DrawerSubtitle
    public let indicator: Indicator
    public let isPrivate: Bool
    public let lastActivity: Date?
    /// 0 for a root row, 1 for its child, and so on.
    public let depth: Int
    /// Direct sub-threads.
    public let childCount: Int
    /// Meaningful when `childCount > 0`.
    public let isExpanded: Bool
    /// The channel of a root thread, shown as a badge; nil for a sub-thread, a task and a row without one.
    public let source: String?
    /// The repository of a root thread, nil when the list row has none.
    public let repository: String?
    /// The pull request line of a Review row; nil on every other row.
    public let reviewLine: ReviewLine?
    /// The error of a failed thread for the row's two lines: every run of whitespace is one space. Nil unless the row
    /// is a failure (the blocked reason with the "Failed" label) and the server sent some text.
    public let failureDetail: String?
    /// The same error as the server sent it, line breaks kept, for the long-press preview.
    public let failureFullText: String?

    /// The repository the second line shows in the mono face in place of the status word. Only a status whose mark
    /// differs without color (stopped, closed, paused, waiting for a repository) gives way; running, blocked, unseen
    /// and any unknown status keep their words, so color is never the only signal.
    public var repositoryDetail: String? {
        guard case .status = subtitle else { return nil }
        switch indicator {
        case .stopped, .closed, .paused, .waiting: return repository
        default: return nil
        }
    }

    init(
        id: String, target: Target, title: String, subtitle: DrawerSubtitle, indicator: Indicator, isPrivate: Bool,
        lastActivity: Date?, depth: Int, childCount: Int, isExpanded: Bool, source: String? = nil,
        repository: String? = nil, reviewLine: ReviewLine? = nil, failureDetail: String? = nil,
        failureFullText: String? = nil
    ) {
        self.id = id
        self.target = target
        self.title = title
        self.subtitle = subtitle
        self.indicator = indicator
        self.isPrivate = isPrivate
        self.lastActivity = lastActivity
        self.depth = depth
        self.childCount = childCount
        self.isExpanded = isExpanded
        self.source = source
        self.repository = repository
        self.reviewLine = reviewLine
        self.failureDetail = failureDetail
        self.failureFullText = failureFullText
    }
}

public enum DrawerSubtitle: Equatable, Sendable {
    /// The server's `needsYouLabel`, shown as given.
    case serverLabel(String)
    case status(ThreadRowStatus)
    /// All descendants, and how many of them are running.
    case children(total: Int, working: Int)
    /// What a sub-thread is doing, as the server words it.
    case step(String)
    /// A task item without a label.
    case taskWaiting
    case unsent(OutboxState)
}

public struct DrawerSection: Equatable, Sendable, Identifiable {
    public enum Kind: Equatable, Hashable, Sendable {
        case notSentYet, needsYou, starred, today, thisWeek, older
        /// A group of the Review tab; the heading is the server's own `label`.
        case review(key: String, label: String)
    }
    public let kind: Kind
    /// Empty while the section is collapsed.
    public let rows: [DrawerRow]
    /// How many threads the section holds, also while it is collapsed.
    public let count: Int
    /// True for a section whose heading folds it open and closed (the Review tab's "Approved by you").
    public let isCollapsible: Bool
    public let isCollapsed: Bool
    public var id: Kind { kind }

    init(kind: Kind, rows: [DrawerRow], count: Int? = nil, isCollapsible: Bool = false, isCollapsed: Bool = false) {
        self.kind = kind
        self.rows = rows
        self.count = count ?? rows.count
        self.isCollapsible = isCollapsible
        self.isCollapsed = isCollapsed
    }
}

public enum DrawerContent: Equatable, Sendable {
    case sections([DrawerSection])
    case empty(othersHaveThreads: Bool)
    case noResults(search: String, canWiden: Bool)
}

public enum DrawerSections {
    /// The Review group whose heading folds.
    public static let approvedGroupKey = "approved"

    public static func make(
        list: ThreadList, filter: ThreadListFilter, search: String?, expanded: [String: Bool],
        unsentNewThreads: [OutboxItem], openedGroups: Set<String> = []
    ) -> DrawerContent {
        func threadRows(_ rows: [ThreadRow]) -> [DrawerRow] {
            rows.flatMap { flatten($0, depth: 0, expanded: expanded) }
        }

        // A closed or review view is not where a new thread would land.
        let unsent =
            (filter == .closed || filter == .review ? [] : unsentNewThreads)
            .filter { if case .newThread = $0.target { true } else { false } }
            .sorted { $0.createdAt < $1.createdAt }
            .map(unsentRow)
        let needsYou = list.needsYou.flatMap { item -> [DrawerRow] in
            switch item {
            case .thread(let row): flatten(row, depth: 0, expanded: expanded)
            case .task(let task): [taskRow(task)]
            case .unknown: []
            }
        }
        var byKind: [DrawerSection.Kind: [DrawerRow]] = [:]
        for row in list.threads {
            let kind: DrawerSection.Kind =
                switch row.group {
                case .today: .today
                case .thisWeek: .thisWeek
                default: .older
                }
            byKind[kind, default: []] += flatten(row, depth: 0, expanded: expanded)
        }
        let groups = list.reviewGroups.map { group -> DrawerSection in
            let rows = threadRows(group.rows)
            // "Approved by you" starts folded: the person has nothing left to do there.
            let isCollapsible = group.key == Self.approvedGroupKey && !rows.isEmpty
            let isCollapsed = isCollapsible && !openedGroups.contains(group.key)
            return DrawerSection(
                kind: .review(key: group.key, label: group.label), rows: isCollapsed ? [] : rows,
                count: group.rows.count, isCollapsible: isCollapsible, isCollapsed: isCollapsed)
        }
        let sections =
            (groups + [
                DrawerSection(kind: .notSentYet, rows: unsent),
                DrawerSection(kind: .needsYou, rows: needsYou),
                DrawerSection(kind: .starred, rows: threadRows(list.starred)),
                DrawerSection(kind: .today, rows: byKind[.today] ?? []),
                DrawerSection(kind: .thisWeek, rows: byKind[.thisWeek] ?? []),
                DrawerSection(kind: .older, rows: byKind[.older] ?? []),
            ]).filter { $0.count > 0 }

        if !sections.isEmpty { return .sections(sections) }
        let trimmed = search?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if !trimmed.isEmpty { return .noResults(search: trimmed, canWiden: filter == .mine || filter == .yak) }
        return .empty(othersHaveThreads: (filter == .mine || filter == .yak) && list.hasAnyThreads)
    }

    /// True when any descendant needs the person or is running.
    public static func isExpandedByDefault(_ row: ThreadRow) -> Bool {
        row.children.contains { $0.needsYouReason != nil || $0.status == .running || isExpandedByDefault($0) }
    }

    private static func flatten(_ row: ThreadRow, depth: Int, expanded: [String: Bool]) -> [DrawerRow] {
        let id = "thread:\(row.id)"
        let isExpanded = expanded[id] ?? isExpandedByDefault(row)
        let descendants = allDescendants(of: row)
        let subtitle: DrawerSubtitle =
            if let label = row.needsYouLabel {
                .serverLabel(label)
            } else if depth > 0, let step = row.step, !step.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                .step(step)
            } else if !row.children.isEmpty {
                .children(total: descendants.count, working: descendants.filter { $0.status == .running }.count)
            } else {
                .status(row.status)
            }
        let line = DrawerRow(
            id: id, target: .thread(row.id), title: row.title, subtitle: subtitle, indicator: indicator(of: row),
            isPrivate: row.isPrivate, lastActivity: RelativeAge.parse(row.lastActivityAt), depth: depth,
            childCount: row.children.count, isExpanded: isExpanded,
            source: depth == 0 ? row.source?.displayName : nil,
            repository: depth == 0 && !row.repo.isEmpty ? row.repo : nil,
            reviewLine: depth == 0 ? ReviewLine.make(row.review) : nil,
            failureDetail: failureText(of: row)?.collapsed, failureFullText: failureText(of: row)?.full)
        guard isExpanded else { return [line] }
        return [line] + row.children.flatMap { flatten($0, depth: depth + 1, expanded: expanded) }
    }

    /// The error text of a failed row: the original, and the same with every run of whitespace as one space.
    private static func failureText(of row: ThreadRow) -> (full: String, collapsed: String)? {
        guard row.needsYouReason == .blocked, row.needsYouLabel == "Failed" else { return nil }
        let full = row.needsYouDetail?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        guard !full.isEmpty else { return nil }
        return (full, full.split(whereSeparator: \.isWhitespace).joined(separator: " "))
    }

    private static func allDescendants(of row: ThreadRow) -> [ThreadRow] {
        row.children.flatMap { [$0] + allDescendants(of: $0) }
    }

    private static func indicator(of row: ThreadRow) -> DrawerRow.Indicator {
        switch row.needsYouReason {
        case .question, .coOwner: return .needsYou
        case .blocked: return .blocked
        case .unseen: return .unseen
        default: break
        }
        switch row.status {
        case .running: return .running
        case .blocked: return .blocked
        case .paused: return .paused
        case .stopped: return .stopped
        case .closed: return .closed
        case .waitingForRepository: return .waiting
        default: return .other
        }
    }

    private static func taskRow(_ task: TaskNeedsYou) -> DrawerRow {
        DrawerRow(
            id: "task:\(task.taskId.value)", target: .task(task.taskId.value), title: task.title,
            subtitle: task.needsYouLabel.map { .serverLabel($0) } ?? .taskWaiting, indicator: .needsYou,
            isPrivate: task.isPrivate, lastActivity: RelativeAge.parse(task.lastActivityAt), depth: 0,
            childCount: 0, isExpanded: false)
    }

    private static func unsentRow(_ item: OutboxItem) -> DrawerRow {
        let firstLine = item.text.split(whereSeparator: \.isNewline).first.map(String.init) ?? ""
        return DrawerRow(
            id: "outbox:\(item.id.uuidString.lowercased())", target: .unsentThread(item.id),
            title: String(firstLine.prefix(80)), subtitle: .unsent(item.state), indicator: .notSent,
            isPrivate: false, lastActivity: item.createdAt, depth: 0, childCount: 0, isExpanded: false)
    }
}

/// How long ago something happened, as a value the view turns into a localized phrase.
public enum RelativeAge {
    public enum Value: Equatable, Sendable {
        case now
        case minutes(Int)
        case hours(Int)
        case days(Int)
        case date(Date)
    }

    public static func value(from date: Date, now: Date) -> Value {
        let seconds = Int(now.timeIntervalSince(date))
        switch seconds {
        case ..<60: return .now
        case ..<3600: return .minutes(seconds / 60)
        case ..<86400: return .hours(seconds / 3600)
        case ..<(7 * 86400): return .days(seconds / 86400)
        default: return .date(date)
        }
    }

    private static let plain = Date.ISO8601FormatStyle(timeZoneSeparator: .colon, timeZone: .gmt)
    private static let fractional = Date.ISO8601FormatStyle(
        timeZoneSeparator: .colon, includingFractionalSeconds: true, timeZone: .gmt)

    /// ISO 8601, with or without fractional seconds.
    public static func parse(_ text: String?) -> Date? {
        guard let text else { return nil }
        return (try? plain.parse(text)) ?? (try? fractional.parse(text))
    }
}
