public struct NewThreadOptions: Codable, Equatable, Sendable {
    public let repos: [NewThreadRepository]
    public let picker: AccountPicker
    public let defaultRepo: String?
    public let models: [ModelFamily]

    public init(
        repos: [NewThreadRepository],
        picker: AccountPicker,
        defaultRepo: String? = nil,
        models: [ModelFamily]
    ) {
        self.repos = repos
        self.picker = picker
        self.defaultRepo = defaultRepo
        self.models = models
    }
}

public struct NewThreadRepository: Codable, Equatable, Sendable {
    public let slug: String
    public let name: String
    public let setupStatus: String
    public let setupStep: String?

    public init(
        slug: String,
        name: String,
        setupStatus: String,
        setupStep: String? = nil
    ) {
        self.slug = slug
        self.name = name
        self.setupStatus = setupStatus
        self.setupStep = setupStep
    }
}

public struct AccountPicker: Codable, Equatable, Sendable {
    public let accounts: [ClaudeAccount]
    public let defaultsByRepo: [String: RepositoryDefaults]

    public init(accounts: [ClaudeAccount], defaultsByRepo: [String: RepositoryDefaults]) {
        self.accounts = accounts
        self.defaultsByRepo = defaultsByRepo
    }

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

    public init(
        id: LenientID,
        name: String,
        kind: String,
        isDefault: Bool,
        defaultModel: String? = nil,
        usable: Bool,
        status: String
    ) {
        self.id = id
        self.name = name
        self.kind = kind
        self.isDefault = isDefault
        self.defaultModel = defaultModel
        self.usable = usable
        self.status = status
    }
}

public struct RepositoryDefaults: Codable, Equatable, Sendable {
    public let accountId: LenientID?
    public let model: String?

    public init(accountId: LenientID? = nil, model: String? = nil) {
        self.accountId = accountId
        self.model = model
    }
}
