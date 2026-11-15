import Foundation

#if canImport(os)
    import os
#endif

/// Logs a failure type (never stored content). Linux has no `os.Logger`, so it is a no-op there.
private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "Outbox").error("\(message, privacy: .public)")
    #endif
}

/// Unsent messages and new threads. Each queue sends only its head, repeats a lost send with the
/// same `send_key`, and holds everything behind a refused head until the person acts on it.
public actor Outbox {
    private let instance: UUID
    private let client: APIClient
    private let files: any FileStore
    private let clock: any YakClock
    private let makeID: @Sendable () -> UUID

    private var stored: [OutboxItem] = []
    private var hasLoadedList = false
    private var pauseState: OutboxPause?
    private var slowTimers: [UUID: Task<Void, Never>] = [:]
    private var retryTimers: [UUID: Task<Void, Never>] = [:]
    private var sendTasks: [UUID: Task<Void, Never>] = [:]
    private var isShutDown = false
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
        guard !isShutDown else { throw .unreadable }
        let item = OutboxItem(
            id: makeID(), target: draft.target, text: draft.text, model: draft.model, delivery: draft.delivery,
            attachments: draft.attachments, createdAt: clock.now())
        stored.append(item)
        do {
            try persist()
        } catch {
            stored.removeAll { $0.id == item.id }
            throw error
        }
        changeBroadcast.send(stored)
        pump()
        return item
    }

    // MARK: Persistence

    /// Reads `items.json`, takes in what other processes left, and starts sending. An item that was
    /// in flight when the app stopped is queued again and goes out with the same `send_key`.
    public func load() {
        guard !isShutDown else { return }
        loadSavedList()
        importIncoming()
    }

    /// Merges the saved list into memory once. Nothing is written until this has succeeded, so a change
    /// made before `load()` cannot overwrite what the last run saved. A list that does not decode is
    /// kept as `items.unreadable.json` before anything new is written over it.
    private func loadSavedList() {
        guard !hasLoadedList else { return }
        do {
            if let data = try files.read(OutboxInbox.itemsPath(instance)) {
                do {
                    var saved = try JSONDecoder().decode([OutboxItem].self, from: data)
                    for index in saved.indices {
                        switch saved[index].state {
                        case .sending, .slow, .waiting: saved[index].state = .queued
                        default: break
                        }
                    }
                    let known = Set(stored.map(\.id))
                    stored += saved.filter { !known.contains($0.id) }
                    sortByCreation()
                } catch {
                    logFailure("outbox list does not decode: \(type(of: error))")
                    try files.write(
                        data, to: OutboxInbox.unreadableItemsPath(instance), protection: .untilFirstUnlock)
                }
            }
            hasLoadedList = true
        } catch {
            logFailure("outbox list unreadable: \(type(of: error))")
        }
    }

    /// Moves every inbox file into the list, in `createdAt` order, then removes the files.
    public func importIncoming() {
        guard !isShutDown else { return }
        let folder = OutboxInbox.folder(instance)
        let names: [String]
        do { names = try files.list(folder) } catch {
            logFailure("outbox inbox unreadable: \(type(of: error))")
            publish()
            pump()
            return
        }
        var taken: [String] = []
        for name in names where name.hasSuffix(".json") {
            let path = "\(folder)/\(name)"
            do {
                guard let data = try files.read(path) else { continue }
                var item = try JSONDecoder().decode(OutboxItem.self, from: data)
                item.state = .queued
                if !stored.contains(where: { $0.id == item.id }) { stored.append(item) }
                taken.append(path)
            } catch is DecodingError {
                logFailure("outbox inbox file dropped: not an item")
                removeFile(path)
            } catch {
                logFailure("outbox inbox file unreadable: \(type(of: error))")
            }
        }
        sortByCreation()
        // The list is saved before the inbox files go, so a crash in between imports them once.
        // When saving fails the files stay for the next import.
        do {
            try persist()
            taken.forEach(removeFile)
        } catch {
            logFailure("outbox list not saved, inbox kept: \(type(of: error))")
        }
        changeBroadcast.send(stored)
        pump()
    }

    /// Everything this instance keeps for sending: the list, the inbox and the attachment files.
    public func eraseAll() {
        guard !isShutDown else { return }
        for timer in slowTimers.values { timer.cancel() }
        for timer in retryTimers.values { timer.cancel() }
        slowTimers = [:]
        retryTimers = [:]
        stored = []
        removeFile(InstanceFolders.outbox(instance))
        changeBroadcast.send([])
    }

    private func sortByCreation() {
        // Swift's sort is not stable, so the position breaks ties.
        stored = stored.enumerated().sorted { ($0.element.createdAt, $0.offset) < ($1.element.createdAt, $1.offset) }
            .map(\.element)
    }

    private func persist() throws(FileStoreError) {
        loadSavedList()
        guard hasLoadedList else { throw .unreadable }
        let saved = stored.map { item -> OutboxItem in
            var copy = item
            if copy.state == .sending || copy.state == .slow { copy.state = .queued }
            return copy
        }
        let data: Data
        do { data = try JSONEncoder().encode(saved) } catch { throw .unreadable }
        try files.write(data, to: OutboxInbox.itemsPath(instance), protection: .untilFirstUnlock)
    }

    private func removeFile(_ path: String) {
        do { try files.remove(path) } catch { logFailure("outbox file not removed: \(type(of: error))") }
    }

    private func removeAttachments(of item: OutboxItem) {
        if !item.attachments.isEmpty { removeFile(OutboxInbox.attachmentFolder(instance: instance, item: item.id)) }
    }

    public func edit(_ id: UUID, text: String) throws(OutboxEditProblem) {
        guard !isShutDown, let index = stored.firstIndex(where: { $0.id == id }) else { throw .notFound }
        switch stored[index].state {
        case .sending, .slow: throw .alreadySending
        case .waiting where stored[index].attemptCount > 0: throw .mayAlreadyBeSent
        default: break
        }
        stored[index].text = text
        publish()
    }

    public func remove(_ id: UUID) throws(OutboxEditProblem) -> OutboxItem {
        guard !isShutDown, let index = stored.firstIndex(where: { $0.id == id }) else { throw .notFound }
        switch stored[index].state {
        case .sending, .slow: throw .alreadySending
        default: break
        }
        let item = stored.remove(at: index)
        retryTimers.removeValue(forKey: id)?.cancel()
        publish()
        removeAttachments(of: item)
        pump()
        return item
    }

    /// A refused or waiting item is sent again now.
    public func retry(_ id: UUID) {
        guard !isShutDown else { return }
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
        guard !isShutDown else { return }
        makeWaitingItemsQueued()
        pump()
    }

    /// Cancels every timer and in-flight send and ignores later triggers. Items stay on disk and go out
    /// again, with the same `send_key`, from the next `Outbox` that loads them.
    func shutDown() {
        isShutDown = true
        for timer in slowTimers.values { timer.cancel() }
        for timer in retryTimers.values { timer.cancel() }
        for task in sendTasks.values { task.cancel() }
        slowTimers = [:]
        retryTimers = [:]
        sendTasks = [:]
    }

    public func pause(_ reason: OutboxPause) {
        pauseState = reason
        for timer in retryTimers.values { timer.cancel() }
        retryTimers = [:]
    }

    public func resume() {
        guard !isShutDown else { return }
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
        guard pauseState == nil, !isShutDown else { return }
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
        sendTasks[id] = Task { await perform(id, attempt: attempt) }
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
        let (parts, problem) = readAttachments(of: item)
        if let decision = problem {
            slowTimers.removeValue(forKey: id)?.cancel()
            guard let index = stored.firstIndex(where: { $0.id == id }), stored[index].attemptCount == attempt
            else { return }
            apply(decision, toItemAt: index, attempt: attempt)
            pump()
            return
        }
        do throws(APIFailure) {
            outcome = .success(try await deliver(item, attachments: parts))
        } catch {
            outcome = .failure(error)
        }
        guard !isShutDown else { return }
        slowTimers.removeValue(forKey: id)?.cancel()
        sendTasks[id] = nil
        guard let index = stored.firstIndex(where: { $0.id == id }), stored[index].attemptCount == attempt else {
            return
        }
        switch outcome {
        case .success(let delivery):
            for receiver in deliveryReceivers.values { receiver.yield(delivery) }
            let item = stored.remove(at: index)
            publish()
            removeAttachments(of: item)
        case .failure(let failure):
            let decision = OutboxRetryPolicy.decision(for: failure, attempt: attempt)
            apply(decision, toItemAt: index, attempt: attempt)
            // A cancelled attempt waits for the next trigger; pumping here would resend it in a tight loop.
            if decision == .stayQueued { return }
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

    /// A file that is missing refuses the item; one that cannot be read now (a locked device) is tried again.
    private func readAttachments(of item: OutboxItem) -> (parts: [MultipartBody.Part], problem: OutboxDecision?) {
        var parts: [MultipartBody.Part] = []
        for attachment in item.attachments {
            do {
                guard let data = try files.read(attachment.path) else {
                    let refusal = OutboxRefusal(
                        code: "attachment_missing", message: "\(attachment.fileName) is no longer on this device.")
                    return ([], .refuse(refusal))
                }
                parts.append(
                    .file(
                        name: "attachments[]", fileName: attachment.fileName, contentType: attachment.contentType,
                        data: data))
            } catch {
                return ([], OutboxRetryPolicy.decision(for: .network(.cannotOpenFile), attempt: item.attemptCount))
            }
        }
        return (parts, nil)
    }

    private func deliver(_ item: OutboxItem, attachments: [MultipartBody.Part]) async throws(APIFailure)
        -> OutboxDelivery
    {
        let references = item.attachments.map(\.reference)
        switch item.target {
        case .thread(let threadID):
            let result = try await client.send(
                .sendMessage(
                    threadID, text: item.text, model: item.model, delivery: item.delivery, sendKey: item.id,
                    attachments: attachments, references: references))
            return OutboxDelivery(
                sendKey: item.id, target: item.target, status: result.status, messageUUID: result.uuid,
                threadID: result.threadId ?? threadID, text: item.text, sentAt: clock.now())
        case .newThread(let draft):
            let withModel = NewThreadDraft(
                repo: draft.repo, mode: draft.mode, repositories: draft.repositories,
                claudeAccountId: draft.claudeAccountId, model: item.model ?? draft.model)
            let created = try await client.send(
                .startThread(
                    withModel, description: item.text, sendKey: item.id, attachments: attachments,
                    references: references))
            return OutboxDelivery(
                sendKey: item.id, target: item.target, status: .sent, messageUUID: nil, threadID: created.id,
                text: item.text, sentAt: clock.now())
        }
    }

    /// The list is saved before anyone hears of the change.
    private func publish() {
        do { try persist() } catch { logFailure("outbox list not saved: \(type(of: error))") }
        changeBroadcast.send(stored)
    }

    private func dropReceiver(_ identifier: Int) { deliveryReceivers[identifier] = nil }
}
