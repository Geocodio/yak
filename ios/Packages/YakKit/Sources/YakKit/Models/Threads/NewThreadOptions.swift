public struct NewThreadOptions: Codable, Equatable, Sendable {
    public let repos: [NewThreadRepository]
    public let picker: AccountPicker
    public let defaultRepo: String?
    public let models: [ModelFamily]
}

public struct NewThreadRepository: Codable, Equatable, Sendable {
    public let slug: String
    public let name: String
    public let setupStatus: String
    public let setupStep: String?
}

public struct AccountPicker: Codable, Equatable, Sendable {
    public let accounts: [ClaudeAccount]
    public let defaultsByRepo: [String: RepositoryDefaults]
}

public struct ClaudeAccount: Codable, Equatable, Sendable {
    public let id: LenientID
    public let name: String
    public let kind: String
    public let isDefault: Bool
    public let defaultModel: String?
    public let usable: Bool
    public let status: String
}

public struct RepositoryDefaults: Codable, Equatable, Sendable {
    public let accountId: LenientID?
    public let model: String?
}
