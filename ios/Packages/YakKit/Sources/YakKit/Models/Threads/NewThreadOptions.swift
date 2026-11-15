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

    private enum CodingKeys: String, CodingKey { case accounts, defaultsByRepo }

    /// PHP writes an empty associative array as `[]`, so an instance without repositories sends
    /// `defaultsByRepo: []`. That reads as an empty dictionary instead of a type mismatch.
    public init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        accounts = try container.decode([ClaudeAccount].self, forKey: .accounts)
        // Decode probe: PHP encodes an empty map as [], so only a list that decodes empty means "no defaults".
        if let list = try? container.decode([RepositoryDefaults].self, forKey: .defaultsByRepo), list.isEmpty {
            defaultsByRepo = [:]
        } else {
            defaultsByRepo = try container.decode([String: RepositoryDefaults].self, forKey: .defaultsByRepo)
        }
    }
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
