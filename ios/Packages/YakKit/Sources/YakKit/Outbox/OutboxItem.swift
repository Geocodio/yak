import Foundation

/// Where an outbox item goes: a message to an existing thread, or the first message of a new one.
public enum OutboxTarget: Codable, Equatable, Hashable, Sendable {
    case thread(String)
    case newThread(NewThreadDraft)
}

/// A file attached to an unsent message. `path` is relative to the app group.
public struct OutboxAttachment: Codable, Equatable, Sendable {
    public let reference: String
    public let fileName: String
    public let contentType: String
    public let path: String

    public init(reference: String, fileName: String, contentType: String, path: String) {
        self.reference = reference
        self.fileName = fileName
        self.contentType = contentType
        self.path = path
    }
}

/// Why the server turned an item down: its code and message, kept for the screen.
public struct OutboxRefusal: Codable, Equatable, Sendable {
    public let code: String
    public let message: String

    public init(code: String, message: String) {
        self.code = code
        self.message = message
    }
}

public enum OutboxState: Codable, Equatable, Sendable {
    /// Ready to send; waits for the head of its queue.
    case queued
    case sending
    /// Still sending after `OutboxRetryPolicy.slowAfter`.
    case slow
    /// The last attempt failed in a way worth repeating; the next one is scheduled.
    case waiting
    case refused(OutboxRefusal)
}

public struct OutboxItem: Codable, Equatable, Identifiable, Sendable {
    /// The `send_key`: the same on every attempt, so the server takes the message once.
    public let id: UUID
    public var target: OutboxTarget
    /// The message, or the new thread's description.
    public var text: String
    public var model: String?
    public var delivery: MessageDelivery
    public var attachments: [OutboxAttachment]
    public var state: OutboxState
    public var attemptCount: Int
    /// True once a request for this item has been started, so the server may hold its `send_key`. A failure
    /// before that (an unreadable attachment, a cancel before the request) leaves it false and the item editable.
    public var mayHaveReachedServer: Bool
    public let createdAt: Date

    public init(
        id: UUID, target: OutboxTarget, text: String, model: String?, delivery: MessageDelivery,
        attachments: [OutboxAttachment], state: OutboxState = .queued, attemptCount: Int = 0,
        mayHaveReachedServer: Bool = false, createdAt: Date
    ) {
        self.id = id
        self.target = target
        self.text = text
        self.model = model
        self.delivery = delivery
        self.attachments = attachments
        self.state = state
        self.attemptCount = attemptCount
        self.mayHaveReachedServer = mayHaveReachedServer
        self.createdAt = createdAt
    }

    /// A list saved before `mayHaveReachedServer` existed treats every attempted item as possibly sent.
    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(UUID.self, forKey: .id)
        target = try container.decode(OutboxTarget.self, forKey: .target)
        text = try container.decode(String.self, forKey: .text)
        model = try container.decodeIfPresent(String.self, forKey: .model)
        delivery = try container.decode(MessageDelivery.self, forKey: .delivery)
        attachments = try container.decode([OutboxAttachment].self, forKey: .attachments)
        state = try container.decode(OutboxState.self, forKey: .state)
        attemptCount = try container.decode(Int.self, forKey: .attemptCount)
        mayHaveReachedServer =
            try container.decodeIfPresent(Bool.self, forKey: .mayHaveReachedServer) ?? (attemptCount > 0)
        createdAt = try container.decode(Date.self, forKey: .createdAt)
    }

    /// Items of one queue are sent one at a time, in order: per thread, and one queue per new thread.
    public var queueKey: String {
        switch target {
        case .thread(let threadID): "thread:\(threadID)"
        case .newThread: "new:\(id.uuidString)"
        }
    }

    /// True when the text may change without the server holding the old text under this key: an item that
    /// was ever sent may already be there.
    public var isEditable: Bool {
        switch state {
        case .refused: true
        case .queued, .waiting: !mayHaveReachedServer
        case .sending, .slow: false
        }
    }
}

/// What the composer hands over to be sent.
public struct OutboxDraft: Equatable, Sendable {
    public var target: OutboxTarget
    public var text: String
    public var model: String?
    public var delivery: MessageDelivery
    public var attachments: [OutboxAttachment]

    public init(
        target: OutboxTarget, text: String, model: String? = nil, delivery: MessageDelivery = .steer,
        attachments: [OutboxAttachment] = []
    ) {
        self.target = target
        self.text = text
        self.model = model
        self.delivery = delivery
        self.attachments = attachments
    }
}

/// One item the server took. `messageUUID` is nil for a `/clear` and for a new thread.
public struct OutboxDelivery: Equatable, Sendable {
    public let sendKey: UUID
    public let target: OutboxTarget
    public let status: SendStatus
    public let messageUUID: String?
    public let threadID: String
    public let text: String
    public let sentAt: Date

    public init(
        sendKey: UUID, target: OutboxTarget, status: SendStatus, messageUUID: String?, threadID: String,
        text: String, sentAt: Date
    ) {
        self.sendKey = sendKey
        self.target = target
        self.status = status
        self.messageUUID = messageUUID
        self.threadID = threadID
        self.text = text
        self.sentAt = sentAt
    }
}

/// Why every queue stands still. The items are kept.
public enum OutboxPause: Equatable, Sendable {
    case unauthorized(code: String)
    case serverTooOld
    case appTooOld(minimum: Int)
}

public enum OutboxEditProblem: Error, Equatable, Sendable {
    case notFound
    case alreadySending
    /// The server may hold this `send_key` with the old text already.
    case mayAlreadyBeSent
}
