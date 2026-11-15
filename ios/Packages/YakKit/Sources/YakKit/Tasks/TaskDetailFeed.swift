import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "TaskDetailFeed").error("\(message, privacy: .public)")
    #endif
}

/// One task: the saved copy first, then the server's, polled while the screen is visible. A decision or an action is
/// sent once and never resent; after any answer the task is loaded again.
public actor TaskDetailFeed {
    public typealias Snapshot = (load: ThreadLoadState, task: AppTask?, problem: ThreadProblem?, isOffline: Bool)

    private let taskID: String
    private let client: APIClient
    private let cache: Cache
    private let clock: any YakClock
    private let broadcast: Broadcast<Snapshot>

    private var load: ThreadLoadState = .loading
    private var task: AppTask?
    private var problem: ThreadProblem?
    private var isOffline = false
    private var hasOpened = false
    private var hasFetched = false
    private var isVisible = false
    private var isClosed = false
    /// Counts the loads started, so an answer to an overtaken load is dropped.
    private var generation = 0
    private var pollTask: Task<Void, Never>?
    private var slowTask: Task<Void, Never>?
    private var inFlight: (key: String, task: Task<TaskActionOutcome, Never>)?
    /// Calls that joined a request already in flight.
    private(set) var joinedCallCount = 0

    public init(taskID: String, client: APIClient, cache: Cache, clock: any YakClock) {
        self.taskID = taskID
        self.client = client
        self.cache = cache
        self.clock = clock
        broadcast = Broadcast((load: .loading, task: nil, problem: nil, isOffline: false))
    }

    public func snapshots() -> AsyncStream<Snapshot> {
        broadcast.stream()
    }

    /// The id the copies are stored under: the root task's, once known.
    private var storedID: String { task?.task.id ?? taskID }

    // MARK: Lifecycle

    /// Shows the saved copy at once, then asks the server. Repeating it changes nothing.
    public func open() async {
        guard !hasOpened, !isClosed else { return }
        hasOpened = true
        if let saved = await cache.task(taskID) {
            task = saved
            load = .ready
        }
        guard !isClosed else { return }
        publish()
        await fetch()
    }

    /// Visible: loads every `pollInterval` milliseconds. Hidden: cancels the polling.
    public func setVisible(_ visible: Bool) async {
        guard visible != isVisible, !isClosed else { return }
        isVisible = visible
        guard visible else {
            pollTask?.cancel()
            pollTask = nil
            return
        }
        startPolling()
    }

    public func close() async {
        guard !isClosed else { return }
        isClosed = true
        pollTask?.cancel()
        slowTask?.cancel()
        inFlight?.task.cancel()
        pollTask = nil
        slowTask = nil
        broadcast.finish()
    }

    /// Loads now and waits for the answer.
    public func reload() async {
        guard !isClosed else { return }
        if task == nil, case .failed(let failure) = load, failure.isRetryable {
            load = .loading
            publish()
        }
        await fetch()
    }

    // MARK: Actions

    /// Sends the action once. A second call for the same action joins the one in flight; a different one waits for it.
    public func perform(_ action: TaskAction) async -> TaskActionOutcome {
        await send(key: String(describing: action)) { feed in
            let id = await feed.storedID
            return await feed.post(.taskAction(id, action))
        }
    }

    /// Sends the saved draft of the open round.
    public func answerQuestions() async -> TaskActionOutcome {
        await send(key: "answers") { feed in
            guard let draft = await feed.questionDraft() else {
                return .refused(
                    code: "no_open_question", message: "There is no question to answer.", fieldErrors: [:],
                    connectURL: nil)
            }
            let id = await feed.storedID
            let outcome = await feed.post(.answerTaskQuestions(id, answers: draft.answers, note: draft.note))
            if case .done = outcome { await feed.discardQuestionDraft() }
            return outcome
        }
    }

    /// The saved answers of the round that is open now; nil for any other round.
    public func questionDraft() async -> QuestionDraft? {
        guard let round = task?.questionRound, let draft = await cache.taskQuestionDraft(storedID),
            draft.roundKey == round.roundKey
        else { return nil }
        return draft
    }

    public func saveQuestionDraft(_ draft: QuestionDraft) async {
        await cache.saveTaskQuestionDraft(draft, id: storedID)
    }

    private func discardQuestionDraft() async {
        await cache.saveTaskQuestionDraft(nil, id: storedID)
    }

    private func send(
        key: String, _ work: @escaping @Sendable (TaskDetailFeed) async -> TaskActionOutcome
    ) async -> TaskActionOutcome {
        while let running = inFlight {
            if running.key == key {
                joinedCallCount += 1
                return await running.task.value
            }
            _ = await running.task.value
        }
        let running = Task {
            let outcome = await work(self)
            await self.fetch()
            self.clearInFlight(key)
            return outcome
        }
        inFlight = (key, running)
        return await running.value
    }

    private func clearInFlight(_ key: String) {
        if inFlight?.key == key { inFlight = nil }
    }

    private func post(_ endpoint: Endpoint<StatusResponse>) async -> TaskActionOutcome {
        do {
            return .done(try await client.send(endpoint).status)
        } catch {
            let outcome = TaskActionOutcome(error)
            if case .refused(let code, _, _, _) = outcome { logFailure("task action refused: \(code)") }
            return outcome
        }
    }

    // MARK: Loading

    private func startPolling() {
        pollTask?.cancel()
        pollTask = Task {
            if !self.hasFetched { await self.fetch() }
            while !Task.isCancelled, self.shouldKeepPolling {
                let interval = min(max(self.task?.pollInterval ?? 5000, 3000), 60000)
                do { try await self.clock.sleep(for: .milliseconds(interval)) } catch { return }
                guard !Task.isCancelled else { return }
                await self.fetch()
            }
        }
    }

    private var shouldKeepPolling: Bool {
        if case .failed(let failure) = load, !failure.isRetryable { return false }
        return isVisible && !isClosed
    }

    private func startSlowTimer() {
        slowTask?.cancel()
        slowTask = Task {
            do { try await self.clock.sleep(for: ThreadEngine.slowAfter) } catch { return }
            guard self.load == .loading else { return }
            self.load = .slow
            self.publish()
        }
    }

    private func stopSlowTimer() {
        slowTask?.cancel()
        slowTask = nil
    }

    private func fetch() async {
        generation += 1
        let current = generation
        hasFetched = true
        if task == nil, load == .loading { startSlowTimer() }
        let fetched: AppTask
        do {
            fetched = try await client.send(.task(taskID))
        } catch {
            guard !Task.isCancelled, !isClosed, current == generation, error != .network(.cancelled) else { return }
            stopSlowTimer()
            await handleFailure(error)
            return
        }
        guard !Task.isCancelled, !isClosed, current == generation else { return }
        stopSlowTimer()
        task = fetched
        load = .ready
        problem = nil
        isOffline = false
        publish()
        let id = fetched.task.id
        await cache.saveTask(fetched, id: id)
        if let draft = await cache.taskQuestionDraft(id), draft.roundKey != fetched.questionRound?.roundKey {
            await cache.saveTaskQuestionDraft(nil, id: id)
        }
    }

    private func handleFailure(_ failure: APIFailure) async {
        let reason = ThreadProblem(failure)
        logFailure("task failed: \(reason.logName)")
        if case .server(let error) = failure, error.status == 404 || error.status == 403 {
            let rootID = task?.task.id
            task = nil
            problem = nil
            isOffline = false
            load = .failed(.notFound)
            publish()
            await cache.removeTask(taskID)
            if let rootID, rootID != taskID { await cache.removeTask(rootID) }
            return
        }
        if task == nil {
            load = .failed(reason)
        } else if reason == .offline {
            isOffline = true
        } else {
            problem = reason
        }
        publish()
    }

    private func publish() {
        broadcast.send((load: load, task: task, problem: problem, isOffline: isOffline))
    }
}
