import Foundation
import Observation
import YakKit

/// What the question card needs from the thread's engine, so its rules are tested without one.
protocol QuestionBackend: Sendable {
    func draft() async -> QuestionDraft?
    func save(_ draft: QuestionDraft) async
    /// Sends the saved draft of the open round.
    func send() async -> ThreadActionOutcome
}

struct LiveQuestionBackend: QuestionBackend {
    let engine: ThreadEngine

    func draft() async -> QuestionDraft? { await engine.questionDraft() }
    func save(_ draft: QuestionDraft) async { await engine.updateQuestionDraft(draft) }
    func send() async -> ThreadActionOutcome { await engine.answerQuestion() }
}

/// The open question round of one thread: the flow through its questions, the saved draft, and the send.
///
/// Every change to the flow is saved before the next one, so leaving the thread or the app loses nothing. The
/// round's key names the send: the engine sends the draft of that round once at a time, and a round the server
/// has closed answers `question_closed`. A round that closes while the card holds answers stays on screen,
/// read-only, until the person dismisses it.
@MainActor @Observable
final class QuestionModel {
    enum Sending: Equatable {
        case idle, sending
        case failed(String)
    }

    private(set) var flow: QuestionFlow?
    private(set) var sending = Sending.idle
    /// Question id to the first message the server gave for it.
    private(set) var fieldErrors: [String: String] = [:]
    /// The round closed while the card held answers; the card shows them and waits to be dismissed.
    private(set) var isClosed = false
    /// Counts answers that were sent, so the view can play the success haptic once per send.
    private(set) var sentCount = 0
    /// The text in the "Your answer" field; kept here so a closed round still shows it.
    var typedText = ""
    /// A field of the card has focus: the card is the input, so the message bar steps aside.
    var isEditing = false
    /// True once a send has run longer than `slowAfter`; the card then says it is taking longer than usual.
    private(set) var isSlow = false
    /// Counts refused or failed sends, so the view can play the error haptic once per failure.
    private(set) var failureCount = 0

    @ObservationIgnored private var backend: (any QuestionBackend)?
    @ObservationIgnored private var isQuestionGone = false
    @ObservationIgnored private var failureWasOffline = false
    /// A send failed without an answer, so the server may have recorded it. The round closing then means it was.
    @ObservationIgnored private var mayHaveBeenDelivered = false
    /// The round just answered; a snapshot that still carries it is stale and is ignored.
    @ObservationIgnored private var answeredRoundKey: String?
    /// A round that arrived during a send; it is applied when the send ends.
    @ObservationIgnored private var pendingQuestion: ThreadQuestion?
    @ObservationIgnored private let slowAfter: Duration
    /// Changes and saves run one after another, so a later draft never lands before an earlier one.
    @ObservationIgnored private var saveChain: Task<Void, Never>?

    init(backend: (any QuestionBackend)? = nil, slowAfter: Duration = .seconds(8)) {
        self.backend = backend
        self.slowAfter = slowAfter
    }

    func use(backend: any QuestionBackend) {
        self.backend = backend
    }

    var isSending: Bool { sending == .sending }

    /// Whether the round holds anything the person would lose: an answer, a note or text in the field.
    private var holdsAnswers: Bool {
        guard let flow else { return false }
        return !flow.answers.isEmpty || !flow.note.isEmpty || !typedText.trimmingCharacters(in: .whitespaces).isEmpty
    }

    /// Takes the open question of the newest page, or nil when there is none. A new round starts a new flow from
    /// the saved draft; the same round keeps the flow it has.
    func update(question: ThreadQuestion?) async {
        guard let question else {
            if isSending {
                isQuestionGone = true
            } else if mayHaveBeenDelivered {
                settleAsAnswered()
            } else if !holdsAnswers {
                clear()
            } else if !isClosed {
                isClosed = true
            }
            return
        }
        guard question.roundKey != answeredRoundKey else { return }
        if isSending {
            if !isCurrent(question) { pendingQuestion = question }
            return
        }
        guard !isCurrent(question) else { return }
        let saved = await backend?.draft()
        // Another page may have arrived while the draft was read.
        guard !isCurrent(question), !isSending else {
            if isSending, !isCurrent(question) { pendingQuestion = question }
            return
        }
        clear()
        answeredRoundKey = nil
        let restored = QuestionFlow(question: question, draft: saved)
        if case .typing(let index) = restored.step {
            typedText = restored.answers[restored.questions[index].id]?.other ?? ""
        }
        flow = restored
    }

    /// Whether the card already shows this round with these very questions; ids alone can repeat across rounds.
    private func isCurrent(_ question: ThreadQuestion) -> Bool {
        flow?.roundKey == question.roundKey && flow?.questions == question.questions
    }

    /// A tap acts only on the question it was made on, so a double tap cannot answer the next one.
    func pick(_ label: String, question index: Int) async {
        guard flow?.step == .question(index) else { return }
        await change { $0.pick(label) }
    }

