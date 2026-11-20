import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ resource: OverviewResource, _ problem: ThreadProblem) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "OverviewFeed")
            .error("\(String(describing: resource), privacy: .public) failed: \(problem.logName, privacy: .public)")
    #endif
}

private func seconds(_ duration: Duration) -> TimeInterval {
    let parts = duration.components
    return TimeInterval(parts.seconds) + TimeInterval(parts.attoseconds) / 1e18
}

/// Holds the two stream readers a feed starts in its initializer.
private final class TaskBag: @unchecked Sendable {
    private let lock = NSLock()
    private var stored: [Task<Void, Never>] = []

    var tasks: [Task<Void, Never>] {
        get { lock.withLock { stored } }
        set { lock.withLock { stored = newValue } }
    }

    func cancelAll() {
        let all = lock.withLock { () -> [Task<Void, Never>] in
            defer { stored = [] }
            return stored
        }
        for task in all { task.cancel() }
    }
}

public struct ResourceState<Value: Equatable & Sendable>: Equatable, Sendable {
    public var value: Value?
    /// `.loading` before the first answer, `.slow` after 8 seconds, `.ready`, or `.failed` for a refusal that stops the polling or a failure with no value.
    public var load: ThreadLoadState
    /// The newest failure while a value is shown; cleared by the next success.
    public var problem: ThreadProblem?

    public init(value: Value? = nil, load: ThreadLoadState = .loading, problem: ThreadProblem? = nil) {
        self.value = value
        self.load = load
        self.problem = problem
    }
}

public struct OverviewState: Equatable, Sendable {
    public var stack: ResourceState<ThreadStack>
    public var environment: ResourceState<ThreadEnvironment>
    /// The newest request of either resource failed with `APIFailure.network`.
    public var isOffline: Bool
    public var isVisible: Bool

    public init(
        stack: ResourceState<ThreadStack> = ResourceState(),
        environment: ResourceState<ThreadEnvironment> = ResourceState(), isOffline: Bool = false,
        isVisible: Bool = false
    ) {
        self.stack = stack
        self.environment = environment
        self.isOffline = isOffline
        self.isVisible = isVisible
    }
}

