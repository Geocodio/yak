import Foundation

/// How a person's action on a thread ended, each case a designed state for the screen.
public enum ThreadActionOutcome: Equatable, Sendable {
    case done
    /// 409 message_delivered: the queued message already went out. The page is refreshed.
    case alreadySent
    /// The server said no, in words the person can read: a 4xx answer, or a 5xx answer with a code of its own
    /// (`github_failed`, `access_check_failed`). `fieldErrors` comes from `details.errors` of a 422 and
    /// `connectURL` from `details.connectUrl` when GitHub must be linked first.
    case refused(code: String, message: String, fieldErrors: [String: [String]], connectURL: String? = nil)
    /// A network failure, a timeout, a 503 `unavailable`, a generic server error and everything else.
    case failed(ThreadProblem)

    init(_ failure: APIFailure) {
        guard case .server(let error) = failure, Self.isRefusal(error) else {
            self = .failed(ThreadProblem(failure))
            return
        }
        if error.status == 409, error.code == "message_delivered" {
            self = .alreadySent
            return
        }
        self = .refused(
            code: error.code, message: error.message, fieldErrors: refusalFieldErrors(error),
            connectURL: error.details["connectUrl"]?.stringValue)
    }

    /// A 5xx answer is a refusal when it names its own reason; `unavailable` and `server_error` are transient.
    private static func isRefusal(_ error: APIError) -> Bool {
        if (400..<500).contains(error.status) { return true }
        return error.status >= 500 && !["unavailable", "server_error", "unknown"].contains(error.code)
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

    /// Sends one work action of the thread (retry, move, a co-owner decision and the rest). The answer carries
    /// the refreshed show, which replaces the page without a second request. A move that lands on another
    /// thread sets the replacement instead. A second call for the same action joins the one in flight.
    public func perform(_ action: ThreadWorkAction) async -> ThreadActionOutcome {
        await perform(
            "work:\(action)", .threadAction(threadID, action),
            onResponse: { engine, answer in await engine.adopt(answer.show) })
    }

    /// The people GitHub says could co-own this thread. A second call after `isComplete` was false asks again.
    public func coOwnerCandidates() async -> Result<CoOwnerCandidates, APIFailure> {
        guard !isClosed else { return .failure(.network(.cancelled)) }
        do throws(APIFailure) {
            return .success(try await sendWaitingOutRateLimit(.threadCoOwnerCandidates(threadID)))
        } catch {
            return .failure(error)
        }
    }

    public func setQueuedMessageMode(_ uuid: String, mode: MessageMode) async -> ThreadActionOutcome {
        await perform(
            "mode:\(uuid):\(mode.rawValue)",
            .updateQueuedMessage(threadID, uuid: uuid, mode: mode, text: nil))
    }

    /// Trims the text and refuses an empty one without a request; the server limits the length.
    public func editQueuedMessage(_ uuid: String, text: String) async -> ThreadActionOutcome {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else {
            return .refused(code: "validation_failed", message: "", fieldErrors: ["text": []])
        }
        return await perform(
            "edit:\(uuid):\(trimmed)",
            .updateQueuedMessage(threadID, uuid: uuid, mode: nil, text: trimmed))
    }

    public func withdrawQueuedMessage(_ uuid: String) async -> ThreadActionOutcome {
        await perform("withdraw:\(uuid)", .withdrawQueuedMessage(threadID, uuid: uuid))
    }

    /// Runs one request and refreshes the page after it, unless the network was down. A second call with
    /// the same key while one is in flight waits for it and returns its outcome. Keys of edits carry the
    /// requested change, so a different change is sent as its own request.
    private func perform<Response: Decodable & Sendable>(
        _ key: String, _ endpoint: Endpoint<Response>,
        onResponse: (@Sendable (ThreadEngine, Response) async -> Bool)? = nil,
        onDone: (@Sendable (ThreadEngine) async -> Void)? = nil
    ) async -> ThreadActionOutcome {
        guard !isClosed else { return .failed(.offline) }
        if let running = actionTasks[key] { return await running.value }
        let task = Task { () -> ThreadActionOutcome in
            let outcome: ThreadActionOutcome
            var isShowAdopted = false
            do throws(APIFailure) {
                let response = try await self.sendWaitingOutRateLimit(endpoint)
                outcome = .done
                if let onResponse { isShowAdopted = await onResponse(self, response) }
            } catch {
                outcome = ThreadActionOutcome(error)
            }
            if case .refused("forbidden", _, _, _) = outcome, !self.isClosed {
                self.isForbidden = true
                self.changed(isStored: false)
            }
            if outcome == .done { await onDone?(self) }
            if outcome != .failed(.offline), !isShowAdopted { await self.refreshShow() }
            self.actionTasks[key] = nil
            return outcome
        }
        actionTasks[key] = task
        return await task.value
    }

    /// The longest `Retry-After` an action waits out by itself; a longer wait is shown as the server's refusal.
    static let longestSilentWait = Duration.seconds(30)
    /// After this long the person is told the action is taking longer.
    static let slowActionAfter = Duration.seconds(5)

    /// Sends an action. A `rate_limited` answer is waited out once, as the server asked, and the request is sent again;
    /// the wait ends when the engine closes. After five seconds the snapshot says the action is slow.
    func sendWaitingOutRateLimit<Response: Decodable & Sendable>(
        _ endpoint: Endpoint<Response>
    ) async throws(APIFailure) -> Response {
        do { return try await client.send(endpoint) } catch {
            guard case .server(let refusal) = error, refusal.status == 429 else { throw error }
            let wait = Duration.seconds(refusal.retryAfterSeconds ?? 2)
            guard wait <= Self.longestSilentWait else { throw error }
            let waitID = UUID()
            let slowTimer = Task { [weak self] in
                guard let self else { return }
                do { try await self.clock.sleep(for: Self.slowActionAfter) } catch { return }
                await self.markSlow(waitID)
            }
            defer {
                slowTimer.cancel()
                if slowWaits.remove(waitID) != nil { changed(isStored: false) }
            }
            do { try await clock.sleep(for: wait) } catch { throw .network(.cancelled) }
            return try await client.send(endpoint)
        }
    }

    private func markSlow(_ waitID: UUID) {
        guard !isClosed, !Task.isCancelled else { return }
        slowWaits.insert(waitID)
        changed(isStored: false)
    }

    /// Removes a saved draft that belongs to another round, or to none, once the page is known.
    func dropStaleQuestionDraft() async {
        let currentRoundKey = show?.question?.roundKey
        await cache.removeQuestionDraft(thread: threadID) { $0 != currentRoundKey }
    }
}

/// The messages the server listed per field in `details.errors` of a refusal.
func refusalFieldErrors(_ error: APIError) -> [String: [String]] {
    var fieldErrors: [String: [String]] = [:]
    if case .object(let fields)? = error.details["errors"] {
        for (field, value) in fields {
            if case .array(let messages) = value { fieldErrors[field] = messages.compactMap(\.stringValue) }
        }
    }
    return fieldErrors
}
