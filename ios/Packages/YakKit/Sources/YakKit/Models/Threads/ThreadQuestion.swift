public struct ThreadQuestion: Codable, Equatable, Sendable {
    public let taskId: LenientID
    public let expiresAt: String?
    public let questions: [ClarificationQuestion]

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
}

public struct QuestionOption: Codable, Equatable, Sendable {
    public let label: String
    public let description: String
}
