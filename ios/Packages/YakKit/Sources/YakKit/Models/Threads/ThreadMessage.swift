public struct ThreadMessage: Codable, Equatable, Sendable {
    public let id: String
    public let uuid: String?
    public let sendKey: String?
    public let text: String
    public let authorName: String?
    public let authorInitials: String
    public let model: String?
    public let state: MessageState
    public let mode: MessageMode?
    public let intent: String?
    public let sentAt: String
    public let readAfterSequence: Int?
    public let bodyHtml: String
}
