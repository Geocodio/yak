/// A half-answered question round, kept so leaving the thread does not lose it.
public struct QuestionDraft: Codable, Equatable, Sendable {
    public var roundKey: String
    public var answers: [String: QuestionAnswer]
    public var note: String?

    public init(roundKey: String, answers: [String: QuestionAnswer] = [:], note: String? = nil) {
        self.roundKey = roundKey
        self.answers = answers
        self.note = note
    }
}
