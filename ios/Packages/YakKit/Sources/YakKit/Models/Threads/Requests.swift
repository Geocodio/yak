/// When a message that is sent while a turn runs takes effect.
public enum MessageDelivery: String, Codable, Sendable {
    case steer
    case afterTurn = "after_turn"
}

/// What the person chose on the new thread screen.
public struct NewThreadDraft: Codable, Equatable, Hashable, Sendable {
    public struct ExtraRepository: Codable, Equatable, Hashable, Sendable {
        public let repo: String
        public let role: String

        public init(repo: String, role: String) {
            self.repo = repo
            self.role = role
        }
    }

    public let repo: String
    public let mode: String
    public let repositories: [ExtraRepository]
    public let claudeAccountId: Int?
    public let model: String?
    public let effort: EffortLevel?

    public init(
        repo: String, mode: String, repositories: [ExtraRepository] = [], claudeAccountId: Int? = nil,
        model: String? = nil, effort: EffortLevel? = nil
    ) {
        self.repo = repo
        self.mode = mode
        self.repositories = repositories
        self.claudeAccountId = claudeAccountId
        self.model = model
        self.effort = effort
    }
}

/// The answer to one question: the chosen option ids and the free text, when there is one.
public struct QuestionAnswer: Codable, Equatable, Sendable {
    public let choices: [String]
    public let other: String?

    public init(choices: [String], other: String? = nil) {
        self.choices = choices
        self.other = other
    }

    /// `other` is always present in the body, `null` when there is none.
    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(choices, forKey: .choices)
        try container.encode(other, forKey: .other)
    }

    private enum CodingKeys: String, CodingKey { case choices, other }
}
