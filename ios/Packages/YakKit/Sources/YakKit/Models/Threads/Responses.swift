public struct ThreadCreated: Codable, Equatable, Sendable {
    public let id: String

    public init(id: String) {
        self.id = id
    }
}

/// The answer to sending a message. `uuid` is null for `/clear`, which has no message row.
public struct MessageSendResult: Codable, Equatable, Sendable {
    public let status: SendStatus
    public let uuid: String?
    public let threadId: String?

    public init(status: SendStatus, uuid: String? = nil, threadId: String? = nil) {
        self.status = status
        self.uuid = uuid
        self.threadId = threadId
    }
}

public struct StatusResponse: Codable, Equatable, Sendable {
    public let status: String

    public init(status: String) {
        self.status = status
    }
}
