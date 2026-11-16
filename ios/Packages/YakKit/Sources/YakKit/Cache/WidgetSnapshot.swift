import Foundation

public struct WidgetItem: Codable, Equatable, Sendable {
    public enum Kind: String, Codable, Sendable { case thread, task }
    public let kind: Kind
    public let id: String
    /// Nil for a private thread or a task of one; the widget shows "A private thread".
    public let title: String?
    public let isPrivate: Bool
    /// The server's needs-you label, or a non-private running thread's step.
    public let detail: String?
    public let lastActivityAt: Date?
    /// The row's `needsYouReason` raw value, at most 32 characters; nil for running items.
    public let needsYouReason: String?

    static let reasonLimit = 32

    public init(
        kind: Kind, id: String, title: String?, isPrivate: Bool,
        detail: String? = nil, lastActivityAt: Date? = nil, needsYouReason: String? = nil
    ) {
        self.kind = kind
        self.id = id
        self.title = title
        self.isPrivate = isPrivate
        self.detail = detail
        self.lastActivityAt = lastActivityAt
        self.needsYouReason = needsYouReason.map { String($0.prefix(Self.reasonLimit)) }
    }

    private enum CodingKeys: String, CodingKey {
        case kind, id, title, isPrivate, detail, lastActivityAt, needsYouReason
    }

    /// A snapshot written before the detail fields existed still decodes; a detail field of the wrong type is nil.
    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        self.init(
            kind: try container.decode(Kind.self, forKey: .kind),
            id: try container.decode(String.self, forKey: .id),
            title: try container.decodeIfPresent(String.self, forKey: .title),
            isPrivate: try container.decode(Bool.self, forKey: .isPrivate),
            detail: try? container.decodeIfPresent(String.self, forKey: .detail),
            lastActivityAt: try? container.decodeIfPresent(Date.self, forKey: .lastActivityAt),
            needsYouReason: try? container.decodeIfPresent(String.self, forKey: .needsYouReason))
    }
}

public struct WidgetSnapshot: Codable, Equatable, Sendable {
    public let instanceName: String
    public let needsYouCount: Int
    /// At most 8.
    public let needsYou: [WidgetItem]
    /// Threads with status running, at most 8.
    public let running: [WidgetItem]
    public let updatedAt: Date

    public init(
        instanceName: String, needsYouCount: Int, needsYou: [WidgetItem], running: [WidgetItem], updatedAt: Date
    ) {
        self.instanceName = instanceName
        self.needsYouCount = needsYouCount
        self.needsYou = needsYou
        self.running = running
        self.updatedAt = updatedAt
    }

    /// Most items a widget family can show.
    static let itemLimit = 8
    /// Sub-thread levels below a root that the walk reads.
    static let depthLimit = 2
    /// Rows the walk reads in one list.
    static let rowLimit = 500

    /// A private thread's or task's title is dropped here, so no later step can leak it. Tasks and threads of
    /// unknown kinds that the app cannot show are skipped. Every text and count is bounded.
    public static func make(from list: ThreadList, instanceName: String, now: Date) -> WidgetSnapshot {
        var rowsRead = 0
        return make(from: list, instanceName: instanceName, now: now, rowsRead: &rowsRead)
    }

    static func make(from list: ThreadList, instanceName: String, now: Date, rowsRead: inout Int) -> WidgetSnapshot {
        func title(_ text: String, isPrivate: Bool) -> String? {
            isPrivate ? nil : WidgetText.bounded(text, limit: WidgetText.titleLimit)
        }
        func detail(_ text: String?) -> String? {
            text.map { WidgetText.bounded($0, limit: WidgetText.detailLimit) }.flatMap { $0.isEmpty ? nil : $0 }
        }
        func needsYouItem(_ row: ThreadRow) -> WidgetItem {
            WidgetItem(
                kind: .thread, id: row.id, title: title(row.title, isPrivate: row.isPrivate),
                isPrivate: row.isPrivate, detail: detail(row.needsYouLabel),
                lastActivityAt: date(row.lastActivityAt), needsYouReason: row.needsYouReason?.rawValue)
        }
        func runningItem(_ row: ThreadRow) -> WidgetItem {
            WidgetItem(
                kind: .thread, id: row.id, title: title(row.title, isPrivate: row.isPrivate),
                isPrivate: row.isPrivate, detail: row.isPrivate ? nil : detail(row.step),
                lastActivityAt: date(row.lastActivityAt))
        }
        let needsYou: [WidgetItem] = list.needsYou.prefix(itemLimit * 4).compactMap { entry in
            switch entry {
            case .thread(let row): needsYouItem(row)
            case .task(let task):
                WidgetItem(
                    kind: .task, id: task.taskId.value, title: title(task.title, isPrivate: task.isPrivate),
                    isPrivate: task.isPrivate, detail: detail(task.needsYouLabel),
                    lastActivityAt: date(task.lastActivityAt), needsYouReason: task.needsYouReason.rawValue)
            case .unknown: nil
            }
        }
        var seen: Set<String> = []
        var running: [WidgetItem] = []
        var pending: [(row: ThreadRow, depth: Int)] = (list.starred + list.threads).reversed().map { ($0, 0) }
        while let (row, depth) = pending.popLast(), rowsRead < rowLimit, running.count < itemLimit {
            rowsRead += 1
            if row.status == .running, seen.insert(row.id).inserted { running.append(runningItem(row)) }
            if depth < depthLimit { pending.append(contentsOf: row.children.reversed().map { ($0, depth + 1) }) }
        }
        return WidgetSnapshot(
            instanceName: WidgetText.bounded(instanceName, limit: WidgetText.instanceNameLimit),
            needsYouCount: WidgetText.clamped(list.needsYouCount),
            needsYou: Array(needsYou.prefix(itemLimit)), running: running, updatedAt: now)
    }

    private static func date(_ text: String?) -> Date? {
        guard let text else { return nil }
        return (try? Date(text, strategy: .iso8601))
            ?? (try? Date(text, strategy: Date.ISO8601FormatStyle(includingFractionalSeconds: true)))
    }
}
