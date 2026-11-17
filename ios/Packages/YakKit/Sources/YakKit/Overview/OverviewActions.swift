import Foundation

#if canImport(os)
    import os
#endif

/// The action name and the failure's type or code, never a title, brief, name or message.
private func logFailure(_ action: String, _ failure: APIFailure) {
    #if canImport(os)
        let reason: String
        switch failure {
        case .server(let error): reason = "server \(error.code)"
        case .network(let code): reason = "network \(code.rawValue)"
        default: reason = "\(failure)"
        }
        Logger(subsystem: "YakKit", category: "OverviewActions")
            .error("\(action) failed: \(reason, privacy: .public)")
    #endif
}

public enum OverviewChange: Equatable, Sendable {
    case stack(threadID: String)
    case environment(threadID: String)
    case share(threadID: String)
}

public enum SubThreadAddOutcome: Equatable, Sendable {
    case added(SubThreadCreated)
    /// `.refused` keeps the server's code, message and field errors.
    case notAdded(ThreadActionOutcome)
    /// 409 `sub_thread_cannot_spawn`: the thread is a sub-thread. `parentID` is the server's `details.parentId`.
    case cannotSpawn(message: String, parentID: String?)
}

/// Every action the Overview sends. Each is safe to repeat: a double tap sends once, a busy thread is asked
/// again, and an add keeps its `send_key` on disk until the answer is known.
public actor OverviewActions {
    public static let busyRetryWaits: [Duration] = [.seconds(1), .seconds(2)]
    private static let maximumStacks = 20
    private static let definiteRefusals: Set<Int> = [403, 404, 409, 422]

    private let client: APIClient
    private let cache: Cache
    private let clock: any YakClock
    private let makeID: @Sendable () -> UUID

    private var subscribers: [Int: AsyncStream<OverviewChange>.Continuation] = [:]
    private var nextSubscriber = 0

    private var stacks: [String: (stack: ThreadStack, fetchedAt: Date)] = [:]
    /// Thread ids of `stacks`, oldest first.
    private var stackOrder: [String] = []
    private typealias StackAnswer = (stack: ThreadStack, fetchedAt: Date)
    /// A finished lookup clears its slot only while the slot still holds that lookup's token.
    private var stackLookups: [String: (token: Int, task: Task<Result<StackAnswer, APIFailure>, Never>)] = [:]
    private var nextStackLookupToken = 0
    /// Counts the times the stacks were dropped, so a lookup that was asked before a change never stores its answer.
    private var stackGeneration = 0

    private var addTasks: [String: Task<SubThreadAddOutcome, Never>] = [:]
    private var outcomeTasks: [String: Task<ThreadActionOutcome, Never>] = [:]
    private var limitTasks: [String: Task<(outcome: ThreadActionOutcome, limit: StackLimit?), Never>] = [:]

    public init(client: APIClient, cache: Cache, clock: any YakClock, makeID: @escaping @Sendable () -> UUID) {
        self.client = client
        self.cache = cache
        self.clock = clock
        self.makeID = makeID
    }

    // MARK: Changes

    /// Every change after the call; nothing is replayed.
    public func changes() -> AsyncStream<OverviewChange> {
        let (stream, continuation) = AsyncStream.makeStream(of: OverviewChange.self, bufferingPolicy: .unbounded)
        nextSubscriber += 1
        let identifier = nextSubscriber
        subscribers[identifier] = continuation
        continuation.onTermination = { [weak self] _ in
            Task { await self?.removeSubscriber(identifier) }
        }
        return stream
    }

    private func removeSubscriber(_ identifier: Int) {
        subscribers[identifier] = nil
    }

    private func emit(_ change: OverviewChange) {
        for continuation in subscribers.values { continuation.yield(change) }
    }

    // MARK: Stacks

    /// The stack, from memory when it was fetched less than `maxAge` ago. Callers asking at the same time share one request.
    public func stack(_ threadID: String, maxAge: Duration = SubThreadActions.freshFor) async throws(APIFailure)
        -> (stack: ThreadStack, fetchedAt: Date)
    {
        if let cached = stacks[threadID], clock.now().timeIntervalSince(cached.fetchedAt) < Self.seconds(maxAge) {
            return cached
        }
        let lookup: Task<Result<(stack: ThreadStack, fetchedAt: Date), APIFailure>, Never>
        if let running = stackLookups[threadID] {
            lookup = running.task
        } else {
            let generation = stackGeneration
            nextStackLookupToken += 1
            let token = nextStackLookupToken
            lookup = Task {
                let result = await self.send(.stack(threadID))
                if self.stackLookups[threadID]?.token == token { self.stackLookups[threadID] = nil }
                switch result {
                case .success(let stack):
                    let answer = (stack: stack, fetchedAt: self.clock.now())
                    if generation == self.stackGeneration { self.remember(answer, for: threadID) }
                    return .success(answer)
                case .failure(let failure):
                    logFailure("stack", failure)
                    return .failure(failure)
                }
            }
            stackLookups[threadID] = (token, lookup)
        }
        switch await lookup.value {
        case .success(let answer): return answer
        case .failure(let failure): throw failure
        }
    }

    public func cachedStack(_ threadID: String) -> (stack: ThreadStack, fetchedAt: Date)? {
        stacks[threadID]
    }

    private func remember(_ answer: (stack: ThreadStack, fetchedAt: Date), for threadID: String) {
        stacks[threadID] = answer
        stackOrder.removeAll { $0 == threadID }
        stackOrder.append(threadID)
        while stackOrder.count > Self.maximumStacks {
            stacks[stackOrder.removeFirst()] = nil
        }
    }

    /// Drops the stack of `threadID` and, for a child, every cached stack that lists it.
    private func dropStacks(for threadID: String, isChild: Bool) {
        stackGeneration += 1
        var dropped: Set<String> = [threadID]
        if isChild {
            for (id, entry) in stacks where entry.stack.children.contains(where: { $0.id == threadID }) {
                dropped.insert(id)
            }
        }
        for id in dropped { stacks[id] = nil }
        // A running lookup may list the child, and its parent is not known yet, so a child drops every lookup.
        if isChild { stackLookups.removeAll() } else { stackLookups[threadID] = nil }
        stackOrder.removeAll { dropped.contains($0) }
    }

    static func seconds(_ duration: Duration) -> TimeInterval {
        let parts = duration.components
        return TimeInterval(parts.seconds) + TimeInterval(parts.attoseconds) / 1e18
    }

    // MARK: Sending

    /// Sends the request, and for a non-GET again after each of `busyRetryWaits` while the server answers `503 unavailable`. A GET is already retried by the client.
    private func send<Response: Decodable & Sendable>(_ endpoint: Endpoint<Response>) async
        -> Result<Response, APIFailure>
    {
        var attempt = 0
        while true {
            do {
                return .success(try await client.send(endpoint))
            } catch {
                guard endpoint.method != .get, case .server(let refusal) = error, refusal.status == 503,
                    refusal.code == "unavailable", attempt < Self.busyRetryWaits.count
                else { return .failure(error) }
                do { try await clock.sleep(for: Self.busyRetryWaits[attempt]) } catch {
                    return .failure(.network(.cancelled))
                }
                attempt += 1
            }
        }
    }

    /// A busy thread that stayed busy is a refusal with the server's sentence, not an offline state.
    private func outcome(for failure: APIFailure) -> ThreadActionOutcome {
        if case .server(let error) = failure, error.status == 503, error.code == "unavailable" {
            return .refused(code: "unavailable", message: error.message, fieldErrors: [:])
        }
        return ThreadActionOutcome(failure)
    }

    /// A second call with the same key while one runs waits for it and answers its outcome.
    private func perform(_ key: String, _ work: @escaping @Sendable (OverviewActions) async -> ThreadActionOutcome)
        async -> ThreadActionOutcome
    {
        if let running = outcomeTasks[key] { return await running.value }
        let task = Task { () -> ThreadActionOutcome in
            let outcome = await work(self)
            self.outcomeTasks[key] = nil
            return outcome
        }
        outcomeTasks[key] = task
        return await task.value
    }

    /// Sends a status action. A success drops the stacks it changes and announces `change`.
    private func settle(
        _ name: String, _ endpoint: Endpoint<StatusResponse>, stackOf threadID: String, isChild: Bool = false,
        change: OverviewChange, alsoDoneOn code: String? = nil
    ) async -> ThreadActionOutcome {
        switch await send(endpoint) {
        case .success:
            return finish(threadID: threadID, isChild: isChild, change: change)
        case .failure(let failure):
            if let code, case .server(let error) = failure, error.status == 409, error.code == code {
                return finish(threadID: threadID, isChild: isChild, change: change)
            }
            logFailure(name, failure)
            return outcome(for: failure)
        }
    }

    private func finish(threadID: String, isChild: Bool, change: OverviewChange) -> ThreadActionOutcome {
        if case .stack = change { dropStacks(for: threadID, isChild: isChild) }
        emit(change)
        return .done
    }

    // MARK: Actions

    public func stopStack(_ threadID: String) async -> ThreadActionOutcome {
        await perform("stop:\(threadID)") {
            await $0.settle("stop", .stopStack(threadID), stackOf: threadID, change: .stack(threadID: threadID))
        }
    }

    public func takeOver(_ childID: String) async -> ThreadActionOutcome {
        await perform("takeOver:\(childID)") {
            await $0.settle(
                "takeOver", .takeOver(childID), stackOf: childID, isChild: true, change: .stack(threadID: childID))
        }
    }

    public func split(_ childID: String) async -> ThreadActionOutcome {
        await perform("split:\(childID)") {
            await $0.settle(
                "split", .splitSubThread(childID), stackOf: childID, isChild: true,
                change: .stack(threadID: childID))
        }
    }

    public func drop(_ childID: String) async -> ThreadActionOutcome {
        await perform("drop:\(childID)") {
            await $0.settle(
                "drop", .dropSubThread(childID), stackOf: childID, isChild: true, change: .stack(threadID: childID))
        }
    }

    /// A retry answered `409 nothing_to_retry` is done: the setup already runs or finished, and the reload shows which.
    public func retryRepositorySetup(_ threadID: String, repository: LenientID) async -> ThreadActionOutcome {
        await perform("retry:\(threadID):\(repository.value)") {
            await $0.settle(
                "retryRepositorySetup", .retryRepositorySetup(threadID, repository: repository),
                stackOf: threadID, change: .environment(threadID: threadID), alsoDoneOn: "nothing_to_retry")
        }
    }

    /// Sends the value as given; the stepper clamps it. A `422` arrives under `limit` in `.refused`.
    public func setAwakeLimit(_ threadID: String, limit: Int) async -> (
        outcome: ThreadActionOutcome, limit: StackLimit?
    ) {
        let key = "limit:\(threadID):\(limit)"
        if let running = limitTasks[key] { return await running.value }
        let task = Task { () -> (outcome: ThreadActionOutcome, limit: StackLimit?) in
            let answer: (outcome: ThreadActionOutcome, limit: StackLimit?)
            switch await self.send(.setAwakeLimit(threadID, limit: limit)) {
            case .success(let updated):
                answer = (
                    self.finish(threadID: threadID, isChild: false, change: .stack(threadID: threadID)),
                    updated.limit
                )
            case .failure(let failure):
                logFailure("setAwakeLimit", failure)
                answer = (self.outcome(for: failure), nil)
            }
            self.limitTasks[key] = nil
            return answer
        }
        limitTasks[key] = task
        return await task.value
    }

    // MARK: Sharing

    public func share(_ threadID: String) async throws(APIFailure) -> ThreadShare {
        switch await send(.share(threadID)) {
        case .success(let share): return share
        case .failure(let failure):
            logFailure("share", failure)
            throw failure
        }
    }

    public func updateShare(_ threadID: String, mode: ShareMode, userIDs: [Int]) async -> ThreadActionOutcome {
        let key = "share:\(threadID):\(mode.rawValue):\(userIDs.map(String.init).joined(separator: ","))"
        return await perform(key) { actions in
            switch await actions.send(.updateShare(threadID, mode: mode, userIDs: userIDs)) {
            case .success:
                return await actions.finish(threadID: threadID, isChild: false, change: .share(threadID: threadID))
            case .failure(let failure):
                logFailure("updateShare", failure)
                return await actions.outcome(for: failure)
            }
        }
    }

    // MARK: Saved add draft

    public func subThreadDraft(parentID: String) async -> SubThreadDraft? {
        await cache.subThreadDraft(parentID: parentID)
    }

    /// Writes the text, kind and model and keeps the saved key and mark. While the saved draft is in flight it
    /// writes nothing. Nil or an empty draft removes it.
    public func saveSubThreadDraft(_ draft: SubThreadDraft?, parentID: String) async {
        if addTasks[parentID] != nil { return }
        await cache.mergeSubThreadDraft(draft, parentID: parentID)
    }

    // MARK: Add

    private static let notSaved = SubThreadAddOutcome.notAdded(
        .failed(.server(code: "not_saved", message: "The draft could not be saved. Try again.")))

    public func addSubThread(parentID: String, draft: SubThreadDraft) async -> SubThreadAddOutcome {
        if let running = addTasks[parentID] { return await running.value }
        let task = Task { () -> SubThreadAddOutcome in
            let outcome = await self.performAdd(parentID: parentID, draft: draft)
            self.addTasks[parentID] = nil
            return outcome
        }
        addTasks[parentID] = task
        return await task.value
    }

    private func performAdd(parentID: String, draft: SubThreadDraft) async -> SubThreadAddOutcome {
        var sending: SubThreadDraft
        if let saved = await cache.subThreadDraft(parentID: parentID), saved.sendKeyIsInFlight, saved.sendKey != nil {
            sending = saved
        } else {
            if let problem = draft.problem {
                return .notAdded(.refused(code: "validation_failed", message: problem.sentence, fieldErrors: [:]))
            }
            sending = draft
            sending.sendKey = draft.sendKey ?? makeID()
            sending.sendKeyIsInFlight = true
            guard await cache.saveSubThreadDraft(sending, parentID: parentID) else { return Self.notSaved }
        }
        var hasReplacedKey = false
        while true {
            guard let key = sending.sendKey else { return Self.notSaved }
            let endpoint = Endpoint<SubThreadCreated>.addSubThread(
                parentID, name: sending.trimmedName, brief: sending.trimmedBrief, kind: sending.kind,
                model: sending.model, sendKey: key)
            switch await send(endpoint) {
            case .success(let created):
                await cache.saveSubThreadDraft(nil, parentID: parentID)
                dropStacks(for: parentID, isChild: false)
                emit(.stack(threadID: parentID))
                return .added(created)
            case .failure(let failure):
                logFailure("addSubThread", failure)
                guard case .server(let error) = failure, Self.definiteRefusals.contains(error.status) else {
                    // The outcome is unknown: the key and the mark stay, so a repeat sends the same request.
                    return .notAdded(outcome(for: failure))
                }
                if error.status == 422, !hasReplacedKey, case .object(let fields)? = error.details["errors"],
                    fields["send_key"] != nil
                {
                    hasReplacedKey = true
                    sending.sendKey = makeID()
                    guard await cache.saveSubThreadDraft(sending, parentID: parentID) else { return Self.notSaved }
                    continue
                }
                // Nothing was created: the text stays and the next add makes a new key.
                sending.sendKey = nil
                sending.sendKeyIsInFlight = false
                await cache.saveSubThreadDraft(sending, parentID: parentID)
                if error.status == 409, error.code == "sub_thread_cannot_spawn" {
                    return .cannotSpawn(message: error.message, parentID: error.details["parentId"]?.stringValue)
                }
                return .notAdded(outcome(for: failure))
            }
        }
    }
}
