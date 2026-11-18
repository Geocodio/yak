import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "PullRequestsFeed").error("\(message, privacy: .public)")
    #endif
}

/// The pull request panel of one thread, polled while the card is visible. The panel is live data and is never
/// stored on the disk.
public actor PullRequestsFeed {
    public typealias Snapshot = (load: ThreadLoadState, panel: PullRequestsPanel?, problem: ThreadProblem?)

    public static let pollInterval: Duration = .seconds(10)

    private let threadID: String
    private let client: APIClient
    private let clock: any YakClock
    private let broadcast: Broadcast<Snapshot>

    private var load: ThreadLoadState = .loading
    private var panel: PullRequestsPanel?
    private var problem: ThreadProblem?
    private var isVisible = false
    private var isClosed = false
    /// Counts the requests started, so an answer to an overtaken request is dropped.
    private var generation = 0
    private var pollTask: Task<Void, Never>?
    private var slowTask: Task<Void, Never>?
    /// True while a poll loop is running; a loop that stops on a problem that retrying cannot fix clears it.
    private var isPolling = false

    public init(threadID: String, client: APIClient, clock: any YakClock) {
        self.threadID = threadID
        self.client = client
        self.clock = clock
        broadcast = Broadcast((load: .loading, panel: nil, problem: nil))
    }

    public func snapshots() -> AsyncStream<Snapshot> {
        broadcast.stream()
    }

    /// Visible: loads now and every 10 seconds. Hidden: cancels the polling. Repeating a value changes nothing.
    public func setVisible(_ visible: Bool) async {
        guard visible != isVisible, !isClosed else { return }
        isVisible = visible
        guard visible else {
            pollTask?.cancel()
            pollTask = nil
            isPolling = false
            slowTask?.cancel()
            slowTask = nil
            return
        }
        startPolling(fetchFirst: true)
    }

    private func startPolling(fetchFirst: Bool) {
        isPolling = true
        pollTask = Task {
            if fetchFirst { await self.fetch() }
            while !Task.isCancelled, self.shouldKeepPolling {
                do { try await self.clock.sleep(for: Self.pollInterval) } catch { return }
                guard !Task.isCancelled else { return }
                await self.fetch()
            }
            if !Task.isCancelled { self.isPolling = false }
        }
    }

    /// Loads now and waits for the answer, visible or not. Returns the panel this load fetched, or nil when it
    /// failed or was overtaken.
    @discardableResult
    public func reload() async -> PullRequestsPanel? {
        guard !isClosed else { return nil }
        if panel == nil, case .failed = load {
            load = .loading
            publish()
        }
        let fetched = await fetch()
        if isVisible, !isClosed, !isPolling, problem?.isRetryable ?? true { startPolling(fetchFirst: false) }
        return fetched
    }

    /// Cancels the polling and ends the streams. Repeating it changes nothing.
    public func close() async {
        guard !isClosed else { return }
        isClosed = true
        pollTask?.cancel()
        slowTask?.cancel()
        pollTask = nil
        slowTask = nil
        broadcast.finish()
    }

    private var shouldKeepPolling: Bool {
        if let problem, !problem.isRetryable { return false }
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

    @discardableResult
    private func fetch() async -> PullRequestsPanel? {
        generation += 1
        let current = generation
        if panel == nil, load == .loading { startSlowTimer() }
        let fetched: PullRequestsPanel
        do {
            fetched = try await client.send(.pullRequests(threadID))
        } catch {
            guard !Task.isCancelled, !isClosed, current == generation, error != .network(.cancelled) else { return nil }
            slowTask?.cancel()
            slowTask = nil
            let failure = ThreadProblem(error)
            logFailure("pull request panel failed: \(failure.logName)")
            problem = failure
            if panel == nil { load = .failed(failure) }
            publish()
            return nil
        }
        guard !Task.isCancelled, !isClosed, current == generation else { return nil }
        slowTask?.cancel()
        slowTask = nil
        panel = fetched
        problem = nil
        load = .ready
        publish()
        return fetched
    }

    private func publish() {
        broadcast.send((load: load, panel: panel, problem: problem))
    }
}
