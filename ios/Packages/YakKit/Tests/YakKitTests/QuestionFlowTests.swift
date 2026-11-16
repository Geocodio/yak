import Testing

@testable import YakKit

struct QuestionFlowTests {
    private func round() throws -> ThreadQuestion {
        try #require(try decoded(ThreadShow.self, "scenario-thread-question").question)
    }

    @Test func anAnswerRoundNeverLosesAnAnswer() throws {
        let question = try round()
        var flow = QuestionFlow(question: question, draft: nil)
        flow.pick("Mark the row as failed")
        #expect(flow.step == .question(1))
        flow.pick("The channel")
        flow.pick("The owner")
        #expect(flow.step == .question(1) && flow.answers["notify"]?.choices == ["The owner", "The channel"])
        flow.next()
        #expect(flow.step == .review && flow.canSend)
        flow.edit(0)
        flow.startTyping()
        flow.submitTyped("  Retry later  ")
        #expect(flow.step == .review)
        #expect(flow.answers["on_failure"] == QuestionAnswer(choices: [], other: "Retry later"))
        #expect(flow.summary(for: "notify") == "The owner, The channel")
        let restored = QuestionFlow(question: question, draft: flow.draft)
        #expect(restored.step == .review && restored.answers == flow.answers)
        let other = QuestionFlow(
            question: question, draft: QuestionDraft(roundKey: "other:x", answers: flow.answers, note: nil))
        #expect(other.step == .question(0) && other.answers.isEmpty)
    }

    @Test func multiSelectNeedsAChoiceBeforeNext() throws {
        var flow = QuestionFlow(question: try round(), draft: nil)
        flow.pick("Fail the whole batch")
        #expect(flow.step == .question(1) && !flow.canGoNext)
        flow.next()
        #expect(flow.step == .question(1))
        flow.startTyping()
        flow.submitTyped("Nobody")
        #expect(flow.step == .review)
        flow.edit(1)
        #expect(flow.canGoNext)
        flow.pick("The owner")
        #expect(flow.answers["notify"] == QuestionAnswer(choices: ["The owner"], other: "Nobody"))
        flow.pick("The owner")
        #expect(flow.answers["notify"]?.choices == [])
        #expect(flow.canGoNext)
    }

    @Test func emptyTypingChangesNothing() throws {
        var flow = QuestionFlow(question: try round(), draft: nil)
        flow.startTyping()
        #expect(flow.step == .typing(0))
        flow.submitTyped("   ")
        #expect(flow.step == .question(0) && flow.answers.isEmpty)
        flow.startTyping()
        flow.cancelTyping()
        #expect(flow.step == .question(0))
    }

    @Test func backWalksThroughTheRound() throws {
        var flow = QuestionFlow(question: try round(), draft: nil)
        flow.pick("Fail the whole batch")
        flow.back()
        #expect(flow.step == .question(0))
        flow.back()
        #expect(flow.step == .question(0))
        flow.pick("Fail the whole batch")
        flow.pick("The owner")
        flow.next()
        flow.back()
        #expect(flow.step == .question(1))
    }

    @Test func aRestoredDraftStartsAtTheFirstGap() throws {
        let question = try round()
        let draft = QuestionDraft(
            roundKey: question.roundKey, answers: ["notify": QuestionAnswer(choices: ["The owner"])], note: "Hi")
        let flow = QuestionFlow(question: question, draft: draft)
        #expect(flow.step == .question(0) && flow.note == "Hi")
    }

    @Test func theNoteIsCutAndBlankIsNil() throws {
        var flow = QuestionFlow(question: try round(), draft: nil)
        flow.setNote(String(repeating: "a", count: 2100))
        #expect(flow.note.count == 2000 && flow.draft.note?.count == 2000)
        flow.setNote("")
        #expect(flow.draft.note == nil)
        flow.setNote("  \n ")
        #expect(flow.draft.note == nil)
    }

    @Test func theNoteIsCutByUnicodeScalars() throws {
        var flow = QuestionFlow(question: try round(), draft: nil)
        flow.setNote(String(repeating: "👨‍👩‍👧", count: 1000))
        #expect(flow.note.unicodeScalars.count <= 2000)
    }

    @Test func aTypedAnswerIsCutToTheServerLimit() throws {
        var flow = QuestionFlow(question: try round(), draft: nil)
        flow.startTyping()
        flow.submitTyped("  " + String(repeating: "👨‍👩‍👧", count: 1000))
        let other = flow.answers.values.first?.other ?? ""
        #expect(other.unicodeScalars.count <= 2000 && !other.isEmpty)
    }

    @Test func anEmptyRoundDoesNotCrash() throws {
        var flow = QuestionFlow(question: ThreadQuestion(taskId: LenientID("t"), questions: []), draft: nil)
        #expect(!flow.canGoNext)
        flow.pick("x")
        flow.startTyping()
        flow.submitTyped("x")
        flow.next()
        flow.back()
        #expect(!flow.canSend)
    }
}
