public struct Composer: Codable, Equatable, Sendable {
    public let state: ComposerState
    public let model: String?
    public let effort: EffortLevel?
    public let models: [ModelFamily]
    public let accountName: String?
    public let nextAttachmentNumber: Int

    public init(
        state: ComposerState,
        model: String? = nil,
        effort: EffortLevel? = nil,
        models: [ModelFamily],
        accountName: String? = nil,
        nextAttachmentNumber: Int
    ) {
        self.state = state
        self.model = model
        self.effort = effort
        self.models = models
        self.accountName = accountName
        self.nextAttachmentNumber = nextAttachmentNumber
    }

    /// An effort level this build does not know decodes as nil, so a newer server never breaks the composer.
    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        state = try container.decode(ComposerState.self, forKey: .state)
        model = try container.decodeIfPresent(String.self, forKey: .model)
        effort = try container.decodeIfPresent(String.self, forKey: .effort).flatMap(EffortLevel.init(rawValue:))
        models = try container.decode([ModelFamily].self, forKey: .models)
        accountName = try container.decodeIfPresent(String.self, forKey: .accountName)
        nextAttachmentNumber = try container.decode(Int.self, forKey: .nextAttachmentNumber)
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
    public let efforts: [EffortLevel]

    public init(value: String, label: String, description: String, efforts: [EffortLevel] = EffortLevel.allCases) {
        self.value = value
        self.label = label
        self.description = description
        self.efforts = efforts
    }

    private enum CodingKeys: String, CodingKey { case value, label, description, efforts }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        value = try container.decode(String.self, forKey: .value)
        label = try container.decode(String.self, forKey: .label)
        description = try container.decode(String.self, forKey: .description)
        efforts = try decodeEfforts(container, .efforts)
    }
}

public struct ModelVersion: Codable, Equatable, Sendable {
    public let value: String
    public let label: String
    public let efforts: [EffortLevel]

    public init(value: String, label: String, efforts: [EffortLevel] = EffortLevel.allCases) {
        self.value = value
        self.label = label
        self.efforts = efforts
    }

    private enum CodingKeys: String, CodingKey { case value, label, efforts }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        value = try container.decode(String.self, forKey: .value)
        label = try container.decode(String.self, forKey: .label)
        efforts = try decodeEfforts(container, .efforts)
    }
}
