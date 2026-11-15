import Foundation

public struct WidgetItem: Codable, Equatable, Sendable {
    public enum Kind: String, Codable, Sendable { case thread, task }
    public let kind: Kind
    public let id: String
    /// Nil for a private thread or a task of one; the widget shows "A private thread".
    public let title: String?
    public let isPrivate: Bool

    public init(kind: Kind, id: String, title: String?, isPrivate: Bool) {
        self.kind = kind
        self.id = id
        self.title = title
        self.isPrivate = isPrivate
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

    /// A private thread's or task's title is dropped here, so no later step can leak it. Tasks and threads of
    /// unknown kinds that the app cannot show are skipped.
    public static func make(from list: ThreadList, instanceName: String, now: Date) -> WidgetSnapshot {
        func item(_ row: ThreadRow) -> WidgetItem {
            WidgetItem(kind: .thread, id: row.id, title: row.isPrivate ? nil : row.title, isPrivate: row.isPrivate)
        }
        let needsYou: [WidgetItem] = list.needsYou.compactMap { entry in
            switch entry {
            case .thread(let row): item(row)
            case .task(let task):
                WidgetItem(
                    kind: .task, id: task.taskId.value, title: task.isPrivate ? nil : task.title,
                    isPrivate: task.isPrivate)
            case .unknown: nil
            }
        }
        func flattened(_ rows: [ThreadRow]) -> [ThreadRow] {
            rows.flatMap { [$0] + flattened($0.children) }
        }
        var seen: Set<String> = []
        let running = flattened(list.starred + list.threads)
            .filter { $0.status == .running && seen.insert($0.id).inserted }
            .prefix(itemLimit).map(item)
        return WidgetSnapshot(
            instanceName: instanceName, needsYouCount: list.needsYouCount,
            needsYou: Array(needsYou.prefix(itemLimit)), running: Array(running), updatedAt: now)
    }
}
