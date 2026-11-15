import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "PreviewSession").error("\(message, privacy: .public)")
    #endif
}

public enum PreviewLoad: Equatable, Sendable {
    case loading, slow, ready
    case failed(ThreadProblem)
}

/// Everything the Preview pane decides on, as one value.
public struct PreviewSnapshot: Equatable, Sendable {
    public var load: PreviewLoad
    /// The last status the server gave, kept through failures.
    public var status: PreviewStatus?
    /// The last status request failed with `.offline`.
    public var isOffline: Bool
    /// The last other failure while a status is kept; cleared by the next success.
    public var problem: ThreadProblem?
    public var isRestoring: Bool
    public var restoreTookTooLong: Bool
    /// A 401, or the app or server is too old: the session stopped calling.
    public var hasEnded: Bool
    /// From the thread snapshot.
    public var canDrive: Bool?
    /// From the thread snapshot.
    public var blockedReason: String?
    /// From the thread snapshot, for `PreviewCard.make`.
    public var sandboxState: String?
    /// The last status url of this pane session that parsed as an http(s) URL; a later null never clears it.
    public var lastPreviewURL: String?

    public init(
        load: PreviewLoad = .loading, status: PreviewStatus? = nil, isOffline: Bool = false,
        problem: ThreadProblem? = nil, isRestoring: Bool = false, restoreTookTooLong: Bool = false,
        hasEnded: Bool = false, canDrive: Bool? = nil, blockedReason: String? = nil,
        sandboxState: String? = nil, lastPreviewURL: String? = nil
    ) {
        self.load = load
        self.status = status
        self.isOffline = isOffline
        self.problem = problem
        self.isRestoring = isRestoring
        self.restoreTookTooLong = restoreTookTooLong
        self.hasEnded = hasEnded
        self.canDrive = canDrive
        self.blockedReason = blockedReason
        self.sandboxState = sandboxState
        self.lastPreviewURL = lastPreviewURL
    }
}

