public struct ThreadQuestion: Codable, Equatable, Sendable {
    public let taskId: LenientID
    public let expiresAt: String?
    public let questions: [ClarificationQuestion]

    public init(taskId: LenientID, expiresAt: String? = nil, questions: [ClarificationQuestion]) {
        self.taskId = taskId
        self.expiresAt = expiresAt
        self.questions = questions
    }

    /// Names one round of questions; a new round always has a new key.
    public var roundKey: String {
        "\(taskId.value):\(questions.map(\.id).joined(separator: ","))"
    }
}

public struct ClarificationQuestion: Codable, Equatable, Sendable {
    public let id: String
    public let header: String
    public let question: String
    public let options: [QuestionOption]
    public let multiSelect: Bool

    public init(
        id: String,
        header: String,
        question: String,
        options: [QuestionOption],
        multiSelect: Bool
    ) {
        self.id = id
        self.header = header
        self.question = question
        self.options = options
        self.multiSelect = multiSelect
    }
}

public struct QuestionOption: Codable, Equatable, Sendable {
    public let label: String
    public let description: String

    public init(label: String, description: String) {
        self.label = label
        self.description = description
    }
}
