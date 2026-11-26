import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "ThreadListFeed").error("\(message, privacy: .public)")
    #endif
}

/// Everything the drawer's list draws, as one value.
public struct ThreadListState: Equatable, Sendable {
    public var list: ThreadList?
    public var load: ThreadLoadState
    /// The list on screen came from the disk and the server has not confirmed it yet.
    public var isShowingCachedCopy: Bool
    /// The last request failed with `.offline`; the list on screen stays.
    public var isOffline: Bool
    /// When the list on screen was answered by the server (a saved copy: when it was saved); nil when not known.
    public var listAsOf: Date?
    public var filter: ThreadListFilter
    public var search: String?
    public var sources: [String]
    public var starters: [String]

    public init(
        list: ThreadList? = nil, load: ThreadLoadState = .loading, isShowingCachedCopy: Bool = false,
        isOffline: Bool = false, listAsOf: Date? = nil, filter: ThreadListFilter = .mine, search: String? = nil,
        sources: [String] = [],
        starters: [String] = []
    ) {
        self.list = list
        self.load = load
        self.isShowingCachedCopy = isShowingCachedCopy
        self.isOffline = isOffline
        self.listAsOf = listAsOf
        self.filter = filter
        self.search = search
        self.sources = sources
        self.starters = starters
    }

    /// No search, source or starter narrows the tab: the answer is the tab's whole list, the one that is stored.
    public var isWholeTab: Bool { search == nil && sources.isEmpty && starters.isEmpty }

    /// The whole Mine tab: the only list the badge count, the cold start and the widget read.
    public var isWholeMine: Bool { filter == .mine && isWholeTab }

    /// Loaded, and nothing to show in any section.
    public var isEmpty: Bool {
        guard load == .ready, let list else { return false }
        return list.needsYou.isEmpty && list.starred.isEmpty && list.threads.isEmpty
    }
}