/// One open Preview pane. Asking for the status never wakes a sandbox, so while a transitional state
/// shows, the session also warms it. Every wait goes through the clock, every request through the
/// shared client, and `close()` cancels all of it.
public actor PreviewSession {
    public static let transitionalPoll: Duration = .seconds(2)
    public static let warmInterval: Duration = .seconds(10)
    public static let heartbeatInterval: Duration = .seconds(30)
    public static let socketDownPoll: Duration = .seconds(15)
    public static let failedRetry: Duration = .seconds(5)
    public static let slowAfter: Duration = .seconds(8)
    public static let restoreLimit: Duration = .seconds(600)

    public nonisolated let threadID: String
    /// Whether the Debug test mode's plain loopback previews may load.
    public nonisolated let allowsLoopbackHTTP: Bool

    private struct PlannedTimer {
        let interval: Duration
        let task: Task<Void, Never>
    }

    private let client: APIClient
    private let clock: any YakClock
    private let broadcast = Broadcast(PreviewSnapshot())
    private var state = PreviewSnapshot() {
        didSet { if state != oldValue { broadcast.send(state) } }
    }

    private var isVisible = false
    private var isClosed = false
    private var statusTimer: PlannedTimer?
    private var warmTimer: PlannedTimer?
    private var heartbeatTimer: PlannedTimer?
    private var slowTask: Task<Void, Never>?
    private var restoreLimitTask: Task<Void, Never>?
    private var fetchTasks: [Int: Task<Void, Never>] = [:]
    private var nextFetchNumber = 0
    private var appliedFetchNumber = 0
    private var lastStatusFailed = false

    private var hasRecordedThread = false
    private var recordedThread: ThreadStateChange?
    private var recordedTurn: TurnChange?
    private var connection: ConnectionState = .live
    private var blockedReasonAtRestoreStart: String?
    private var automaticTicketTimes: [Date] = []

    init(threadID: String, client: APIClient, clock: any YakClock, allowsLoopbackHTTP: Bool) {
        self.threadID = threadID
        self.client = client
        self.clock = clock
        self.allowsLoopbackHTTP = allowsLoopbackHTTP
    }

    /// The current value first.
    public func snapshots() -> AsyncStream<PreviewSnapshot> { broadcast.stream() }

    /// A visible pane fetches status at once and keeps its cadence; a hidden one sends nothing.
    public func setVisible(_ visible: Bool) async {
        guard canCall, visible != isVisible else { return }
        isVisible = visible
        if visible {
            startFetch()
        } else {
            cancelSlowTimer()
        }
        replan()
    }

    public func threadChanged(_ snapshot: ConversationSnapshot) async {
        guard canCall else { return }
        let isFirst = !hasRecordedThread
        var next = state
        if let canDrive = snapshot.show?.canDrive { next.canDrive = canDrive }
        if let thread = snapshot.thread {
            next.blockedReason = thread.blockedReason
            next.sandboxState = thread.sandboxState
        } else if let detail = snapshot.show?.thread {
            next.blockedReason = detail.blockedReason
            next.sandboxState = detail.sandboxState
        }
        let threadDiffers = !isFirst && snapshot.thread != recordedThread
        let turnDiffers = !isFirst && snapshot.turn != recordedTurn
        hasRecordedThread = true
        recordedThread = snapshot.thread
        recordedTurn = snapshot.turn
        connection = snapshot.connection
        state = next
        settleRestore()
        replan()
        let setupRuns = state.status?.setup.intent != nil
        if isVisible, threadDiffers || (turnDiffers && setupRuns) { startFetch() }
    }

    /// Status now: Retry, a 425, 502 or 404 page, the network returning.
    public func refresh() async {
        await fetchStatus()
    }

    /// Shows `starting_dev_server` at once and warms now; the next answer replaces it.
    public func tryAgain() async {
        guard canCall else { return }
        guard let status = state.status else {
            await fetchStatus()
            return
        }
        state.status = status.with(state: .startingDevServer)
        warmTimer?.task.cancel()
        warmTimer = nil
        replan()
    }

    public func restore() async -> ThreadActionOutcome {
        if state.hasEnded { return .failed(.needsPairing) }
        guard !isClosed else { return .failed(.offline) }
        do throws(APIFailure) {
            _ = try await client.send(.restorePreview(threadID))
        } catch {
            return await settleRefusedRestore(error)
        }
        guard canCall else { return .done }
        if !state.isRestoring {
            state.isRestoring = true
            state.restoreTookTooLong = false
            blockedReasonAtRestoreStart = state.blockedReason
            restoreLimitTask = Task {
                guard await self.sleepUnlessCancelled(Self.restoreLimit), self.state.isRestoring else { return }
                self.endRestore(tookTooLong: true)
            }
        }
        replan()
        return .done
    }

    /// Cancels every task. Later calls do nothing.
    public func close() async {
        guard !isClosed else { return }
        isClosed = true
        cancelEverything()
        broadcast.finish()
    }

    // MARK: Cadence

    private var canCall: Bool { !isClosed && !state.hasEnded }

    private var isBlockedByFailedRestore: Bool { state.blockedReason == "restore_failed" }

    private func replan() {
        var statusInterval: Duration?
        var warmInterval: Duration?
        var heartbeatInterval: Duration?
        if isVisible, canCall {
            let previewState = state.status?.state
            let isTransitional = previewState?.isTransitional == true
            if lastStatusFailed {
                statusInterval = Self.failedRetry
            } else if state.isRestoring {
                statusInterval = Self.transitionalPoll
            } else if !isBlockedByFailedRestore {
                if isTransitional {
                    statusInterval = Self.transitionalPoll
                } else if previewState == .ready, connection != .live {
                    statusInterval = Self.socketDownPoll
                }
            }
            if !isBlockedByFailedRestore {
                if isTransitional { warmInterval = Self.warmInterval }
                if previewState == .ready { heartbeatInterval = Self.heartbeatInterval }
            }
        }
        statusTimer = adjusted(statusTimer, to: statusInterval) { interval in
            Task {
                while await self.sleepUnlessCancelled(interval) { await self.fetchStatus() }
            }
        }
        warmTimer = adjusted(warmTimer, to: warmInterval) { interval in
            Task {
                repeat {
                    guard self.canCall, !Task.isCancelled else { return }
                    await self.post(.warmPreview(self.threadID))
                } while await self.sleepUnlessCancelled(interval)
            }
        }
        heartbeatTimer = adjusted(heartbeatTimer, to: heartbeatInterval) { interval in
            Task {
                repeat {
                    guard self.canCall, !Task.isCancelled else { return }
                    await self.post(.previewHeartbeat(self.threadID))
                } while await self.sleepUnlessCancelled(interval)
            }
        }
    }

    /// A timer whose interval did not change keeps running, so its next tick is not pushed back.
    private func adjusted(
        _ timer: PlannedTimer?, to interval: Duration?, start: (Duration) -> Task<Void, Never>
    ) -> PlannedTimer? {
        if timer?.interval == interval { return timer }
        timer?.task.cancel()
        guard let interval else { return nil }
        return PlannedTimer(interval: interval, task: start(interval))
    }

    private func sleepUnlessCancelled(_ duration: Duration) async -> Bool {
        do { try await clock.sleep(for: duration) } catch { return false }
        return !Task.isCancelled && canCall
    }

    private func cancelEverything() {
        for timer in [statusTimer, warmTimer, heartbeatTimer] { timer?.task.cancel() }
        statusTimer = nil
        warmTimer = nil
        heartbeatTimer = nil
        slowTask?.cancel()
        slowTask = nil
        restoreLimitTask?.cancel()
        restoreLimitTask = nil
        for task in fetchTasks.values { task.cancel() }
        fetchTasks = [:]
    }

    private func end() {
        guard !state.hasEnded else { return }
        state.hasEnded = true
        cancelEverything()
    }

    private static func endsSession(_ failure: APIFailure) -> Bool {
        switch failure {
        case .unauthorized, .appTooOld, .serverTooOld: true
        default: false
        }
    }

    // MARK: Requests

    private func startFetch() {
        nextFetchNumber += 1
        let number = nextFetchNumber
        fetchTasks[number] = Task {
            await self.fetchStatus()
            self.fetchTasks[number] = nil
        }
    }

    private func fetchStatus() async {
        guard canCall else { return }
        nextFetchNumber += 1
        let number = nextFetchNumber
        startSlowTimer()
        do throws(APIFailure) {
            let status = try await client.send(.previewStatus(threadID))
            guard canCall, number > appliedFetchNumber else { return }
            appliedFetchNumber = number
            apply(status)
        } catch {
            guard canCall, number > appliedFetchNumber else { return }
            if case .network(.cancelled) = error { return }
            appliedFetchNumber = number
            apply(failure: error)
        }
    }

    private func apply(_ status: PreviewStatus) {
        cancelSlowTimer()
        lastStatusFailed = false
        var next = state
        next.status = status
        next.load = .ready
        next.isOffline = false
        next.problem = nil
        if let url = status.url, Self.isWebURL(url) { next.lastPreviewURL = url }
        state = next
        settleRestore()
        replan()
    }

    private func apply(failure: APIFailure) {
        if Self.endsSession(failure) {
            end()
            return
        }
        cancelSlowTimer()
        lastStatusFailed = true
        let problem = ThreadProblem(failure)
        var next = state
        if next.status == nil {
            next.load = .failed(problem)
        } else if problem == .offline {
            next.isOffline = true
            next.problem = nil
        } else {
            next.isOffline = false
            next.problem = problem
        }
        state = next
        logFailure("status failed: \(problem.logName)")
        replan()
    }

    /// Warm and heartbeat have no state of their own; a failure is logged, and only a refused sign-in ends.
    private func post(_ endpoint: Endpoint<EmptyResponse>) async {
        do throws(APIFailure) {
            _ = try await client.send(endpoint)
        } catch {
            if Self.endsSession(error) {
                end()
            } else if case .network(.cancelled) = error {
                return
            } else {
                logFailure("preview request failed: \(ThreadProblem(error).logName)")
            }
        }
    }

    private func startSlowTimer() {
        guard slowTask == nil, state.load == .loading else { return }
        slowTask = Task {
            guard await self.sleepUnlessCancelled(Self.slowAfter), self.state.load == .loading else { return }
            self.state.load = .slow
        }
    }

    private func cancelSlowTimer() {
        slowTask?.cancel()
        slowTask = nil
    }

    private static func isWebURL(_ text: String) -> Bool {
        guard let components = URLComponents(string: text), let scheme = components.scheme?.lowercased(),
            scheme == "http" || scheme == "https", let host = components.host, !host.isEmpty
        else { return false }
        return true
    }

    // MARK: Restore

    /// A restore ends when the state leaves `destroyed`, when `blockedReason` differs from its value at
    /// the start, or after `restoreLimit`.
    private func settleRestore() {
        guard state.isRestoring else { return }
        if let previewState = state.status?.state, previewState != .destroyed {
            endRestore(tookTooLong: false)
        } else if state.blockedReason != blockedReasonAtRestoreStart {
            endRestore(tookTooLong: false)
        }
    }

    private func endRestore(tookTooLong: Bool) {
        restoreLimitTask?.cancel()
        restoreLimitTask = nil
        state.isRestoring = false
        if tookTooLong { state.restoreTookTooLong = true }
        replan()
    }

    private func settleRefusedRestore(_ failure: APIFailure) async -> ThreadActionOutcome {
        if Self.endsSession(failure) {
            end()
            return ThreadActionOutcome(failure)
        }
        if case .server(let error) = failure, error.status == 409, error.code == "nothing_to_restore" {
            await fetchStatus()
            return .done
        }
        let outcome = ThreadActionOutcome(failure)
        if case .refused(let code, _, _) = outcome {
            if state.isRestoring { endRestore(tookTooLong: false) }
            if code == "thread_closed" || code == "thread_owned_by_device" { await fetchStatus() }
        }
        return outcome
    }
}