/// Keeps the stack and environment of one open thread fresh while the Overview is visible, and reloads them
/// by the contract's rules. Nothing is requested before `setVisible(true)`.
public actor OverviewFeed {
    public static let stackInterval: Duration = .seconds(10)
    public static let environmentInterval: Duration = .seconds(30)
    public static let setupInterval: Duration = .seconds(3)
    public static let eventReloadSpacing: Duration = .seconds(2)
    public static let slowAfter: Duration = .seconds(8)

    private struct Runtime {
        var lastSuccess: Date?
        var lastEventReload: Date?
        /// Set by a 404 or another answer that asking again cannot change; ends the polling.
        var isStopped = false
        /// A reload was asked for while hidden.
        var isDirty = false
        /// Told apart from a run of an earlier visit, which must not schedule anything when it ends.
        var token = 0
        var needsAnother = false
        var running: Task<Void, Never>?
        var interval: Task<Void, Never>?
        var trailing: Task<Void, Never>?
        var slow: Task<Void, Never>?
    }

    public static let defaultCaller = UUID(uuidString: "00000000-0000-0000-0000-000000000000")!

    public nonisolated let threadID: String

    private let client: APIClient
    private let clock: any YakClock
    private let broadcast = Broadcast(OverviewState())
    private var state = OverviewState()
    private var runtimes: [OverviewResource: Runtime] = [.stack: Runtime(), .environment: Runtime()]
    private var isVisible = false
    private var visibleCallers: Set<UUID> = []
    private var isClosed = false
    private var lastSnapshot: ConversationSnapshot?
    private let consumers = TaskBag()

    public init(
        threadID: String, client: APIClient, clock: any YakClock,
        snapshots: AsyncStream<ConversationSnapshot>, changes: AsyncStream<OverviewChange>
    ) {
        self.threadID = threadID
        self.client = client
        self.clock = clock
        consumers.tasks = [
            Task { [weak self] in
                for await snapshot in snapshots {
                    guard let self else { return }
                    await self.receive(snapshot)
                }
            },
            Task { [weak self] in
                for await change in changes {
                    guard let self else { return }
                    await self.receive(change)
                }
            },
        ]
    }

    /// The current state at once, then every change. Finishes when the feed closes.
    public func states() -> AsyncStream<OverviewState> {
        broadcast.stream()
    }

    /// The state as it is now, for a caller that must judge what a `refresh` just loaded without waiting for `states()`.
    public func currentState() -> OverviewState { state }

    /// Visible while any caller is visible: loads what is missing or old at once and polls. Hidden once every caller
    /// is hidden: cancels every timer and request. A caller is whatever shows the feed, such as a pane or a panel.
    public func setVisible(_ requested: Bool, caller: UUID = OverviewFeed.defaultCaller) async {
        if requested { visibleCallers.insert(caller) } else { visibleCallers.remove(caller) }
        let visible = !visibleCallers.isEmpty
        guard visible != isVisible, !isClosed else { return }
        isVisible = visible
        state.isVisible = visible
        guard visible else {
            for resource in Self.all { stopWork(resource) }
            if state.stack.value == nil, state.stack.load == .slow { state.stack.load = .loading }
            if state.environment.value == nil, state.environment.load == .slow { state.environment.load = .loading }
            publish()
            return
        }
        publish()
        for resource in Self.all {
            runtimes[resource]?.isStopped = false
            let age = runtimes[resource]?.lastSuccess.map { clock.now().timeIntervalSince($0) }
            let interval = seconds(interval(for: resource))
            if let age, age < interval, runtimes[resource]?.isDirty == false {
                scheduleNext(resource, after: .seconds(interval - age))
            } else {
                startReload(resource)
            }
        }
    }

    /// Loads now and waits for the answer. Hidden, it only remembers the request for the next visit.
    public func refresh(_ resources: Set<OverviewResource> = [.stack, .environment]) async {
        guard !isClosed else { return }
        guard isVisible else {
            for resource in resources { runtimes[resource]?.isDirty = true }
            return
        }
        for resource in Self.all where resources.contains(resource) {
            runtimes[resource]?.isStopped = false
            if resource == .stack, state.stack.value == nil, case .failed = state.stack.load {
                state.stack.load = .loading
                publish()
            }
            if resource == .environment, state.environment.value == nil, case .failed = state.environment.load {
                state.environment.load = .loading
                publish()
            }
            startReload(resource)
        }
        for resource in Self.all where resources.contains(resource) {
            await runtimes[resource]?.running?.value
        }
    }

    /// Cancels everything and finishes `states()`.
    public func close() async {
        guard !isClosed else { return }
        isClosed = true
        isVisible = false
        consumers.cancelAll()
        for resource in Self.all { stopWork(resource) }
        state.isVisible = false
        broadcast.send(state)
        broadcast.finish()
    }

    private static let all: [OverviewResource] = [.stack, .environment]

    // MARK: Reload requests

    private func receive(_ snapshot: ConversationSnapshot) {
        guard !isClosed else { return }
        let hasSubThreads = state.stack.value.map { !$0.children.isEmpty || !$0.isRoot } ?? false
        let resources = OverviewReloads.after(previous: lastSnapshot, current: snapshot, hasSubThreads: hasSubThreads)
        lastSnapshot = snapshot
        for resource in Self.all where resources.contains(resource) { requestEventReload(resource) }
    }

    private func receive(_ change: OverviewChange) {
        guard !isClosed else { return }
        switch change {
        case .stack(let id): if concerns(id) { requestEventReload(.stack) }
        case .environment(let id): if concerns(id) { requestEventReload(.environment) }
        case .share: break
        }
    }

    /// This thread, its root and parent, or a sub-thread of the loaded stack.
    private func concerns(_ id: String) -> Bool {
        if id == threadID { return true }
        guard let stack = state.stack.value else { return false }
        return id == stack.rootId || id == stack.parent?.id || stack.children.contains { $0.id == id }
    }

    /// At most one reload per spacing: the first at once, later ones collapse into one trailing reload.
    private func requestEventReload(_ resource: OverviewResource) {
        guard isVisible else {
            runtimes[resource]?.isDirty = true
            return
        }
        let now = clock.now()
        if let last = runtimes[resource]?.lastEventReload {
            let elapsed = now.timeIntervalSince(last)
            let spacing = seconds(Self.eventReloadSpacing)
            if elapsed < spacing {
                guard runtimes[resource]?.trailing == nil else { return }
                let wait = Duration.seconds(spacing - elapsed)
                runtimes[resource]?.trailing = Task {
                    do { try await self.clock.sleep(for: wait) } catch { return }
                    guard !Task.isCancelled else { return }
                    self.runtimes[resource]?.trailing = nil
                    self.runtimes[resource]?.lastEventReload = self.clock.now()
                    self.startReload(resource)
                }
                return
            }
        }
        runtimes[resource]?.lastEventReload = now
        startReload(resource)
    }

    // MARK: Loading

    private func interval(for resource: OverviewResource) -> Duration {
        switch resource {
        case .stack: return Self.stackInterval
        case .environment:
            let isSettingUp = state.environment.value?.repositories.contains { $0.setupStatus.isInProgress } ?? false
            return isSettingUp ? Self.setupInterval : Self.environmentInterval
        }
    }

    private func stopWork(_ resource: OverviewResource) {
        guard var runtime = runtimes[resource] else { return }
        runtime.running?.cancel()
        runtime.interval?.cancel()
        runtime.trailing?.cancel()
        runtime.slow?.cancel()
        runtime.running = nil
        runtime.interval = nil
        runtime.trailing = nil
        runtime.slow = nil
        runtime.needsAnother = false
        runtime.token += 1
        runtimes[resource] = runtime
    }

    private func scheduleNext(_ resource: OverviewResource, after delay: Duration? = nil) {
        guard isVisible, !isClosed, runtimes[resource]?.isStopped == false else { return }
        runtimes[resource]?.interval?.cancel()
        let wait = delay ?? interval(for: resource)
        runtimes[resource]?.interval = Task {
            do { try await self.clock.sleep(for: wait) } catch { return }
            guard !Task.isCancelled else { return }
            self.startReload(resource)
        }
    }

    /// Starts a reload, or asks the one that runs to go once more when it ends. A reload restarts the interval.
    private func startReload(_ resource: OverviewResource) {
        guard isVisible, !isClosed, var runtime = runtimes[resource] else { return }
        runtime.interval?.cancel()
        runtime.interval = nil
        runtime.isDirty = false
        if runtime.running != nil {
            runtime.needsAnother = true
            runtimes[resource] = runtime
            return
        }
        runtime.token += 1
        let token = runtime.token
        runtime.running = Task {
            repeat {
                self.runtimes[resource]?.needsAnother = false
                await self.fetch(resource)
            } while self.runtimes[resource]?.needsAnother == true && !Task.isCancelled
            guard self.runtimes[resource]?.token == token else { return }
            self.runtimes[resource]?.running = nil
            self.scheduleNext(resource)
        }
        runtimes[resource] = runtime
    }

    private func fetch(_ resource: OverviewResource) async {
        switch resource {
        case .stack: await load(.stack, into: \.stack, endpoint: .stack(threadID))
        case .environment: await load(.environment, into: \.environment, endpoint: .environment(threadID))
        }
    }

    private func load<Value: Decodable & Equatable & Sendable>(
        _ resource: OverviewResource, into path: WritableKeyPath<OverviewState, ResourceState<Value>>,
        endpoint: Endpoint<Value>
    ) async {
        if state[keyPath: path].value == nil, state[keyPath: path].load == .loading {
            runtimes[resource]?.slow?.cancel()
            runtimes[resource]?.slow = Task {
                do { try await self.clock.sleep(for: Self.slowAfter) } catch { return }
                guard !Task.isCancelled, self.state[keyPath: path].value == nil,
                    self.state[keyPath: path].load == .loading
                else { return }
                self.state[keyPath: path].load = .slow
                self.publish()
            }
        }
        do {
            let value = try await client.send(endpoint)
            guard !Task.isCancelled else { return }
            runtimes[resource]?.slow?.cancel()
            runtimes[resource]?.slow = nil
            runtimes[resource]?.lastSuccess = clock.now()
            runtimes[resource]?.isStopped = false
            state[keyPath: path] = ResourceState(value: value, load: .ready, problem: nil)
            state.isOffline = false
            publish()
        } catch {
            guard !Task.isCancelled else { return }
            let problem = Self.problem(for: error)
            logFailure(resource, problem)
            runtimes[resource]?.slow?.cancel()
            runtimes[resource]?.slow = nil
            runtimes[resource]?.isStopped = !problem.isRetryable
            if case .network = error { state.isOffline = true } else { state.isOffline = false }
            if !problem.isRetryable {
                state[keyPath: path].load = .failed(problem)
                state[keyPath: path].problem = nil
            } else if state[keyPath: path].value == nil {
                state[keyPath: path].load = .failed(problem)
            } else {
                state[keyPath: path].load = .ready
                state[keyPath: path].problem = problem
            }
            publish()
        }
    }

    /// A `5xx` answer is the server's own sentence, not a missing connection, even for `503`.
    private static func problem(for failure: APIFailure) -> ThreadProblem {
        if case .server(let error) = failure, (500...599).contains(error.status) {
            return .server(code: error.code, message: error.message)
        }
        return ThreadProblem(failure)
    }

    private func publish() {
        broadcast.send(state)
    }
}
