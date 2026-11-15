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
    public var filter: ThreadListFilter
    public var search: String?

    public init(
        list: ThreadList? = nil, load: ThreadLoadState = .loading, isShowingCachedCopy: Bool = false,
        isOffline: Bool = false, filter: ThreadListFilter = .mine, search: String? = nil
    ) {
        self.list = list
        self.load = load
        self.isShowingCachedCopy = isShowingCachedCopy
        self.isOffline = isOffline
        self.filter = filter
        self.search = search
    }

    /// Loaded, and nothing to show in any section.
    public var isEmpty: Bool {
        guard load == .ready, let list else { return false }
        return list.needsYou.isEmpty && list.starred.isEmpty && list.threads.isEmpty
    }
}

/// Keeps the thread list of one instance fresh while the drawer is visible. Only the unsearched `mine`
/// list is stored, so the stored copy, the badge count and the widget always mean the same thing.
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
    public func setFilter(_ filter: ThreadListFilter, search: String?) async {
        let trimmed = search?.trimmingCharacters(in: .whitespacesAndNewlines)
        let search = trimmed?.isEmpty == false ? trimmed : nil
        guard filter != state.filter || search != state.search else { return }
        state.filter = filter
        state.search = search
        state.list = nil
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

    /// The saved list is shown only for the view that is stored, and only before a newer answer.
    private func showSavedCopy() async {
        guard state.filter == .mine, state.search == nil, state.list == nil else { return }
        guard let saved = await cache.threadList(.mine) else { return }
        guard state.list == nil, state.filter == .mine, state.search == nil else { return }
        state.list = saved
        state.load = .ready
        state.isShowingCachedCopy = true
        slowTask?.cancel()
        slowTask = nil
        publish()
    }

    private func fetch(generation current: Int) async {
        let filter = state.filter
        let search = state.search
        let fetched: ThreadList
        do {
            fetched = try await client.send(.threads(filter: filter, search: search))
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
        state.list = fetched
        state.load = .ready
        state.isShowingCachedCopy = false
        state.isOffline = false
        publish()
        guard filter == .mine, search == nil else { return }
        await cache.saveThreadList(fetched, filter: .mine)
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
