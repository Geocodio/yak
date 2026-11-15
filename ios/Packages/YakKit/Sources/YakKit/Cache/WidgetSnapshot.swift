import Foundation

public struct WidgetItem: Codable, Equatable, Sendable {
    public enum Kind: String, Codable, Sendable { case thread, task }
    public let kind: Kind
    public let id: String
    /// Nil for a private thread; the widget shows "A private thread".
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
}