public enum PreviewTicketReason: Equatable, Sendable {
    /// The first ready load of this pane session.
    case firstLoad
    /// A bounce or a 401 on /_yak/auth.
    case signInAgain
    /// "Try again" on the sign-in card: clears the bound first.
    case personRetry
    /// Open in Safari: never counted.
    case openInBrowser
}

public enum PreviewTicketOutcome: Equatable, Sendable {
    case url(URL)
    /// More than two automatic mints inside 60 seconds.
    case tooManyAttempts
    /// 409 thread_closed, thread_owned_by_device, 422 validation_failed, 503 unavailable.
    case refused(code: String, message: String)
    /// The answer's URL failed `isAcceptedTicket`.
    case unexpectedHost
    case failed(ThreadProblem)
}

extension PreviewSession {
    public static let ticketWindow: Duration = .seconds(60)
    public static let automaticTicketsPerWindow = 2

    /// Mints a single-use ticket for `path` on the preview host. Each call mints a new one and nothing is stored.
    public func ticket(path: String, reason: PreviewTicketReason) async -> PreviewTicketOutcome {
        guard !isClosed else { return .failed(.offline) }
        if state.hasEnded { return .failed(.needsPairing) }
        guard let text = state.lastPreviewURL, let previewURL = URL(string: text) else {
            return .failed(.server(code: "not_ready", message: ""))
        }
        if reason != .openInBrowser {
            let now = clock.now()
            if reason == .personRetry { automaticTicketTimes = [] }
            let window = Double(Self.ticketWindow.components.seconds)
            automaticTicketTimes.removeAll { now.timeIntervalSince($0) >= window }
            if automaticTicketTimes.count >= Self.automaticTicketsPerWindow { return .tooManyAttempts }
            automaticTicketTimes.append(now)
        }
        let host = PreviewNavigation.authority(of: previewURL)
        let normalized = PreviewAddress.normalize(path, previewHost: host)
        let ticket: PreviewTicket
        do throws(APIFailure) {
            ticket = try await client.send(.previewTicket(threadID, path: normalized))
        } catch {
            return await settleRefusedTicket(error)
        }
        guard let url = URL(string: ticket.url),
            PreviewNavigation.isAcceptedTicket(url, previewURL: previewURL, allowsLoopbackHTTP: allowsLoopbackHTTP)
        else {
            logFailure("ticket refused: unexpected host")
            return .unexpectedHost
        }
        return .url(url)
    }

    private func settleRefusedTicket(_ failure: APIFailure) async -> PreviewTicketOutcome {
        if Self.endsSession(failure) {
            end()
            return .failed(ThreadProblem(failure))
        }
        if case .server(let error) = failure {
            if error.status == 503, error.code == "unavailable" {
                return .refused(code: error.code, message: error.message)
            }
            if error.status == 409 || error.status == 422 {
                await fetchStatus()
                return .refused(code: error.code, message: error.message)
            }
        }
        let problem = ThreadProblem(failure)
        logFailure("ticket failed: \(problem.logName)")
        return .failed(problem)
    }
}