/// Keeps the thread list of one instance fresh while the drawer is visible. Each tab's whole list is stored on its
/// own; a searched or filtered view is never stored. Only the whole `mine` list feeds the badge count and the widget.
public actor ThreadListFeed {
    public static let pollInterval: Duration = .seconds(5)
    /// How long a first load may wait before it counts as slow.
    public static let slowAfter: Duration = .seconds(8)

    private let instance: Instance
    private let client: APIClient
    private let cache: Cache
    private let instances: InstanceStore
    private let clock: any YakClock
    private let broadcast = Broadcast(ThreadListState())

    private var state = ThreadListState()
    private var isVisible = false
    /// Counts the requests started, so an answer to an overtaken request is dropped.
    private var generation = 0
    private var firstFetch: Task<Void, Never>?
    private var pollTask: Task<Void, Never>?
    private var slowTask: Task<Void, Never>?

    public init(instance: Instance, client: APIClient, cache: Cache, instances: InstanceStore, clock: any YakClock) {
        self.instance = instance
        self.client = client
        self.cache = cache
        self.instances = instances
        self.clock = clock
    }

    public func states() -> AsyncStream<ThreadListState> {
        broadcast.stream()
    }

    /// Visible: shows the saved list at once, loads, and loads again every 5 seconds. Hidden: cancels everything.
    public func setVisible(_ visible: Bool) async {
        guard visible != isVisible else { return }
        isVisible = visible
        guard visible else {
            stop()
            return
        }
        await showSavedCopy()
        guard isVisible else { return }
        _ = start()
    }

    /// Cancels the request in flight and loads the new view at once.
    public func setFilter(
        _ filter: ThreadListFilter, search: String?, sources: [String] = [], starters: [String] = []
    ) async {
        let trimmed = search?.trimmingCharacters(in: .whitespacesAndNewlines)
        let search = trimmed?.isEmpty == false ? trimmed : nil
        guard
            filter != state.filter || search != state.search || sources != state.sources
                || starters != state.starters
        else { return }
        state.filter = filter
        state.search = search
        state.sources = sources
        state.starters = starters
        state.list = nil
        state.listAsOf = nil
        state.isShowingCachedCopy = false
        state.isOffline = false
        state.load = .loading
        publish()
        // Cancels the request of the view that was left before anything awaits.
        let first = start()
        await showSavedCopy()
        await first.value
    }

    /// Loads now and waits for the answer.
    public func refresh() async {
        if state.list == nil, case .failed = state.load {
            state.load = .loading
            publish()
        }
        await start().value
    }

    /// Applies a change the person's own action already knows to the list on screen and to every saved tab, the widget
    /// snapshot and the needs-you count, and drops the answer of any request that was asked before it, so a stale
    /// list never undoes it.
    public func apply(_ edit: ThreadListEdit) async {
        if let list = state.list {
            state.list = list.applying(edit)
            generation += 1
            publish()
        }
        for tab in ThreadListFilter.allCases {
            guard let saved = await cache.threadList(tab) else { continue }
            let savedAt = await cache.threadListSavedAt(tab)
            await cache.saveThreadList(saved.applying(edit), filter: tab, savedAt: savedAt)
        }
        guard let mine = await cache.threadList(.mine) else { return }
        do {
            try await instances.setNeedsYouCount(instance.id, mine.needsYouCount)
        } catch {
            logFailure("needs-you count not stored: \(type(of: error))")
        }
        await cache.saveWidgetSnapshot(WidgetSnapshot.make(from: mine, instanceName: instance.name, now: clock.now()))
    }

    // MARK: Loading

    /// Starts a request now, and the polling when visible. Returns the first request.
    private func start() -> Task<Void, Never> {
        stop()
        generation += 1
        let current = generation
        let first = Task { await self.fetch(generation: current) }
        firstFetch = first
        if state.load == .loading { startSlowTimer() }
        if isVisible {
            pollTask = Task {
                await first.value
                while !Task.isCancelled, self.shouldKeepPolling {
                    do { try await self.clock.sleep(for: Self.pollInterval) } catch { return }
                    guard !Task.isCancelled else { return }
                    self.generation += 1
                    await self.fetch(generation: self.generation)
                }
            }
        }
        return first
    }

    private func stop() {
        firstFetch?.cancel()
        pollTask?.cancel()
        slowTask?.cancel()
        firstFetch = nil
        pollTask = nil
        slowTask = nil
    }

    private var shouldKeepPolling: Bool {
        if case .failed(let problem) = state.load, !problem.isRetryable { return false }
        return isVisible
    }

    private func startSlowTimer() {
        slowTask = Task {
            do { try await self.clock.sleep(for: Self.slowAfter) } catch { return }
            guard self.state.load == .loading else { return }
            self.state.load = .slow
            self.publish()
        }
    }

    /// The saved list is shown only for a whole tab, and only before a newer answer.
    private func showSavedCopy() async {
        guard state.isWholeTab, state.list == nil else { return }
        let tab = state.filter
        guard let saved = await cache.threadList(tab) else { return }
        // Read before the state changes, so no other request sees a half-set state while this waits.
        let savedAt = await cache.threadListSavedAt(tab)
        guard state.list == nil, state.filter == tab, state.isWholeTab else { return }
        // An offline answer that came first still counts: the copy is shown with the offline line.
        if case .failed(.offline) = state.load { state.isOffline = true }
        state.list = saved
        state.listAsOf = savedAt
        state.load = .ready
        state.isShowingCachedCopy = true
        slowTask?.cancel()
        slowTask = nil
        publish()
    }

    private func fetch(generation current: Int) async {
        let filter = state.filter
        let search = state.search
        let sources = state.sources
        let starters = state.starters
        let fetched: ThreadList
        do {
            fetched = try await client.send(
                .threads(filter: filter, search: search, sources: sources, starters: starters))
        } catch {
            guard !Task.isCancelled, current == generation else { return }
            let problem = ThreadProblem(error)
            logFailure("thread list failed: \(problem.logName)")
            slowTask?.cancel()
            slowTask = nil
            if state.list != nil, problem == .offline {
                state.isOffline = true
            } else {
                state.load = .failed(problem)
            }
            publish()
            return
        }
        guard !Task.isCancelled, current == generation else { return }
        slowTask?.cancel()
        slowTask = nil
        let answeredAt = clock.now()
        state.list = fetched
        state.listAsOf = answeredAt
        state.load = .ready
        state.isShowingCachedCopy = false
        state.isOffline = false
        publish()
        guard search == nil, sources.isEmpty, starters.isEmpty else { return }
        await cache.saveThreadList(fetched, filter: filter, savedAt: answeredAt)
        guard filter == .mine else { return }
        do {
            try await instances.setNeedsYouCount(instance.id, fetched.needsYouCount)
        } catch {
            logFailure("needs-you count not stored: \(type(of: error))")
        }
        await cache.saveWidgetSnapshot(
            WidgetSnapshot.make(from: fetched, instanceName: instance.name, now: clock.now()))
    }

    private func publish() {
        broadcast.send(state)
    }
}
