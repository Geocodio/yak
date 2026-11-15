import Foundation

/// Unsent messages and new threads. Each queue sends only its head, repeats a lost send with the
/// same `send_key`, and holds everything behind a refused head until the person acts on it.
public actor Outbox {
    private let instance: UUID
    private let client: APIClient
    private let files: any FileStore
    private let clock: any YakClock
    private let makeID: @Sendable () -> UUID

    private var stored: [OutboxItem] = []
    private var pauseState: OutboxPause?
    private var slowTimers: [UUID: Task<Void, Never>] = [:]
    private var retryTimers: [UUID: Task<Void, Never>] = [:]
    private let changeBroadcast = Broadcast<[OutboxItem]>([])
    private var deliveryReceivers: [Int: AsyncStream<OutboxDelivery>.Continuation] = [:]
    private var nextReceiver = 0

    public init(
        instance: UUID, client: APIClient, files: any FileStore, clock: any YakClock,
        makeID: @escaping @Sendable () -> UUID
    ) {
        self.instance = instance
        self.client = client
        self.files = files
        self.clock = clock
        self.makeID = makeID
    }

    // MARK: Reading

    /// In `createdAt` order.
    public func items() -> [OutboxItem] { stored }

    public func changes() -> AsyncStream<[OutboxItem]> { changeBroadcast.stream() }

    public func deliveries() -> AsyncStream<OutboxDelivery> {
        let (stream, continuation) = AsyncStream.makeStream(of: OutboxDelivery.self)
        nextReceiver += 1
        let identifier = nextReceiver
        deliveryReceivers[identifier] = continuation
        continuation.onTermination = { [weak self] _ in
            Task { await self?.dropReceiver(identifier) }
        }
        return stream
    }

    public func pauseReason() -> OutboxPause? { pauseState }

    // MARK: Changing

    public func enqueue(_ draft: OutboxDraft) async throws(FileStoreError) -> OutboxItem {
        let item = OutboxItem(
            id: makeID(), target: draft.target, text: draft.text, model: draft.model, delivery: draft.delivery,
            attachments: draft.attachments, createdAt: clock.now())
        stored.append(item)
        publish()
        pump()
        return item
    }

    public func edit(_ id: UUID, text: String) throws(OutboxEditProblem) {
        guard let index = stored.firstIndex(where: { $0.id == id }) else { throw .notFound }
        switch stored[index].state {
        case .sending, .slow: throw .alreadySending
        case .waiting where stored[index].attemptCount > 0: throw .mayAlreadyBeSent
        default: break
        }
        stored[index].text = text
        publish()
    }

    public func remove(_ id: UUID) throws(OutboxEditProblem) -> OutboxItem {
        guard let index = stored.firstIndex(where: { $0.id == id }) else { throw .notFound }
        switch stored[index].state {
        case .sending, .slow: throw .alreadySending
        default: break
        }
        let item = stored.remove(at: index)
        retryTimers.removeValue(forKey: id)?.cancel()
        publish()
        pump()
        return item
    }

    /// A refused or waiting item is sent again now.
    public func retry(_ id: UUID) {
        guard let index = stored.firstIndex(where: { $0.id == id }) else { return }
        switch stored[index].state {
        case .refused, .waiting:
            retryTimers.removeValue(forKey: id)?.cancel()
            stored[index].state = .queued
            publish()
            pump()
        default: break
        }
    }

    /// Every waiting item is sent now instead of after its delay.
    public func networkReturned() {
        makeWaitingItemsQueued()
        pump()
    }

    public func pause(_ reason: OutboxPause) {
        pauseState = reason
        for timer in retryTimers.values { timer.cancel() }
        retryTimers = [:]
    }

    public func resume() {
        pauseState = nil
        makeWaitingItemsQueued()
        pump()
    }

    // MARK: Sending

    private func makeWaitingItemsQueued() {
        for index in stored.indices where stored[index].state == .waiting {
            retryTimers.removeValue(forKey: stored[index].id)?.cancel()
            stored[index].state = .queued
        }
        publish()
    }

    /// Starts the head of every queue that is ready.
    private func pump() {
        guard pauseState == nil else { return }
        var seen: Set<String> = []
        for item in stored where seen.insert(item.queueKey).inserted && item.state == .queued {
            start(item.id)
        }
    }

    private func start(_ id: UUID) {
        guard let index = stored.firstIndex(where: { $0.id == id }) else { return }
        stored[index].state = .sending
        stored[index].attemptCount += 1
        let attempt = stored[index].attemptCount
        publish()
        slowTimers[id] = Task {
            do { try await clock.sleep(for: OutboxRetryPolicy.slowAfter) } catch { return }
            markSlow(id, attempt: attempt)
        }
        Task { await perform(id, attempt: attempt) }
    }

    private func markSlow(_ id: UUID, attempt: Int) {
        guard let index = stored.firstIndex(where: { $0.id == id }),
            stored[index].attemptCount == attempt, stored[index].state == .sending
        else { return }
        stored[index].state = .slow
        publish()
    }

    private func perform(_ id: UUID, attempt: Int) async {
        guard let item = stored.first(where: { $0.id == id }) else { return }
        let outcome: Result<OutboxDelivery, APIFailure>
        do throws(APIFailure) {
            outcome = .success(try await deliver(item))
        } catch {
            outcome = .failure(error)
        }
        slowTimers.removeValue(forKey: id)?.cancel()
        guard let index = stored.firstIndex(where: { $0.id == id }), stored[index].attemptCount == attempt else {
            return
        }
        switch outcome {
        case .success(let delivery):
            stored.remove(at: index)
            publish()
            for receiver in deliveryReceivers.values { receiver.yield(delivery) }
        case .failure(let failure):
            apply(OutboxRetryPolicy.decision(for: failure, attempt: attempt), toItemAt: index, attempt: attempt)
        }
        pump()
    }

    private func apply(_ decision: OutboxDecision, toItemAt index: Int, attempt: Int) {
        let id = stored[index].id
        switch decision {
        case .retry(let delay):
            stored[index].state = .waiting
            retryTimers[id] = Task {
                do { try await clock.sleep(for: delay) } catch { return }
                wake(id, attempt: attempt)
            }
        case .refuse(let refusal):
            stored[index].state = .refused(refusal)
        case .pause(let reason):
            stored[index].state = .queued
            pause(reason)
        case .stayQueued:
            stored[index].state = .queued
            stored[index].attemptCount -= 1
        }
        publish()
    }

    private func wake(_ id: UUID, attempt: Int) {
        retryTimers[id] = nil
        guard let index = stored.firstIndex(where: { $0.id == id }),
            stored[index].attemptCount == attempt, stored[index].state == .waiting
        else { return }
        stored[index].state = .queued
        publish()
        pump()
    }

    private func deliver(_ item: OutboxItem) async throws(APIFailure) -> OutboxDelivery {
        switch item.target {
        case .thread(let threadID):
            let result = try await client.send(
                .sendMessage(
                    threadID, text: item.text, model: item.model, delivery: item.delivery, sendKey: item.id,
                    attachments: [], references: []))
            return OutboxDelivery(
                sendKey: item.id, target: item.target, status: result.status, messageUUID: result.uuid,
                threadID: result.threadId ?? threadID, text: item.text, sentAt: clock.now())
        case .newThread(let draft):
            let withModel = NewThreadDraft(
                repo: draft.repo, mode: draft.mode, repositories: draft.repositories,
                claudeAccountId: draft.claudeAccountId, model: item.model ?? draft.model)
            let created = try await client.send(
                .startThread(withModel, description: item.text, sendKey: item.id, attachments: [], references: []))
            return OutboxDelivery(
                sendKey: item.id, target: item.target, status: .sent, messageUUID: nil, threadID: created.id,
                text: item.text, sentAt: clock.now())
        }
    }

    private func publish() { changeBroadcast.send(stored) }

    private func dropReceiver(_ identifier: Int) { deliveryReceivers[identifier] = nil }
}