    func next(question index: Int) async {
        guard flow?.step == .question(index) else { return }
        await change { $0.next() }
    }
    func back() async { await change { $0.back() } }
    func edit(_ index: Int) async { await change { $0.edit(index) } }

    /// Takes the note at once, so a text field bound to it never reads a stale value; the save is queued.
    func setNote(_ text: String) { _ = apply { $0.setNote(text) } }

    func startTyping() {
        guard canChange, var next = flow else { return }
        next.startTyping()
        guard case .typing(let index) = next.step else { return }
        typedText = next.answers[next.questions[index].id]?.other ?? ""
        flow = next
    }

    func submitTyped(_ text: String) async {
        guard canChange else { return }
        await change { $0.submitTyped(text) }
        typedText = ""
    }

    func cancelTyping() {
        guard canChange else { return }
        flow?.cancelTyping()
        typedText = ""
    }

    /// Saves the draft with the text in the open field, without changing what is on screen, and waits for every
    /// queued save. The thread calls it when it goes away or the app goes to the background.
    func flush() async {
        if canChange, let flow, case .typing = flow.step,
            !typedText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        {
            let draft = flow.draft(typing: typedText)
            let previous = saveChain
            saveChain = Task { [backend] in
                await previous?.value
                await backend?.save(draft)
            }
        }
        await saveChain?.value
    }

    /// Sends the answers. A second call while one is running changes nothing.
    func send() async {
        guard let flow, flow.canSend, canChange, let backend else { return }
        sending = .sending
        fieldErrors = [:]
        failureWasOffline = false
        isSlow = false
        let slowTimer = Task { [slowAfter] in
            try? await Task.sleep(for: slowAfter)
            if !Task.isCancelled { self.isSlow = true }
        }
        await saveChain?.value
        let outcome = await backend.send()
        slowTimer.cancel()
        isSlow = false
        let key = flow.roundKey
        switch outcome {
        case .done, .alreadySent:
            sentCount += 1
            clear()
            answeredRoundKey = key
        case .refused(let code, let message, let errors):
            let delivered = mayHaveBeenDelivered
            mayHaveBeenDelivered = false
            if code == "question_closed" {
                if delivered {
                    settleAsAnswered()
                } else {
                    sending = .idle
                    isClosed = true
                }
            } else {
                failureCount += 1
                refuse(message: message, errors: errors)
            }
        case .failed(let problem):
            failureCount += 1
            fail(problem)
        }
        if isQuestionGone, !isClosed, self.flow != nil, sending == .idle || mayHaveBeenDelivered { settleAsAnswered() }
        if let pending = pendingQuestion {
            pendingQuestion = nil
            await update(question: pending)
        }
    }

    /// The connection is back: an offline failure gives way to the review, so Send is one tap away.
    func connectionRestored() {
        if failureWasOffline, case .failed = sending { sending = .idle }
    }

    /// Removes the card of a round that closed.
    func dismiss() {
        guard isClosed else { return }
        clear()
    }

    // MARK: Private

    private var canChange: Bool { flow != nil && !isClosed && !isSending }

    private func change(_ mutate: (inout QuestionFlow) -> Void) async {
        guard apply(mutate) else { return }
        await saveChain?.value
    }

    /// Changes the flow at once and queues the save behind the earlier ones; false when a change is not allowed.
    private func apply(_ mutate: (inout QuestionFlow) -> Void) -> Bool {
        guard canChange, var next = flow else { return false }
        mutate(&next)
        flow = next
        if case .failed = sending { sending = .idle }
        let draft = next.draft
        let previous = saveChain
        saveChain = Task { [backend] in
            await previous?.value
            await backend?.save(draft)
        }
        return true
    }

    /// The round closed after a send that may have arrived: the answers are not shown as unsent, the card goes.
    private func settleAsAnswered() {
        let key = flow?.roundKey
        clear()
        answeredRoundKey = key
    }

    private func refuse(message: String, errors: [String: [String]]) {
        var byQuestion: [String: String] = [:]
        for (field, messages) in errors {
            guard field.hasPrefix("answers."), let first = messages.first else { continue }
            let id = String(field.dropFirst("answers.".count).prefix { $0 != "." })
            if byQuestion[id] == nil { byQuestion[id] = first }
        }
        fieldErrors = byQuestion
        if byQuestion.isEmpty {
            sending = .failed(message.isEmpty ? String(localized: "question.failed") : message)
        } else {
            sending = .idle
        }
    }

    private func fail(_ problem: ThreadProblem) {
        switch problem {
        case .offline:
            failureWasOffline = true
            mayHaveBeenDelivered = true
            sending = .failed(String(localized: "question.failed.offline"))
        case .server(_, let message) where !message.isEmpty:
            mayHaveBeenDelivered = true
            sending = .failed(message)
        default:
            mayHaveBeenDelivered = true
            sending = .failed(String(localized: "question.failed"))
        }
    }

    private func clear() {
        flow = nil
        sending = .idle
        fieldErrors = [:]
        isClosed = false
        isQuestionGone = false
        failureWasOffline = false
        mayHaveBeenDelivered = false
        isSlow = false
        typedText = ""
    }
}
