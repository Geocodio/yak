/// A half-answered question round, kept so leaving the thread does not lose it.
public struct QuestionDraft: Codable, Equatable, Sendable {
    public var roundKey: String
    public var answers: [String: QuestionAnswer]
    public var note: String?
    /// The question whose "Your answer" field was open and unconfirmed; its text is in that answer's `other`.
    public var typing: Int?

    public init(
        roundKey: String, answers: [String: QuestionAnswer] = [:], note: String? = nil, typing: Int? = nil
    ) {
        self.roundKey = roundKey
        self.answers = answers
        self.note = note
        self.typing = typing
    }
}
