import Foundation

/// How a person's action on a thread ended, each case a designed state for the screen.
public enum ThreadActionOutcome: Equatable, Sendable {
    case done
    /// 409 message_delivered: the queued message already went out. The page is refreshed.
    case alreadySent
    /// A 4xx answer. `fieldErrors` comes from `details.errors` of a 422.
    case refused(code: String, message: String, fieldErrors: [String: [String]])
    /// A network failure, a 5xx answer and everything else.
    case failed(ThreadProblem)

    init(_ failure: APIFailure) {
        guard case .server(let error) = failure, (400..<500).contains(error.status) else {
            self = .failed(ThreadProblem(failure))
            return
        }
        if error.status == 409, error.code == "message_delivered" {
            self = .alreadySent
            return
        }
        var fieldErrors: [String: [String]] = [:]
        if case .object(let fields)? = error.details["errors"] {
            for (field, value) in fields {
                if case .array(let messages) = value { fieldErrors[field] = messages.compactMap(\.stringValue) }
            }
        }
        self = .refused(code: error.code, message: error.message, fieldErrors: fieldErrors)
    }

    fileprivate static let questionClosed = ThreadActionOutcome.refused(
        code: "question_closed", message: "", fieldErrors: [:])
}

extension ThreadEngine {
    public func stopTurn() async -> ThreadActionOutcome {
        await perform("stop", .stopTurn(threadID))
    }

    public func continueTurn() async -> ThreadActionOutcome {
        await perform("continue", .continueTurn(threadID))
    }

    public func retryRestore() async -> ThreadActionOutcome {
        await perform("retryRestore", .retryRestore(threadID))
    }

    /// Sends the saved draft for the open round.
    public func answerQuestion() async -> ThreadActionOutcome {
        guard let question = show?.question,
            let draft = await cache.questionDraft(thread: threadID), draft.roundKey == question.roundKey
        else { return .questionClosed }
        return await perform(
            "answer", .answerQuestion(threadID, answers: draft.answers, note: draft.note),
            onDone: { [answeredRoundKey = draft.roundKey] in
                await $0.cache.removeQuestionDraft(thread: $0.threadID) { $0 == answeredRoundKey }
            })
    }

    public func questionDraft() async -> QuestionDraft? {
        await cache.questionDraft(thread: threadID)
    }

    /// Saves the draft at once. A draft for a round that is not the open one is ignored.
    public func updateQuestionDraft(_ draft: QuestionDraft) async {
        guard !isClosed, show?.question?.roundKey == draft.roundKey else { return }
        await cache.saveQuestionDraft(draft, thread: threadID)
    }

    public func setQueuedMessageMode(_ uuid: String, mode: MessageMode) async -> ThreadActionOutcome {
        await perform("mode:\(uuid):\(mode.rawValue)", .updateQueuedMessage(threadID, uuid: uuid, mode: mode, text: nil))
    }

    /// Trims the text and refuses an empty one without a request; the server limits the length.
    public func editQueuedMessage(_ uuid: String, text: String) async -> ThreadActionOutcome {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else {
            return .refused(code: "validation_failed", message: "", fieldErrors: ["text": []])
        }
        return await perform("edit:\(uuid):\(trimmed)", .updateQueuedMessage(threadID, uuid: uuid, mode: nil, text: trimmed))
    }

    public func withdrawQueuedMessage(_ uuid: String) async -> ThreadActionOutcome {
        await perform("withdraw:\(uuid)", .withdrawQueuedMessage(threadID, uuid: uuid))
    }

    /// Runs one request and refreshes the page after it, unless the network was down. A second call with
    /// the same key while one is in flight waits for it and returns its outcome. Keys of edits carry the
    /// requested change, so a different change is sent as its own request.
    private func perform<Response: Decodable & Sendable>(
        _ key: String, _ endpoint: Endpoint<Response>,
        onDone: (@Sendable (ThreadEngine) async -> Void)? = nil
    ) async -> ThreadActionOutcome {
        guard !isClosed else { return .failed(.offline) }
        if let running = actionTasks[key] { return await running.value }
        let task = Task { () -> ThreadActionOutcome in
            let outcome: ThreadActionOutcome
            do throws(APIFailure) {
                _ = try await self.client.send(endpoint)
                outcome = .done
            } catch {
                outcome = ThreadActionOutcome(error)
            }
            if outcome == .done { await onDone?(self) }
            if outcome != .failed(.offline) { await self.refreshShow() }
            self.actionTasks[key] = nil
            return outcome
        }
        actionTasks[key] = task
        return await task.value
    }

    /// Removes a saved draft that belongs to another round, or to none, once the page is known.
    func dropStaleQuestionDraft() async {
        let currentRoundKey = show?.question?.roundKey
        await cache.removeQuestionDraft(thread: threadID) { $0 != currentRoundKey }
    }
}
