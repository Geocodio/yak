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
    /// How often a visible engine catches up while the socket is not delivering.
    public static let pollInterval: Duration = .seconds(3)
    /// How long a first load may wait before it counts as slow.
    public static let slowAfter: Duration = .seconds(8)
    /// Events that change the page join one refresh when they arrive inside this time.
    public static let refreshSettle: Duration = .milliseconds(300)
    /// The wait before a failed refresh is tried again.
    public static let refreshRetry: Duration = .seconds(3)

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
    /// True once `open()` has restored the cache; until then live input waits so it cannot be overwritten.
    private var isOpenFinished = false
    private var problem: ThreadProblem?
    private var isClosed = false
    private var catchUpTask: Task<Void, Never>?
    private var isRerunRequested = false
    private var frameTask: Task<Void, Never>?
    private var cacheTask: Task<Void, Never>?
    private var isCacheDirty = false
    private var sideTasks: [UUID: Task<Void, Never>] = [:]
    private var pointersInFlight: Set<Int> = []
    private var isVisible = false
    private var isSubscribed = false
    private var pollTask: Task<Void, Never>?
    private var refreshTask: Task<Void, Never>?
    private var isRefreshRequested = false
    private var refreshGeneration = 0
    private var slowTask: Task<Void, Never>?
    private var olderTask: Task<Void, Never>?
    private var isLoadingOlder = false
    /// Counts the times the loaded window was replaced, so a page fetched for an earlier window is dropped.
    private var windowGeneration = 0

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
        isOpenFinished = true
        if load == .loading { startSlowTimer() }
        publishNow()
        requestCatchUp()
        updatePolling()
    }

    /// Cancels every task, saves the cache and ends the snapshot streams. Repeating it changes nothing.
    public func close() async {
        guard !isClosed else { return }
        isClosed = true
        catchUpTask?.cancel()
        frameTask?.cancel()
        cacheTask?.cancel()
        for task in sideTasks.values { task.cancel() }
        for task in [pollTask, refreshTask, slowTask, olderTask] { task?.cancel() }
        pollTask = nil
        refreshTask = nil
        slowTask = nil
        olderTask = nil
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
            if isOpenFinished { applyLive(event) }
        case .pointer(let sequence):
            if isOpenFinished { handlePointer(sequence) }
        case .subscribed:
            isSubscribed = true
            requestCatchUp()
            updatePolling()
        case .connection(let next):
            connection = next
            if next != .live { isSubscribed = false }
            changed(isStored: false)
            updatePolling()
        case .subscriptionFailed(let failure):
            isSubscribed = false
            updatePolling()
            logFailure("thread subscription failed: \(failure.map { ThreadProblem($0).logName } ?? "refused")")
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
        scheduleShowRefresh()
    }

    // MARK: Visibility, polling, refreshes and history

    /// A visible engine marks the thread seen and, while the socket is not delivering, polls.
    public func setVisible(_ visible: Bool) async {
        guard !isClosed, visible != isVisible else { return }
        isVisible = visible
        if visible {
            // Before the first show answer, confirming the show sends the mark.
            if isShowConfirmed { sendMarkSeen() }
            requestCatchUp()
        }
        updatePolling()
    }

    /// Fetches the older page of events. A second call while one runs, or at the start of the thread, does nothing.
    public func loadOlder() async {
        guard !isClosed, isOpenFinished, load == .ready, firstLoadedAfter > 0, !isLoadingOlder else { return }
        isLoadingOlder = true
        changed(isStored: false)
        let task = Task { await self.runLoadOlder() }
        olderTask = task
        await task.value
        if olderTask == task { olderTask = nil }
    }

    /// Fetches the thread now and replaces the page's messages, composer, question and panes with the answer.
    public func refreshShow() async {
        guard !isClosed, isShowConfirmed else { return }
        if let problem = await fetchShow(), problem.isRetryable { startRefreshLoop(retryFirst: true) }
    }

    private var shouldPoll: Bool {
        if case .failed(let failure) = load, !failure.isRetryable { return false }
        return isVisible && isOpenFinished && !isClosed && !(connection == .live && isSubscribed)
    }

    private func updatePolling() {
        guard shouldPoll else {
            pollTask?.cancel()
            pollTask = nil
            return
        }
        guard pollTask == nil else { return }
        pollTask = Task {
            while !Task.isCancelled {
                do { try await self.clock.sleep(for: Self.pollInterval) } catch { return }
                guard self.shouldPoll else { return }
                self.requestCatchUp()
            }
        }
    }

    private func startSlowTimer() {
        guard slowTask == nil else { return }
        slowTask = Task {
            do { try await self.clock.sleep(for: Self.slowAfter) } catch { return }
            self.slowTask = nil
            guard !self.isClosed, self.load == .loading else { return }
            self.load = .slow
            self.changed(isStored: false)
        }
    }

    private func scheduleShowRefresh() {
        guard !isClosed, isShowConfirmed else { return }
        isRefreshRequested = true
        startRefreshLoop(retryFirst: false)
    }

    private func startRefreshLoop(retryFirst: Bool) {
        guard refreshTask == nil else { return }
        isRefreshRequested = true
        refreshTask = Task { await self.runRefreshes(retryFirst: retryFirst) }
    }

    private func runRefreshes(retryFirst: Bool) async {
        var shouldWait = true
        var delay = retryFirst ? Self.refreshRetry : Self.refreshSettle
        while isRefreshRequested, !isClosed, !Task.isCancelled {
            if shouldWait {
                do { try await clock.sleep(for: delay) } catch { break }
            }
            isRefreshRequested = false
            if let problem = await fetchShow(), problem.isRetryable {
                isRefreshRequested = true
                delay = Self.refreshRetry
            } else {
                delay = Self.refreshSettle
            }
            shouldWait = true
        }
        refreshTask = nil
    }

    /// Returns the problem when the request failed, nil when it succeeded or was overtaken.
    private func fetchShow() async -> ThreadProblem? {
        refreshGeneration += 1
        let generation = refreshGeneration
        let fetched: ThreadShow
        do {
            fetched = try await client.send(.thread(threadID))
        } catch {
            guard !isClosed, !Task.isCancelled, generation == refreshGeneration else { return nil }
            let failure = ThreadProblem(error)
            logFailure("show refresh failed: \(failure.logName)")
            if failure == .offline { isOffline = true } else { problem = failure }
            changed(isStored: false)
            return failure
        }
        guard !isClosed, !Task.isCancelled, generation == refreshGeneration else { return nil }
        show = fetched
        isOffline = false
        problem = nil
        changed(isStored: true)
        return nil
    }

    private func runLoadOlder() async {
        let bound = firstLoadedAfter
        let generation = windowGeneration
        let after = max(0, bound - Self.historyPage)
        let page: EventsPage
        do {
            page = try await client.send(.events(threadID, after: after, limit: Self.historyPage))
        } catch {
            isLoadingOlder = false
            guard !isClosed, !Task.isCancelled else { return }
            fail(ThreadProblem(error), what: "history")
            return
        }
        isLoadingOlder = false
        guard !isClosed, !Task.isCancelled else { return }
        guard generation == windowGeneration else {
            changed(isStored: false)
            return
        }
        state.prependOlder(page.events.filter { $0.sequence <= bound })
        firstLoadedAfter = after
        isOffline = false
        problem = nil
        changed(isStored: true)
    }

    /// Reacts to events that were just applied: a closed question leaves the page at once, and anything
    /// that changes the page (a turn, thread or question change newer than the page) schedules a refresh.
    private func noteApplied(_ applied: [ThreadEvent]) {
        let pageSequence = show?.latestSequence ?? 0
        var needsRefresh = false
        for event in applied {
            switch event.payload {
            case .turn, .thread:
                if event.sequence > pageSequence { needsRefresh = true }
            case .question(let change):
                if !change.open, event.sequence > pageSequence { show?.question = nil }
                if event.sequence > pageSequence { needsRefresh = true }
            default:
                break
            }
        }
        if needsRefresh { scheduleShowRefresh() }
    }

    /// Fetches what the stream missed. While one runs, a request only marks a rerun.
    public func requestCatchUp() {
        guard !isClosed, isOpenFinished else { return }
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
        noteApplied(state.apply([event]))
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
            logFailure("pointer fetch failed: \(ThreadProblem(failure).logName)")
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
            // The end is decided by what the server sent, so a malformed event dropped from a full page
            // cannot make the engine skip the events after it.
            let isLastPage = page.rawEventCount < Self.historyPage
            noteApplied(state.apply(page.events, latestSequence: isLastPage ? page.latestSequence : nil))
            isOffline = false
            problem = nil
            isShowingCachedCopy = false
            load = .ready
            slowTask?.cancel()
            slowTask = nil
            changed(isStored: true)
            if isLastPage || state.lastSequence <= after { return }
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
        refreshGeneration += 1
        isShowConfirmed = true
        let cachedLast = state.lastSequence
        let hasCachedCopy = isShowingCachedCopy
        let behind = fetched.latestSequence - cachedLast
        if !hasCachedCopy || behind > Self.largestUsableGap || cachedLast > fetched.latestSequence {
            let start = max(0, fetched.latestSequence - Self.historyPage)
            state = ThreadEventState(lastSequence: start)
            firstLoadedAfter = start
            windowGeneration += 1
            isShowingCachedCopy = false
            if load == .ready {
                load = .loading
                startSlowTimer()
            }
        }
        markSeenOnce()
        changed(isStored: true)
        return true
    }

    private func markSeenOnce() {
        guard !hasMarkedSeen else { return }
        hasMarkedSeen = true
        sendMarkSeen()
    }

    private func sendMarkSeen() {
        let identifier = UUID()
        sideTasks[identifier] = Task {
            do {
                _ = try await self.client.send(.markSeen(self.threadID))
            } catch {
                logFailure("mark seen failed: \((error as? APIFailure).map { ThreadProblem($0).logName } ?? "unknown")")
            }
            self.sideTasks[identifier] = nil
        }
    }

    /// A failed request keeps what is shown, a cached copy included, whatever the problem is: `.offline`
    /// sets `isOffline` and any other problem goes into `problem`. Only without data does `load` become
    /// `.failed`.
    private func fail(_ problem: ThreadProblem, what: String) {
        logFailure("\(what) failed: \(problem.logName)")
        if problem == .offline { isOffline = true }
        if load == .ready {
            if problem != .offline { self.problem = problem }
        } else {
            load = .failed(problem)
        }
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
        if load != .loading, load != .slow {
            slowTask?.cancel()
            slowTask = nil
        }
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
                isOffline: isOffline, hasOlderHistory: firstLoadedAfter > 0, isLoadingOlder: isLoadingOlder,
                replacementThreadID: replacementThreadID, lastSequence: state.lastSequence, problem: problem))
    }
}
