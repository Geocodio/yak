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
    /// The channel the message came from; nil for a dashboard message.
    public let via: MessageVia?
    /// A follow-up that came from a channel.
    public let isReply: Bool
    /// A retry run: `text` and `bodyHtml` are empty.
    public let isRetry: Bool
    public let retryNumber: Int?
    /// False when the server does not allow editing the text.
    public let canEditText: Bool

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
        attachments: [MessageAttachment]? = nil,
        via: MessageVia? = nil,
        isReply: Bool = false,
        isRetry: Bool = false,
        retryNumber: Int? = nil,
        canEditText: Bool = true
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
        self.via = via
        self.isReply = isReply
        self.isRetry = isRetry
        self.retryNumber = retryNumber
        self.canEditText = canEditText
    }

    private enum CodingKeys: String, CodingKey {
        case id, uuid, sendKey, text, authorName, authorInitials, model, state, mode, intent, sentAt
        case readAfterSequence, bodyHtml, attachments, via, isReply, isRetry, retryNumber, canEditText
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        uuid = try container.decodeIfPresent(String.self, forKey: .uuid)
        sendKey = try container.decodeIfPresent(String.self, forKey: .sendKey)
        text = try container.decode(String.self, forKey: .text)
        authorName = try container.decodeIfPresent(String.self, forKey: .authorName)
        authorInitials = try container.decode(String.self, forKey: .authorInitials)
        model = try container.decodeIfPresent(String.self, forKey: .model)
        state = try container.decode(MessageState.self, forKey: .state)
        mode = try container.decodeIfPresent(MessageMode.self, forKey: .mode)
        intent = try container.decodeIfPresent(String.self, forKey: .intent)
        sentAt = try container.decode(String.self, forKey: .sentAt)
        readAfterSequence = try container.decodeIfPresent(Int.self, forKey: .readAfterSequence)
        bodyHtml = try container.decode(String.self, forKey: .bodyHtml)
        attachments = try container.decodeIfPresent([MessageAttachment].self, forKey: .attachments)
        via = try container.decodeIfPresent(MessageVia.self, forKey: .via)
        isReply = try container.decodeIfPresent(Bool.self, forKey: .isReply) ?? false
        isRetry = try container.decodeIfPresent(Bool.self, forKey: .isRetry) ?? false
        retryNumber = try container.decodeIfPresent(Int.self, forKey: .retryNumber)
        canEditText = try container.decodeIfPresent(Bool.self, forKey: .canEditText) ?? true
    }
}
