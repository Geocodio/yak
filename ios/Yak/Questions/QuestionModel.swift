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

    @ObservationIgnored private var backend: (any QuestionBackend)?
    @ObservationIgnored private var isQuestionGone = false
    @ObservationIgnored private var failureWasOffline = false
    /// Changes and saves run one after another, so a later draft never lands before an earlier one.
    @ObservationIgnored private var saveChain: Task<Void, Never>?

    init(backend: (any QuestionBackend)? = nil) {
        self.backend = backend
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
            } else if !holdsAnswers {
                clear()
            } else if !isClosed {
                isClosed = true
            }
            return
        }
        guard flow?.roundKey != question.roundKey else { return }
        let saved = await backend?.draft()
        // Another page may have arrived while the draft was read.
        guard flow?.roundKey != question.roundKey else { return }
        clear()
        flow = QuestionFlow(question: question, draft: saved)
    }

    func pick(_ label: String) async { await change { $0.pick(label) } }
    func next() async { await change { $0.next() } }
    func back() async { await change { $0.back() } }
    func edit(_ index: Int) async { await change { $0.edit(index) } }
    func setNote(_ text: String) async { await change { $0.setNote(text) } }

    func startTyping() {
        guard canChange, var next = flow else { return }
        next.startTyping()
        guard case .typing(let index) = next.step else { return }
        typedText = next.answers[next.questions[index].id]?.other ?? ""
        flow = next
    }

    func submitTyped(_ text: String) async {
        await change { $0.submitTyped(text) }
        typedText = ""
    }

    func cancelTyping() {
        guard canChange else { return }
        flow?.cancelTyping()
    }

    /// Sends the answers. A second call while one is running changes nothing.
    func send() async {
        guard let flow, flow.canSend, canChange, let backend else { return }
        sending = .sending
        fieldErrors = [:]
        failureWasOffline = false
        await saveChain?.value
        let outcome = await backend.send()
        switch outcome {
        case .done, .alreadySent:
            sentCount += 1
            clear()
        case .refused(let code, let message, let errors):
            if code == "question_closed" {
                sending = .idle
                isClosed = true
            } else {
                refuse(message: message, errors: errors)
            }
        case .failed(let problem):
            fail(problem)
        }
        if isQuestionGone, sending == .idle, !isClosed { clear() }
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
        guard canChange, var next = flow else { return }
        mutate(&next)
        flow = next
        if case .failed = sending { sending = .idle }
        let draft = next.draft
        let previous = saveChain
        let save = Task { [backend] in
            await previous?.value
            await backend?.save(draft)
        }
        saveChain = save
        await save.value
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
            sending = .failed(String(localized: "question.failed.offline"))
        case .server(_, let message) where !message.isEmpty:
            sending = .failed(message)
        default:
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
        typedText = ""
    }
}
