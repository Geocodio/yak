public struct ThreadCreated: Codable, Equatable, Sendable {
    public let id: String
}

/// The answer to sending a message. `uuid` is null for `/clear`, which has no message row.
public struct MessageSendResult: Codable, Equatable, Sendable {
    public let status: SendStatus
    public let uuid: String?
    public let threadId: String?
}

public struct StatusResponse: Codable, Equatable, Sendable {
    public let status: String
}
