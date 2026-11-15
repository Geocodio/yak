import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "PagedFeed").error("\(message, privacy: .public)")
    #endif
}

/// A list that loads page by page: Tasks and Observations. The first page of the query it was made with is saved
/// under `cacheKey`; other queries show nothing saved. There is no polling: callers reload on appear, on pull to
/// refresh and after an action.
public actor PagedFeed<
    Query: Equatable & Codable & Sendable, Row: Codable & Equatable & Sendable & Identifiable
> where Row.ID: Hashable & Sendable {
    public struct Snapshot: Equatable, Sendable {
        public var load: ThreadLoadState
        public var rows: [Row]
        public var nextPage: Int?
        public var isLoadingMore: Bool
        public var isShowingCachedCopy: Bool
        public var isOffline: Bool
        public var problem: ThreadProblem?

        public init(
            load: ThreadLoadState = .loading, rows: [Row] = [], nextPage: Int? = nil, isLoadingMore: Bool = false,
            isShowingCachedCopy: Bool = false, isOffline: Bool = false, problem: ThreadProblem? = nil
        ) {
            self.load = load
            self.rows = rows
            self.nextPage = nextPage
            self.isLoadingMore = isLoadingMore
            self.isShowingCachedCopy = isShowingCachedCopy
            self.isOffline = isOffline
            self.problem = problem
        }
    }

    public typealias Fetch = @Sendable (Query, Int) async throws(APIFailure) -> (rows: [Row], nextPage: Int?)

    private let cacheableQuery: Query
    private let cacheKey: TaskListCacheKey?
    private let fetch: Fetch
    private let cache: Cache
    private let clock: any YakClock
    private let broadcast = Broadcast(Snapshot())

    private var query: Query
    private var snapshot = Snapshot()
    /// Counts the page-one loads started, so an answer to an overtaken load is dropped.
    private var generation = 0
    private var moreTask: Task<Void, Never>?
    private var slowTask: Task<Void, Never>?
    private var isClosed = false

    /// `query` is the default view: the only one whose first page is saved and shown from the disk.
    public init(
        query: Query, cacheKey: TaskListCacheKey?, fetch: @escaping Fetch, cache: Cache, clock: any YakClock
    ) {
        cacheableQuery = query
        self.query = query
        self.cacheKey = cacheKey
        self.fetch = fetch
        self.cache = cache
        self.clock = clock
    }

    public func snapshots() -> AsyncStream<Snapshot> {
        broadcast.stream()
    }

    /// Drops the rows and loads page 1 of the new query.
    public func setQuery(_ newQuery: Query) async {
        guard !isClosed, newQuery != query else { return }
        query = newQuery
        moreTask?.cancel()
        moreTask = nil
        snapshot = Snapshot()
        publish()
        await reload()
    }

    /// Page 1 again. The rows are replaced on success only.
    public func reload() async {
        guard !isClosed else { return }
        generation += 1
        let current = generation
        moreTask?.cancel()
        moreTask = nil
        snapshot.isLoadingMore = false
        if snapshot.rows.isEmpty {
            if case .failed = snapshot.load { snapshot.load = .loading }
            await showSavedCopy(generation: current)
            guard current == generation, !isClosed else { return }
            if snapshot.rows.isEmpty { startSlowTimer() }
        }
        publish()
        let asked = query
        let answer: (rows: [Row], nextPage: Int?)
        do {
            answer = try await fetch(asked, 1)
        } catch {
            guard !Task.isCancelled, !isClosed, current == generation, error != .network(.cancelled) else { return }
            stopSlowTimer()
            let problem = ThreadProblem(error)
            logFailure("list failed: \(problem.logName)")
            if snapshot.rows.isEmpty {
                snapshot.load = .failed(problem)
            } else if problem == .offline {
                snapshot.isOffline = true
            } else {
                snapshot.problem = problem
            }
            publish()
            return
        }
        guard !Task.isCancelled, !isClosed, current == generation else { return }
        stopSlowTimer()
        snapshot = Snapshot(load: .ready, rows: Self.unique(answer.rows), nextPage: answer.nextPage)
        publish()
        if let cacheKey, asked == cacheableQuery {
            await cache.saveFirstPage(FirstPage(rows: snapshot.rows, nextPage: snapshot.nextPage), key: cacheKey)
        }
    }

    /// The next page, once. Does nothing without a next page or while a page is loading.
    public func loadMore() async {
        guard !isClosed, !snapshot.isLoadingMore, !snapshot.isShowingCachedCopy, let page = snapshot.nextPage else {
            return
        }
        snapshot.isLoadingMore = true
        publish()
        let current = generation
        let asked = query
        let fetch = self.fetch
        let task = Task {
            let result: Result<(rows: [Row], nextPage: Int?), APIFailure>
            do { result = .success(try await fetch(asked, page)) } catch let failure as APIFailure {
                result = .failure(failure)
            } catch { result = .failure(.network(.cancelled)) }
            self.finishMore(generation: current, result: result)
        }
        moreTask = task
        await task.value
    }

    public func close() async {
        guard !isClosed else { return }
        isClosed = true
        moreTask?.cancel()
        slowTask?.cancel()
        moreTask = nil
        slowTask = nil
        broadcast.finish()
    }

    // MARK: Private

    private func finishMore(generation current: Int, result: Result<(rows: [Row], nextPage: Int?), APIFailure>) {
        guard !isClosed, current == generation else { return }
        moreTask = nil
        snapshot.isLoadingMore = false
        switch result {
        case .success(let answer):
            snapshot.rows = Self.unique(snapshot.rows + answer.rows)
            snapshot.nextPage = answer.nextPage
            snapshot.problem = nil
            snapshot.isOffline = false
        case .failure(let failure):
            guard failure != .network(.cancelled) else { break }
            let problem = ThreadProblem(failure)
            logFailure("next page failed: \(problem.logName)")
            snapshot.problem = problem
            if problem == .offline { snapshot.isOffline = true }
        }
        publish()
    }

    /// The saved first page, for the default query only and only before a newer answer.
    private func showSavedCopy(generation current: Int) async {
        guard let cacheKey, query == cacheableQuery else { return }
        let saved: FirstPage<Row>? = await cache.firstPage(cacheKey)
        guard let saved, current == generation, !isClosed, snapshot.rows.isEmpty else { return }
        snapshot.rows = Self.unique(saved.rows)
        snapshot.nextPage = nil
        snapshot.load = .ready
        snapshot.isShowingCachedCopy = true
    }

    private func startSlowTimer() {
        slowTask?.cancel()
        slowTask = Task {
            do { try await self.clock.sleep(for: ThreadEngine.slowAfter) } catch { return }
            guard self.snapshot.load == .loading, self.snapshot.rows.isEmpty else { return }
            self.snapshot.load = .slow
            self.publish()
        }
    }

    private func stopSlowTimer() {
        slowTask?.cancel()
        slowTask = nil
    }

    private static func unique(_ rows: [Row]) -> [Row] {
        var seen: Set<Row.ID> = []
        return rows.filter { seen.insert($0.id).inserted }
    }

    private func publish() {
        broadcast.send(snapshot)
    }
}
