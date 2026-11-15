public struct Composer: Codable, Equatable, Sendable {
    public let state: ComposerState
    public let model: String?
    public let models: [ModelFamily]
    public let accountName: String?
    public let nextAttachmentNumber: Int
}

public struct ModelFamily: Codable, Equatable, Sendable {
    public let family: String
    public let latest: ModelChoice
    public let older: [ModelVersion]
}

public struct ModelChoice: Codable, Equatable, Sendable {
    public let value: String
    public let label: String
    public let description: String
}

public struct ModelVersion: Codable, Equatable, Sendable {
    public let value: String
    public let label: String
}
