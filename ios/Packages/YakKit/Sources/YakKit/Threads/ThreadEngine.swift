import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "ThreadEngine").error("\(message, privacy: .public)")
    #endif
}

/// One open thread: events in, conversation snapshots out. Events are applied in sequence order, a gap
/// or a pointer is closed by a catch-up that runs one at a time, and snapshots are paced to a frame.
public actor ThreadEngine {
    public static let historyPage = 500
    /// A cached copy further behind than this is dropped and the newest page is loaded instead.
    private static let largestUsableGap = 2000

    public nonisolated let threadID: String

    private let client: APIClient
    private let cache: Cache
    private let clock: any YakClock
    private let frameInterval: Duration
    private let cacheSaveDelay: Duration
    private let broadcast = Broadcast(ConversationSnapshot())

    private var state = ThreadEventState()
    private var tracker = ReadTracker()
    private var show: ThreadShow?
    private var load: ThreadLoadState = .loading
    private var connection: ConnectionState = .offline
    private var isShowingCachedCopy = false
    private var isOffline = false
    private var firstLoadedAfter = 0
    private var isShowConfirmed = false
    private var hasMarkedSeen = false
    private var outboxItems: [OutboxItem] = []
    private var deliveries: [OutboxDelivery] = []
    private var replacementThreadID: String?

    private var hasOpened = false
    private var isClosed = false
    private var catchUpTask: Task<Void, Never>?
    private var isRerunRequested = false
    private var frameTask: Task<Void, Never>?
    private var cacheTask: Task<Void, Never>?
    private var isCacheDirty = false
    private var sideTasks: [UUID: Task<Void, Never>] = [:]
    private var pointersInFlight: Set<Int> = []

    public init(
        threadID: String, client: APIClient, cache: Cache, clock: any YakClock,
        frameInterval: Duration = .milliseconds(16), cacheSaveDelay: Duration = .seconds(2)
    ) {
        self.threadID = threadID
        self.client = client
        self.cache = cache
        self.clock = clock
        self.frameInterval = frameInterval
        self.cacheSaveDelay = cacheSaveDelay
    }

    // MARK: Lifecycle

    /// Shows the cached copy at once, then confirms with the server. Repeating it changes nothing.
    public func open() async {
        guard !hasOpened, !isClosed else { return }
        hasOpened = true
        if let cached = await cache.thread(threadID) {
            await cache.markOpened(threadID)
            guard !isClosed else { return }
            restore(from: cached)
            load = .ready
            isShowingCachedCopy = true
        }
        publishNow()
        requestCatchUp()
    }

    /// Cancels every task, saves the cache and ends the snapshot streams. Repeating it changes nothing.
    public func close() async {
        guard !isClosed else { return }
        isClosed = true
        catchUpTask?.cancel()
        frameTask?.cancel()
        cacheTask?.cancel()
        for task in sideTasks.values { task.cancel() }
        catchUpTask = nil
        frameTask = nil
        cacheTask = nil
        sideTasks = [:]
        await saveCacheNow()
        broadcast.finish()
    }

    public func snapshots() -> AsyncStream<ConversationSnapshot> {
        broadcast.stream()
    }

    /// Saves the cache now.
    public func flush() async {
        cacheTask?.cancel()
        cacheTask = nil
        await saveCacheNow()
    }

    // MARK: Input

    public func receive(_ message: ThreadChannelMessage) async {
        guard !isClosed else { return }
        switch message {
        case .event(let event):
            applyLive(event)
        case .pointer(let sequence):
            handlePointer(sequence)
        case .subscribed:
            requestCatchUp()
        case .connection(let next):
            connection = next
            changed(isStored: false)
        case .subscriptionFailed(let failure):
            logFailure("thread subscription failed: \(failure.map { "\($0)" } ?? "refused")")
        }
    }

    public func outboxChanged(_ items: [OutboxItem]) async {
        guard !isClosed else { return }
        outboxItems = items
        changed(isStored: false)
    }

    public func delivered(_ delivery: OutboxDelivery) async {
        guard !isClosed else { return }
        if !deliveries.contains(where: { $0.sendKey == delivery.sendKey }) { deliveries.append(delivery) }
        if delivery.threadID != threadID { replacementThreadID = delivery.threadID }
        changed(isStored: false)
    }

    /// Fetches what the stream missed. While one runs, a request only marks a rerun.
    public func requestCatchUp() {
        guard !isClosed else { return }
        if catchUpTask != nil {
            isRerunRequested = true
            return
        }
        catchUpTask = Task { await self.runCatchUps() }
    }

    // MARK: Live events

    private func applyLive(_ event: ThreadEvent) {
        if event.sequence <= state.lastSequence { return }
        if event.sequence > state.lastSequence + 1 {
            requestCatchUp()
            return
        }
        state.apply([event])
        changed(isStored: event.isStored)
    }

    private func handlePointer(_ sequence: Int) {
        if sequence <= state.lastSequence { return }
        if sequence > state.lastSequence + 1 {
            requestCatchUp()
            return
        }
        guard pointersInFlight.insert(sequence).inserted else { return }
        let identifier = UUID()
        sideTasks[identifier] = Task {
            await self.fetchPointer(sequence)
            self.sideTasks[identifier] = nil
        }
    }

    private func fetchPointer(_ sequence: Int) async {
        let result: Result<ThreadEvent, APIFailure>
        do {
            result = .success(try await client.send(.event(threadID, sequence: sequence)))
        } catch {
            result = .failure(error)
        }
        pointersInFlight.remove(sequence)
        guard !isClosed, !Task.isCancelled else { return }
        switch result {
        case .success(let event): applyLive(event)
        case .failure(let failure):
            logFailure("pointer fetch failed: \(failure)")
            requestCatchUp()
        }
    }

    // MARK: Catch-up

    private func runCatchUps() async {
        repeat {
            isRerunRequested = false
            await runCatchUp()
        } while isRerunRequested && !isClosed && !Task.isCancelled
        catchUpTask = nil
    }

    private func runCatchUp() async {
        if !isShowConfirmed {
            guard await confirmShow() else { return }
        }
        while !isClosed, !Task.isCancelled {
            let after = state.lastSequence
            let page: EventsPage
            do {
                page = try await client.send(.events(threadID, after: after, limit: Self.historyPage))
            } catch {
                guard !isClosed, !Task.isCancelled else { return }
                fail(ThreadProblem(error), what: "catch-up")
                return
            }
            guard !isClosed, !Task.isCancelled else { return }
            let isLastPage = page.events.count < Self.historyPage
            state.apply(page.events, latestSequence: isLastPage ? page.latestSequence : nil)
            isOffline = false
            isShowingCachedCopy = false
            load = .ready
            changed(isStored: true)
            if isLastPage { return }
        }
    }

    /// Fetches the thread and decides where the catch-up starts. False when it failed.
    private func confirmShow() async -> Bool {
        let fetched: ThreadShow
        do {
            fetched = try await client.send(.thread(threadID))
        } catch {
            guard !isClosed, !Task.isCancelled else { return false }
            fail(ThreadProblem(error), what: "show")
            return false
        }
        guard !isClosed, !Task.isCancelled else { return false }
        show = fetched
        isShowConfirmed = true
        let cachedLast = state.lastSequence
        let hasCachedCopy = isShowingCachedCopy
        let behind = fetched.latestSequence - cachedLast
        if !hasCachedCopy || behind > Self.largestUsableGap || cachedLast > fetched.latestSequence {
            let start = max(0, fetched.latestSequence - Self.historyPage)
            state = ThreadEventState(lastSequence: start)
            firstLoadedAfter = start
            isShowingCachedCopy = false
            load = .loading
        }
        markSeenOnce()
        changed(isStored: true)
        return true
    }

    private func markSeenOnce() {
        guard !hasMarkedSeen else { return }
        hasMarkedSeen = true
        let identifier = UUID()
        sideTasks[identifier] = Task {
            do {
                _ = try await self.client.send(.markSeen(self.threadID))
            } catch {
                logFailure("mark seen failed: \(error)")
            }
            self.sideTasks[identifier] = nil
        }
    }

    /// A failed request keeps what is shown. Without any data the screen gets the error state.
    private func fail(_ problem: ThreadProblem, what: String) {
        logFailure("\(what) failed: \(problem)")
        if problem == .offline { isOffline = true }
        if problem != .offline || load != .ready { load = .failed(problem) }
        changed(isStored: false)
    }

    // MARK: Cache

    private func restore(from cached: CachedThread) {
        let first = cached.events.map(\.sequence).min() ?? .max
        var restored = ThreadEventState(lastSequence: min(cached.firstLoadedAfter, first - 1, cached.lastSequence))
        restored.apply(cached.events, latestSequence: cached.lastSequence)
        state = restored
        show = cached.show
        firstLoadedAfter = cached.firstLoadedAfter
    }

    private func saveCacheNow() async {
        isCacheDirty = false
        guard let show else { return }
        let copy = CachedThread(
            show: show, events: state.storedEvents, lastSequence: state.lastSequence,
            firstLoadedAfter: firstLoadedAfter)
        await cache.saveThread(threadID, copy)
    }

    // MARK: Snapshots

    private func changed(isStored: Bool) {
        scheduleSnapshot()
        if isStored { scheduleCacheSave() }
    }

    private func scheduleSnapshot() {
        if frameInterval == .zero {
            publishNow()
            return
        }
        guard frameTask == nil else { return }
        frameTask = Task {
            do { try await self.clock.sleep(for: self.frameInterval) } catch { return }
            self.frameTask = nil
            self.publishNow()
        }
    }

    private func scheduleCacheSave() {
        guard show != nil else { return }
        isCacheDirty = true
        guard cacheTask == nil else { return }
        cacheTask = Task {
            do { try await self.clock.sleep(for: self.cacheSaveDelay) } catch { return }
            self.cacheTask = nil
            if self.isCacheDirty, !self.isClosed { await self.saveCacheNow() }
        }
    }

    private func publishNow() {
        guard !isClosed else { return }
        let messages = show?.messages ?? []
        deliveries.removeAll { delivery in
            messages.contains { message in
                (delivery.messageUUID != nil && message.uuid == delivery.messageUUID)
                    || message.sendKey?.lowercased() == delivery.sendKey.uuidString.lowercased()
            }
        }
        let projection = ConversationProjection.project(state)
        let read = tracker.track(projection.readSequences.keys)
        let entries = ConversationMerge.merge(
            messages: messages, readUUIDs: read, items: projection.items,
            readSequences: projection.readSequences, messageStates: state.messageStates, unsent: outboxItems,
            delivered: deliveries)
        broadcast.send(
            ConversationSnapshot(
                load: load, show: show, entries: entries,
                currentStepKey: ConversationMerge.currentStepKey(in: entries, turn: state.turn),
                waiting: ConversationMerge.waitingRow(in: entries, turn: state.turn), turn: state.turn,
                thread: state.thread, connection: connection, isShowingCachedCopy: isShowingCachedCopy,
                isOffline: isOffline, hasOlderHistory: firstLoadedAfter > 0, isLoadingOlder: false,
                replacementThreadID: replacementThreadID, lastSequence: state.lastSequence))
    }
}
