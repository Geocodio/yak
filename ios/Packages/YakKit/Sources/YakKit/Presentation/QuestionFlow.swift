/// One round of clarification questions, answered one at a time and reviewed before sending.
public struct QuestionFlow: Equatable, Sendable {
    public enum Step: Equatable, Sendable {
        case question(Int)
        case typing(Int)
        case review
    }

    public static let maximumNoteLength = 2000

    public let roundKey: String
    public let questions: [ClarificationQuestion]
    public private(set) var step: Step
    public private(set) var answers: [String: QuestionAnswer]
    public private(set) var note: String
    private var returnsToReview = false

    public init(question: ThreadQuestion, draft: QuestionDraft?) {
        roundKey = question.roundKey
        questions = question.questions
        answers = [:]
        note = ""
        step = .question(0)
        guard let draft, draft.roundKey == question.roundKey else { return }
        let known = Set(question.questions.map(\.id))
        answers = draft.answers.filter { known.contains($0.key) }
        note = (draft.note ?? "").cutToServerLimit
        if let gap = questions.firstIndex(where: { !isAnswered($0.id) }) {
            step = .question(gap)
        } else {
            step = .review
        }
        if let typing = draft.typing, questions.indices.contains(typing) { step = .typing(typing) }
    }

    /// The draft to save while the field holds unconfirmed text: the text is the answer, and the draft remembers
    /// that the field was open. Without text it is the plain draft.
    public func draft(typing text: String) -> QuestionDraft {
        guard case .typing(let index) = step, !text.trimmedForAnswer.isEmpty else { return draft }
        var copy = self
        copy.submitTyped(text)
        var saved = copy.draft
        saved.typing = index
        return saved
    }

    /// The draft to save; a blank note is written as nil.
    public var draft: QuestionDraft {
        QuestionDraft(roundKey: roundKey, answers: answers, note: note.trimmedForAnswer.isEmpty ? nil : note)
    }

    public var canGoNext: Bool {
        guard let index = currentIndex, questions.indices.contains(index) else { return false }
        return isAnswered(questions[index].id)
    }

    public var canSend: Bool { !questions.isEmpty && questions.allSatisfy { isAnswered($0.id) } }

    public func isAnswered(_ questionID: String) -> Bool {
        guard let answer = answers[questionID] else { return false }
        return !answer.choices.isEmpty || !(answer.other ?? "").trimmedForAnswer.isEmpty
    }

    public func isSelected(_ label: String, question index: Int) -> Bool {
        guard questions.indices.contains(index) else { return false }
        return answers[questions[index].id]?.choices.contains(label) ?? false
    }

    public func summary(for questionID: String) -> String? {
        guard isAnswered(questionID), let answer = answers[questionID] else { return nil }
        let other = (answer.other ?? "").trimmedForAnswer
        return (answer.choices + (other.isEmpty ? [] : [other])).joined(separator: ", ")
    }

    public mutating func pick(_ label: String) {
        guard let index = currentIndex, questions.indices.contains(index) else { return }
        let question = questions[index]
        guard question.options.contains(where: { $0.label == label }) else { return }
        if question.multiSelect {
            var chosen = Set(answers[question.id]?.choices ?? [])
            if !chosen.insert(label).inserted { chosen.remove(label) }
            let ordered = question.options.map(\.label).filter(chosen.contains)
            answers[question.id] = QuestionAnswer(choices: ordered, other: answers[question.id]?.other)
        } else {
            answers[question.id] = QuestionAnswer(choices: [label], other: nil)
            moveOn(from: index)
        }
    }

    public mutating func next() {
        guard canGoNext, let index = currentIndex else { return }
        moveOn(from: index)
    }

    public mutating func back() {
        returnsToReview = false
        switch step {
        case .question(let index) where index > 0: step = .question(index - 1)
        case .review where !questions.isEmpty: step = .question(questions.count - 1)
        default: break
        }
    }

    public mutating func startTyping() {
        if case .question(let index) = step { step = .typing(index) }
    }

    public mutating func submitTyped(_ text: String) {
        guard case .typing(let index) = step, questions.indices.contains(index) else { return }
        let trimmed = text.trimmedForAnswer.cutToServerLimit
        guard !trimmed.isEmpty else {
            step = .question(index)
            return
        }
        let question = questions[index]
        let kept = question.multiSelect ? (answers[question.id]?.choices ?? []) : []
        answers[question.id] = QuestionAnswer(choices: kept, other: trimmed)
        moveOn(from: index)
    }

    public mutating func cancelTyping() {
        if case .typing(let index) = step { step = .question(index) }
    }

    public mutating func edit(_ index: Int) {
        guard questions.indices.contains(index) else { return }
        returnsToReview = true
        step = .question(index)
    }

    public mutating func setNote(_ text: String) {
        note = text.cutToServerLimit
    }

    private var currentIndex: Int? {
        switch step {
        case .question(let index), .typing(let index): index
        case .review: nil
        }
    }

    private mutating func moveOn(from index: Int) {
        if returnsToReview || index + 1 >= questions.count {
            returnsToReview = false
            step = .review
        } else {
            step = .question(index + 1)
        }
    }
}

extension String {
    /// The server counts code points (max:2000), so the cut is by unicode scalars.
    fileprivate var cutToServerLimit: String {
        String(String.UnicodeScalarView(unicodeScalars.prefix(QuestionFlow.maximumNoteLength)))
    }

    fileprivate var trimmedForAnswer: String {
        var scalars = Substring(self)
        while let first = scalars.first, first.isWhitespace || first.isNewline { scalars.removeFirst() }
        while let last = scalars.last, last.isWhitespace || last.isNewline { scalars.removeLast() }
        return String(scalars)
    }
}
