import Foundation
import Testing
import YakKit

@testable import Yak

private actor FakeQuestionBackend: QuestionBackend {
    private(set) var saved: [QuestionDraft] = []
    private(set) var sendCount = 0
    var stored: QuestionDraft?
    var outcomes: [ThreadActionOutcome] = [.done]
    var holdsSend = false
    private var release: CheckedContinuation<Void, Never>?

    func draft() async -> QuestionDraft? { stored }
    func save(_ draft: QuestionDraft) async { saved.append(draft) }

    func send() async -> ThreadActionOutcome {
        sendCount += 1
        if holdsSend { await withCheckedContinuation { release = $0 } }
        return outcomes.count > 1 ? outcomes.removeFirst() : (outcomes.first ?? .done)
    }

    func set(outcomes: [ThreadActionOutcome]) { self.outcomes = outcomes }
    func set(stored: QuestionDraft?) { self.stored = stored }
    func hold() { holdsSend = true }
    func releaseSend() {
        release?.resume()
        release = nil
    }
}

private func round(task: String = "k3m9p2qa") -> ThreadQuestion {
    ThreadQuestion(
        taskId: LenientID(task),
        questions: [
            ClarificationQuestion(
                id: "on_failure", header: "On failure", question: "What happens to the row?",
                options: [
                    QuestionOption(label: "Mark the row as failed", description: ""),
                    QuestionOption(label: "Fail the whole batch", description: ""),
                ], multiSelect: false),
            ClarificationQuestion(
                id: "notify", header: "Notify", question: "Who hears about failures?",
                options: [
                    QuestionOption(label: "The owner", description: ""),
                    QuestionOption(label: "The channel", description: ""),
                ], multiSelect: true),
        ])
}

@MainActor private func answeredModel(_ backend: FakeQuestionBackend) async -> QuestionModel {
    let model = QuestionModel(backend: backend)
    await model.update(question: round())
    await model.pick("Mark the row as failed")
    await model.pick("The owner")
    await model.next()
    return model
}

@MainActor @Suite struct QuestionModelTests {
    @Test func everyActionSavesTheDraft() async {
        let backend = FakeQuestionBackend()
        let model = QuestionModel(backend: backend)
        await model.update(question: round())
        await model.pick("Mark the row as failed")
        await model.pick("The owner")
        await model.next()
        await model.setNote("Thanks")
        let saved = await backend.saved
        #expect(saved.count == 4)
        #expect(saved.last?.answers["notify"]?.choices == ["The owner"] && saved.last?.note == "Thanks")
        #expect(model.flow?.step == .review)
    }

    @Test func aNewRoundReplacesTheFlow() async {
        let backend = FakeQuestionBackend()
        let model = QuestionModel(backend: backend)
        await model.update(question: round())
        await model.pick("Fail the whole batch")
        await model.update(question: round())
        #expect(model.flow?.answers.count == 1)
        await model.update(question: round(task: "other"))
        #expect(model.flow?.answers.isEmpty == true && model.flow?.step == .question(0))
    }

    @Test func aSavedDraftRestoresTheFlow() async {
        let backend = FakeQuestionBackend()
        let first = QuestionFlow(question: round(), draft: nil)
        var answered = first
        answered.pick("Fail the whole batch")
        await backend.set(stored: answered.draft)
        let model = QuestionModel(backend: backend)
        await model.update(question: round())
        #expect(model.flow?.step == .question(1) && model.flow?.isAnswered("on_failure") == true)
    }

    @Test func nilQuestionRemovesTheCard() async {
        let model = QuestionModel(backend: FakeQuestionBackend())
        await model.update(question: round())
        await model.update(question: nil)
        #expect(model.flow == nil && !model.isClosed)
    }

    @Test func aClosingRoundKeepsWhatWasAnsweredUntilDismissed() async {
        let model = await answeredModel(FakeQuestionBackend())
        await model.update(question: nil)
        #expect(model.isClosed && model.flow?.canSend == true)
        await model.pick("Fail the whole batch")
        #expect(model.flow?.answers["on_failure"]?.choices == ["Mark the row as failed"])
        model.dismiss()
        #expect(model.flow == nil && !model.isClosed)
    }

    @Test func typedTextKeepsTheCardOpenWhenTheRoundCloses() async {
        let model = QuestionModel(backend: FakeQuestionBackend())
        await model.update(question: round())
        model.startTyping()
        model.typedText = "Retry once more"
        await model.update(question: nil)
        #expect(model.isClosed && model.typedText == "Retry once more")
    }

    @Test func aSendThatWorksRemovesTheCard() async {
        let backend = FakeQuestionBackend()
        let model = await answeredModel(backend)
        await model.send()
        #expect(model.flow == nil && model.sentCount == 1)
        #expect(await backend.sendCount == 1)
    }

    @Test func aDoubleTapSendsOnce() async {
        let backend = FakeQuestionBackend()
        await backend.hold()
        let model = await answeredModel(backend)
        let first = Task { await model.send() }
        while !model.isSending { await Task.yield() }
        await model.send()
        await backend.releaseSend()
        await first.value
        #expect(await backend.sendCount == 1)
    }

    @Test func offlineKeepsTheAnswersAndRetrySends() async {
        let backend = FakeQuestionBackend()
        await backend.set(outcomes: [.failed(.offline), .done])
        let model = await answeredModel(backend)
        await model.send()
        #expect(model.sending == .failed(String(localized: "question.failed.offline")))
        #expect(model.flow?.canSend == true && model.flow?.step == .review)
        model.connectionRestored()
        #expect(model.sending == .idle)
        await model.send()
        #expect(model.flow == nil)
    }

    @Test func aServerFailureShowsItsMessage() async {
        let backend = FakeQuestionBackend()
        await backend.set(outcomes: [.failed(.server(code: "server_error", message: "Try later."))])
        let model = await answeredModel(backend)
        await model.send()
        #expect(model.sending == .failed("Try later.") && model.flow?.canSend == true)
    }

    @Test func fieldErrorsGoUnderTheirQuestion() async {
        let backend = FakeQuestionBackend()
        await backend.set(outcomes: [
            .refused(code: "validation_failed", message: "m", fieldErrors: ["answers.on_failure": ["Pick an option."]])
        ])
        let model = await answeredModel(backend)
        await model.send()
        #expect(model.fieldErrors == ["on_failure": "Pick an option."])
        #expect(model.flow?.step == .review && model.sending == .idle)
        #expect(model.flow?.answers.count == 2)
    }

    @Test func aClosedQuestionIsShownAsClosedWithTheAnswersKept() async {
        let backend = FakeQuestionBackend()
        await backend.set(outcomes: [.refused(code: "question_closed", message: "", fieldErrors: [:])])
        let model = await answeredModel(backend)
        await model.send()
        #expect(model.isClosed && model.flow?.canSend == true)
        // The refreshed page no longer has the question; the card stays until it is dismissed.
        await model.update(question: nil)
        #expect(model.flow != nil && model.isClosed)
        model.dismiss()
        #expect(model.flow == nil)
    }

    @Test func aRoundThatClosesDuringTheSendEndsTheSendFirst() async {
        let backend = FakeQuestionBackend()
        await backend.hold()
        let model = await answeredModel(backend)
        let sending = Task { await model.send() }
        while !model.isSending { await Task.yield() }
        await model.update(question: nil)
        #expect(!model.isClosed && model.flow != nil)
        await backend.releaseSend()
        await sending.value
        #expect(model.flow == nil && model.sentCount == 1)
    }
}
