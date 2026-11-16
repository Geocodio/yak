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

/// One change of the outbox, in the order it happened: a delivery always comes before the list that no
/// longer holds the delivered item.
public enum OutboxUpdate: Equatable, Sendable {
    case items([OutboxItem])
    case delivery(OutboxDelivery)
}

/// Unsent messages and new threads. Each queue sends only its head, repeats a lost send with the
/// same `send_key`, and holds everything behind a refused head until the person acts on it.
public actor Outbox {
    private let instance: UUID
    private let client: APIClient
    private let files: any FileStore
    private let clock: any YakClock
    private let makeID: @Sendable () -> UUID
    private let uploads: UploadLink?

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
    private var completionTask: Task<Void, Never>?
    /// Items whose background upload failed: waiting with no timer until a launch, a return to the foreground,
    /// a background refresh or Retry.
    private var failedUploads: Set<UUID> = []
    /// Items that are sending only in the sense that a background task holds their upload.
    private var waitingOnUpload: Set<UUID> = []
    /// Items whose failed upload may start at the next attempt: set at launch and by an explicit retry.
    private var startFailedUploads: Set<UUID> = []
    /// Items whose answer is being applied right now.
    private var handlingOutcomes: Set<UUID> = []
    private var updateReceivers: [Int: AsyncStream<OutboxUpdate>.Continuation] = [:]

    public init(
        instance: UUID, client: APIClient, files: any FileStore, clock: any YakClock,
        makeID: @escaping @Sendable () -> UUID, uploads: UploadLink? = nil
    ) {
        self.instance = instance
        self.client = client
        self.files = files
        self.clock = clock
        self.makeID = makeID
        self.uploads = uploads
    }

    deinit { completionTask?.cancel() }

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

    /// The current list first, then every change and delivery in the order they happened.
    public func updates() -> AsyncStream<OutboxUpdate> {
        let (stream, continuation) = AsyncStream.makeStream(of: OutboxUpdate.self)
        continuation.yield(.items(stored))
        nextReceiver += 1
        let identifier = nextReceiver
        updateReceivers[identifier] = continuation
        continuation.onTermination = { [weak self] _ in
            Task { await self?.dropUpdateReceiver(identifier) }
        }
        return stream
    }

    public func pauseReason() -> OutboxPause? { pauseState }

    // MARK: Changing

    public func enqueue(_ draft: OutboxDraft) async throws(FileStoreError) -> OutboxItem {
        guard !isShutDown else { throw .unreadable }
        if let sendKey = draft.sendKey, let existing = stored.first(where: { $0.id == sendKey }) { return existing }
        let item = OutboxItem(
            id: draft.sendKey ?? makeID(), target: draft.target, text: draft.text, model: draft.model,
            delivery: draft.delivery,
            attachments: draft.attachments, createdAt: clock.now(), intent: draft.intent)
        stored.append(item)
        do {
            try persist()
        } catch {
            stored.removeAll { $0.id == item.id }
            throw error
        }
        announceItems()
        pump()
        return item
    }

    // MARK: Persistence

    /// Reads `items.json`, takes in what other processes left, and starts sending. An item that was
    /// in flight when the app stopped is queued again and goes out with the same `send_key`.
    public func load() async {
        guard !isShutDown else { return }
        loadSavedList()
        await importInbox(startingFailedUploads: true)
        await settleAnsweredUploads()
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
                    let added = saved.filter { !known.contains($0.id) }
                    stored += added
                    startFailedUploads.formUnion(added.map(\.id))
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
    public func importIncoming() async {
        await importInbox(startingFailedUploads: false)
    }

    private func importInbox(startingFailedUploads: Bool) async {
        guard !isShutDown else { return }
        subscribeToCompletions()
        let folder = OutboxInbox.folder(instance)
        let names: [String]
        do { names = try files.list(folder) } catch {
            logFailure("outbox inbox unreadable: \(type(of: error))")
            publish()
            pump()
            return
        }
        var taken: [String] = []
        var incoming: [OutboxItem] = []
        for name in names where name.hasSuffix(".json") {
            let path = "\(folder)/\(name)"
            do {
                guard let data = try files.read(path) else { continue }
                var item = try JSONDecoder().decode(OutboxItem.self, from: data)
                item.state = .queued
                // A share extension starts its upload before it adds the item, so an upload that is running or
                // answered means the server may already have the message and its text can no longer change.
                switch await uploads?.ledger.record(for: item.id)?.phase {
                case .uploading?, .answered?: item.mayHaveReachedServer = true
                default: break
                }
                incoming.append(item)
                taken.append(path)
            } catch is DecodingError {
                logFailure("outbox inbox file dropped: not an item")
                removeFile(path)
            } catch {
                logFailure("outbox inbox file unreadable: \(type(of: error))")
            }
        }
        guard !isShutDown else { return }
        for item in incoming where !stored.contains(where: { $0.id == item.id }) {
            stored.append(item)
            if startingFailedUploads { startFailedUploads.insert(item.id) }
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
        announceItems()
        pump()
    }

    /// Everything this instance keeps for sending: the list, the inbox and the attachment files.
    public func eraseAll() {
        guard !isShutDown else { return }
        for timer in slowTimers.values { timer.cancel() }
        for timer in retryTimers.values { timer.cancel() }
        slowTimers = [:]
        retryTimers = [:]
        for item in stored { dropRecord(of: item.id) }
        stored = []
        failedUploads = []
        waitingOnUpload = []
        removeFile(InstanceFolders.outbox(instance))
        announceItems()
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
        case .queued, .waiting: if stored[index].mayHaveReachedServer { throw .mayAlreadyBeSent }
        case .refused: break
        }
        stored[index].text = text
        if case .refused = stored[index].state { dropRecord(of: id) }
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
        failedUploads.remove(id)
        publish()
        removeAttachments(of: item)
        dropRecord(of: id)
        pump()
        return item
    }

    /// Gives an editable item up to its owner without deleting its attachment files: the caller now owns them and
    /// may send them again under the same id. An item the server may already hold stays.
    public func take(_ id: UUID) throws(OutboxEditProblem) -> OutboxItem {
        guard !isShutDown, let index = stored.firstIndex(where: { $0.id == id }) else { throw .notFound }
        switch stored[index].state {
        case .sending, .slow: throw .alreadySending
        case .queued, .waiting: if stored[index].mayHaveReachedServer { throw .mayAlreadyBeSent }
        case .refused: break
        }
        let item = stored.remove(at: index)
        do {
            try persist()
        } catch {
            stored.insert(item, at: min(index, stored.count))
            throw .notSaved
        }
        retryTimers.removeValue(forKey: id)?.cancel()
        failedUploads.remove(id)
        publish()
        dropRecord(of: id)
        pump()
        return item
    }

    /// Removes the attachment folders that no item here and no name in `draftFolders` holds: what a stop between
    /// storing a file and saving its draft left behind. An item waiting in the inbox keeps its folder. Does nothing
    /// unless every holder is accounted for: `draftFolders` is nil when a draft could not be read, and an unreadable
    /// inbox listing or an item list that did not decode stops the sweep.
    public func sweepAttachmentFolders(keeping draftFolders: Set<String>?) async {
        guard !isShutDown, hasLoadedList, let draftFolders else { return }
        do {
            if try files.read(OutboxInbox.unreadableItemsPath(instance)) != nil { return }
        } catch { return }
        let folder = "\(InstanceFolders.outbox(instance))/files"
        let names: [String]
        do { names = try files.list(folder) } catch { return }
        var held = draftFolders
        for item in stored {
            held.insert(item.id.uuidString)
            for attachment in item.attachments {
                if let name = OutboxInbox.attachmentFolderName(of: attachment.path) { held.insert(name) }
            }
        }
        let waiting: [String]
        do { waiting = try files.list(OutboxInbox.folder(instance)) } catch {
            logFailure("sweep skipped, inbox unreadable: \(type(of: error))")
            return
        }
        for name in waiting where name.hasSuffix(".json") { held.insert(String(name.dropLast(5))) }
        if let records = await uploads?.ledger.records() {
            for record in records { held.insert(record.itemID.uuidString) }
        }
        guard !isShutDown else { return }
        // A folder is removed only when it was also unheld at the previous sweep, so a file stored a moment before
        // its draft or item is saved is never taken.
        let candidatesPath = "\(InstanceFolders.outbox(instance))/sweep-candidates.json"
        var previous: Set<String> = []
        do {
            if let data = try files.read(candidatesPath) {
                previous = Set(try JSONDecoder().decode([String].self, from: data))
            }
        } catch { logFailure("sweep candidates unreadable: \(type(of: error))") }
        let unheld = Set(names).subtracting(held)
        let doomed = unheld.intersection(previous)
        for name in doomed { removeFile("\(folder)/\(name)") }
        do {
            try files.write(
                JSONEncoder().encode(unheld.subtracting(doomed).sorted()), to: candidatesPath,
                protection: .untilFirstUnlock)
        } catch { logFailure("sweep candidates not saved: \(type(of: error))") }
    }

    /// A refused or waiting item is sent again now.
    public func retry(_ id: UUID) {
        guard !isShutDown else { return }
        guard let index = stored.firstIndex(where: { $0.id == id }) else { return }
        switch stored[index].state {
        case .refused, .waiting:
            retryTimers.removeValue(forKey: id)?.cancel()
            failedUploads.remove(id)
            startFailedUploads.insert(id)
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
        // An item in flight may already be on the server, so it reads as waiting, never as still sending.
        for index in stored.indices where stored[index].state == .sending || stored[index].state == .slow {
            stored[index].state = .waiting
        }
        announceItems()
        for timer in slowTimers.values { timer.cancel() }
        for timer in retryTimers.values { timer.cancel() }
        for task in sendTasks.values { task.cancel() }
        completionTask?.cancel()
        completionTask = nil
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
        for index in stored.indices where stored[index].state == .waiting && !failedUploads.contains(stored[index].id) {
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
        if let uploads, !item.attachments.isEmpty {
            await performUpload(item, attempt: attempt, link: uploads)
            return
        }
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
        if !Task.isCancelled, let index = stored.firstIndex(where: { $0.id == id }) {
            stored[index].mayHaveReachedServer = true
            do { try persist() } catch { logFailure("outbox list not saved: \(type(of: error))") }
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
            for receiver in updateReceivers.values { receiver.yield(.delivery(delivery)) }
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
            // A request may have reached the server; `mayHaveReachedServer` keeps the item from being edited.
            stored[index].state = .queued
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
                    attachments: attachments, references: references, intent: item.intent))
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
        announceItems()
    }

    private func announceItems() {
        changeBroadcast.send(stored)
        for receiver in updateReceivers.values { receiver.yield(.items(stored)) }
    }

    private func dropReceiver(_ identifier: Int) { deliveryReceivers[identifier] = nil }
    private func dropUpdateReceiver(_ identifier: Int) { updateReceivers[identifier] = nil }

    // MARK: Background uploads

    /// Starts every item whose background upload failed, was lost or was left by a share extension long ago.
    /// Called on launch (from `load`), when the app returns to the foreground and by background refresh; never
    /// by the backoff timer.
    public func retryFailedUploads() async {
        guard let uploads, !isShutDown else { return }
        let ids = Set(await uploads.ledger.retryable().map(\.itemID))
        guard !isShutDown else { return }
        for index in stored.indices where ids.contains(stored[index].id) {
            let id = stored[index].id
            switch stored[index].state {
            case .waiting: if retryTimers[id] != nil { continue }
            case .queued: break
            case .sending, .slow: if !waitingOnUpload.contains(id) { continue }
            case .refused: continue
            }
            retryTimers.removeValue(forKey: id)?.cancel()
            slowTimers.removeValue(forKey: id)?.cancel()
            failedUploads.remove(id)
            waitingOnUpload.remove(id)
            startFailedUploads.insert(id)
            stored[index].state = .queued
        }
        publish()
        pump()
    }

    private func subscribeToCompletions() {
        guard completionTask == nil, let ledger = uploads?.ledger else { return }
        completionTask = Task { [weak self] in
            let completions = await ledger.completions()
            await self?.settleAnsweredUploads()
            for await id in completions { await self?.uploadCompleted(id) }
        }
    }

    /// Applies answers that came while nobody listened and drops records whose item is gone.
    private func settleAnsweredUploads() async {
        guard let ledger = uploads?.ledger else { return }
        for record in await ledger.records() {
            await uploadCompleted(record.itemID)
        }
    }

    private func uploadCompleted(_ id: UUID) async {
        guard !isShutDown, let ledger = uploads?.ledger else { return }
        guard let index = stored.firstIndex(where: { $0.id == id }) else {
            guard let record = await ledger.record(for: id) else { return }
            // A share extension adds its item after it starts the upload, so a fresh share record waits for the item.
            // A record that no item claims within `shareLostAfter`, whatever its phase, is removed with its body file.
            let age = Duration.seconds(clock.now().timeIntervalSince(record.updatedAt))
            if age < UploadLedger.shareLostAfter {
                guard case .answered = record.phase, record.origin != .share else { return }
            }
            await ledger.remove(id)
            return
        }
        switch stored[index].state {
        case .sending, .slow, .waiting: await applyUploadState(id)
        case .queued, .refused: break
        }
    }

    private func dropRecord(of id: UUID) {
        guard let ledger = uploads?.ledger else { return }
        Task { await ledger.remove(id) }
    }

    private func performUpload(_ item: OutboxItem, attempt: Int, link: UploadLink) async {
        let id = item.id
        func isCurrent() -> Bool {
            !isShutDown && stored.contains { $0.id == id && $0.attemptCount == attempt }
        }
        var record = await link.ledger.record(for: id)
        if case .uploading(let sessionIdentifier, _)? = record?.phase, sessionIdentifier == link.session.identifier {
            await link.ledger.reconcile(session: sessionIdentifier, live: await link.session.liveTasks())
            if case .failed(.lost)? = await link.ledger.record(for: id)?.phase { startFailedUploads.insert(id) }
            record = await link.ledger.record(for: id)
        }
        guard isCurrent() else { return }
        let mayStart = startFailedUploads.remove(id) != nil
        let isStale = await link.ledger.retryable().contains { $0.itemID == id }
        guard isCurrent() else { return }
        switch record?.phase {
        case .answered?:
            await applyUploadState(id)
        case .failed? where !mayStart:
            await applyUploadState(id)
        case .uploading? where !isStale:
            waitingOnUpload.insert(id)
            sendTasks[id] = nil
        case .prepared? where record?.origin == .share && !isStale && !mayStart:
            waitingOnUpload.insert(id)
            sendTasks[id] = nil
        default:
            await startUpload(item, attempt: attempt, link: link, existing: record)
        }
    }

    private func startUpload(_ item: OutboxItem, attempt: Int, link: UploadLink, existing: UploadRecord?) async {
        let id = item.id
        if let index = stored.firstIndex(where: { $0.id == id }) {
            stored[index].mayHaveReachedServer = true
            do { try persist() } catch { logFailure("outbox list not saved: \(type(of: error))") }
        }
        do throws(MultipartFileProblem) {
            if existing == nil { _ = try await link.ledger.prepare(item, origin: .app) }
        } catch {
            let decision = decision(for: error, attempt: item.attemptCount)
            slowTimers.removeValue(forKey: id)?.cancel()
            sendTasks[id] = nil
            guard let index = stored.firstIndex(where: { $0.id == id }), stored[index].attemptCount == attempt
            else { return }
            apply(decision, toItemAt: index, attempt: attempt)
            pump()
            return
        }
        waitingOnUpload.remove(id)
        failedUploads.remove(id)
        _ = await link.ledger.start(id, session: link.session, client: client)
        sendTasks[id] = nil
        guard !isShutDown, stored.contains(where: { $0.id == id && $0.attemptCount == attempt }) else { return }
        switch await link.ledger.record(for: id)?.phase {
        case .uploading?: waitingOnUpload.insert(id)
        default: await applyUploadState(id)
        }
    }

    private func decision(for problem: MultipartFileProblem, attempt: Int) -> OutboxDecision {
        switch problem {
        case .attachmentMissing(let fileName):
            .refuse(
                OutboxRefusal(code: "attachment_missing", message: "\(fileName) is no longer on this device."))
        case .partCountMismatch:
            .refuse(OutboxRefusal(code: "encoding_failed", message: ""))
        case .locked, .notWritten:
            OutboxRetryPolicy.decision(for: .network(.cannotOpenFile), attempt: attempt)
        }
    }

    /// Reads the record of an item and applies what it says: an answer is delivered, refused, retried or paused
    /// on; a failed upload holds the item, and so its thread, until the next trigger.
    private func applyUploadState(_ id: UUID) async {
        guard let ledger = uploads?.ledger, let record = await ledger.record(for: id) else { return }
        switch record.phase {
        case .failed:
            guard let index = stored.firstIndex(where: { $0.id == id }), !isShutDown else { return }
            slowTimers.removeValue(forKey: id)?.cancel()
            retryTimers.removeValue(forKey: id)?.cancel()
            sendTasks[id] = nil
            waitingOnUpload.remove(id)
            failedUploads.insert(id)
            stored[index].state = .waiting
            publish()
            pump()
        case .answered:
            guard let outcome = await ledger.outcome(for: id) else { return }
            await applyUploadOutcome(outcome, id: id, ledger: ledger)
        case .prepared, .uploading: break
        }
    }

    private func applyUploadOutcome(_ outcome: UploadOutcome, id: UUID, ledger: UploadLedger) async {
        guard !isShutDown, handlingOutcomes.insert(id).inserted else { return }
        defer { handlingOutcomes.remove(id) }
        // Two callers can read the same answer; only the one that still finds it answered acts on it.
        guard case .answered? = await ledger.record(for: id)?.phase,
            let index = stored.firstIndex(where: { $0.id == id })
        else { return }
        let item = stored[index]
        var decision: OutboxDecision
        switch outcome {
        case .delivered(let status, let messageUUID, let answeredThread):
            var threadID = answeredThread
            if threadID == nil, case .thread(let target) = item.target { threadID = target }
            if let threadID {
                finishWaiting(id)
                let delivery = OutboxDelivery(
                    sendKey: id, target: item.target, status: status, messageUUID: messageUUID, threadID: threadID,
                    text: item.text, sentAt: clock.now())
                for receiver in deliveryReceivers.values { receiver.yield(delivery) }
                for receiver in updateReceivers.values { receiver.yield(.delivery(delivery)) }
                stored.remove(at: index)
                publish()
                removeAttachments(of: item)
                await ledger.remove(id)
                pump()
                return
            }
            decision = OutboxRetryPolicy.decision(for: .notYak, attempt: item.attemptCount)
        case .decision(let answered):
            decision = answered
        }
        switch decision {
        case .refuse:
            await ledger.remove(id)
        default:
            do throws(MultipartFileProblem) {
                _ = try await ledger.renew(item)
            } catch {
                decision = self.decision(for: error, attempt: item.attemptCount)
                if case .refuse = decision { await ledger.remove(id) }
            }
        }
        guard !isShutDown, let current = stored.firstIndex(where: { $0.id == id }) else { return }
        finishWaiting(id)
        retryTimers.removeValue(forKey: id)?.cancel()
        apply(decision, toItemAt: current, attempt: stored[current].attemptCount)
        pump()
    }

    private func finishWaiting(_ id: UUID) {
        slowTimers.removeValue(forKey: id)?.cancel()
        sendTasks[id] = nil
        waitingOnUpload.remove(id)
        failedUploads.remove(id)
    }
}
