public struct Composer: Codable, Equatable, Sendable {
    public let state: ComposerState
    public let model: String?
    public let models: [ModelFamily]
    public let accountName: String?
    public let nextAttachmentNumber: Int

    public init(
        state: ComposerState,
        model: String? = nil,
        models: [ModelFamily],
        accountName: String? = nil,
        nextAttachmentNumber: Int
    ) {
        self.state = state
        self.model = model
        self.models = models
        self.accountName = accountName
        self.nextAttachmentNumber = nextAttachmentNumber
    }
}

public struct ModelFamily: Codable, Equatable, Sendable {
    public let family: String
    public let latest: ModelChoice
    public let older: [ModelVersion]

    public init(family: String, latest: ModelChoice, older: [ModelVersion]) {
        self.family = family
        self.latest = latest
        self.older = older
    }
}

public struct ModelChoice: Codable, Equatable, Sendable {
    public let value: String
    public let label: String
    public let description: String

    public init(value: String, label: String, description: String) {
        self.value = value
        self.label = label
        self.description = description
    }
}

public struct ModelVersion: Codable, Equatable, Sendable {
    public let value: String
    public let label: String

    public init(value: String, label: String) {
        self.value = value
        self.label = label
    }
}
