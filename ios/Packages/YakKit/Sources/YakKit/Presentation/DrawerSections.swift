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
    public enum Kind: Equatable, Sendable { case notSentYet, needsYou, starred, today, thisWeek, older }
    public let kind: Kind
    public let rows: [DrawerRow]
    public var id: Kind { kind }
}

public enum DrawerContent: Equatable, Sendable {
    case sections([DrawerSection])
    case empty(othersHaveThreads: Bool)
    case noResults(search: String, canWiden: Bool)
}

public enum DrawerSections {
    public static func make(
        list: ThreadList, filter: ThreadListFilter, search: String?, expanded: [String: Bool],
        unsentNewThreads: [OutboxItem]
    ) -> DrawerContent {
        func threadRows(_ rows: [ThreadRow]) -> [DrawerRow] {
            rows.flatMap { flatten($0, depth: 0, expanded: expanded) }
        }

        let unsent =
            unsentNewThreads
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
        let sections = [
            DrawerSection(kind: .notSentYet, rows: unsent),
            DrawerSection(kind: .needsYou, rows: needsYou),
            DrawerSection(kind: .starred, rows: threadRows(list.starred)),
            DrawerSection(kind: .today, rows: byKind[.today] ?? []),
            DrawerSection(kind: .thisWeek, rows: byKind[.thisWeek] ?? []),
            DrawerSection(kind: .older, rows: byKind[.older] ?? []),
        ].filter { !$0.rows.isEmpty }

        if !sections.isEmpty { return .sections(sections) }
        let trimmed = search?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if !trimmed.isEmpty { return .noResults(search: trimmed, canWiden: filter != .everyone) }
        return .empty(othersHaveThreads: filter != .everyone && list.hasAnyThreads)
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
            childCount: row.children.count, isExpanded: isExpanded)
        guard isExpanded else { return [line] }
        return [line] + row.children.flatMap { flatten($0, depth: depth + 1, expanded: expanded) }
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
