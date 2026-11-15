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
    public let attachments: [MessageAttachment]?

    public init(
        id: String,
        uuid: String? = nil,
        sendKey: String? = nil,
        text: String,
        authorName: String? = nil,
        authorInitials: String,
        model: String? = nil,
        state: MessageState,
        mode: MessageMode? = nil,
        intent: String? = nil,
        sentAt: String,
        readAfterSequence: Int? = nil,
        bodyHtml: String,
        attachments: [MessageAttachment]? = nil
    ) {
        self.id = id
        self.uuid = uuid
        self.sendKey = sendKey
        self.text = text
        self.authorName = authorName
        self.authorInitials = authorInitials
        self.model = model
        self.state = state
        self.mode = mode
        self.intent = intent
        self.sentAt = sentAt
        self.readAfterSequence = readAfterSequence
        self.bodyHtml = bodyHtml
        self.attachments = attachments
    }
}
