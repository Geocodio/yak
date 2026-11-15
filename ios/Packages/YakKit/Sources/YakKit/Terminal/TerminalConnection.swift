import Foundation

#if canImport(os)
    import os
#endif

/// Logs a phase or close code by case name only: never a URL, a token, a message or a byte.
private func logTerminal(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "TerminalConnection").info("\(message, privacy: .public)")
    #endif
}

public struct TerminalTimings: Equatable, Sendable {
    public var waitPoll: Duration = .seconds(2)
    public var waitCap: Duration = .seconds(120)
    /// The cap while a restore runs: the restore job itself allows 900 seconds.
    public var restoreCap: Duration = .seconds(900)
    /// How long after this app's own Restore a waiting answer without `restoring` still counts as restoring.
    public var restoreHint: Duration = .seconds(900)
    public var slowAfterPaused: Duration = .seconds(8)
    public var slowAfterStopped: Duration = .seconds(30)
    public var handshake: Duration = .seconds(15)
    public var backoff: [Duration] = [.seconds(1), .seconds(2), .seconds(4), .seconds(8)]
    public var maximumTries = 5

    public init() {}

    public static let standard = TerminalTimings()

    /// The app's Debug test mode: the same rules with a 6-second wait cap and a 12-second restore cap.
    public static let shortWaits: TerminalTimings = {
        var timings = TerminalTimings()
        timings.waitCap = .seconds(6)
        timings.restoreCap = .seconds(12)
        return timings
    }()
}

public enum TerminalStep: Equatable, Sendable { case sandbox, link, shell }

public enum TerminalRefusal: Equatable, Sendable {
    case originNotAllowed(message: String)
    case forbidden
    case threadClosed
    case onComputer
    case sandboxRemoved
    case noSandbox
    case restoreFailed(message: String)
    case blocked(reason: String, message: String)
    case other(code: String, message: String)
}

public enum TerminalPhase: Equatable, Sendable {
    case idle
    case opening(step: TerminalStep, since: Date, isSlow: Bool)
    case waitingForSlot(limit: TerminalWaitLimit?, since: Date)
    case waitedTooLong
    /// Waiting while a restore is queued or runs.
    case restoring(since: Date)
    /// The restore cap passed.
    case stillRestoring
    case connected
    /// `attempt` runs from 1 to `maximumTries`.
    case lost(attempt: Int, retryAt: Date)
    case couldNotReconnect
    case offline
    case ended(exitCode: Int?)
    case idleClosed
    case tooMany
    case denied
    case broken
    case refused(TerminalRefusal)
    /// A network or server failure of the request.
    case failed(ThreadProblem)

    /// The case name only, so it is safe to log.
    var logName: String { String("\(self)".prefix { $0 != "(" }) }
}

public struct TerminalSnapshot: Equatable, Sendable {
    public var phase: TerminalPhase
    /// True after a key was dropped because the shell was not connected; cleared on the next connect.
    public var droppedKeys: Bool
    public var columns: Int
    public var rows: Int

    public init(phase: TerminalPhase, droppedKeys: Bool, columns: Int, rows: Int) {
        self.phase = phase
        self.droppedKeys = droppedKeys
        self.columns = columns
        self.rows = rows
    }
}

public enum TerminalOutput: Equatable, Sendable {
    case bytes(Data)
    /// A reconnect opened a new shell; the old one stopped at `droppedAt`.
    case newShell(droppedAt: Date)
}

/// One shell: it asks for a session, waits for a slot, opens the socket, reconnects when the connection is
/// lost and publishes one phase at a time. Subscribe to `output()` before `start`: output is not replayed.
public actor TerminalConnection {
    private enum AttemptResult {
        /// The attempt ended in a final phase.
        case finished(TerminalPhase)
        /// The connection or the handshake failed in a way a new try can fix.
        case lost(hadOutput: Bool)
    }

    private let threadID: String
    private let client: APIClient
    private let origin: String
    private let connector: any TerminalSocketConnector
    private let clock: any YakClock
    private let allowsInsecure: Bool
    private let sandboxState: String
    private let timings: TerminalTimings

    private let phases: Broadcast<TerminalSnapshot>
    private var snapshot: TerminalSnapshot
    private var outputReceivers: [Int: AsyncStream<TerminalOutput>.Continuation] = [:]
    private var nextReceiver = 0

    private var isStarted = false
    private var isClosed = false
    private var isSuspended = false
    private var isNetworkOnline = true
    private var restoreHintUntil: Date?
    /// Bumped whenever the running attempt is replaced or stopped; a stale attempt changes nothing.
    private var generation = 0
    private var driver: Task<Void, Never>?
    private var slowTimer: Task<Void, Never>?
    private var socket: (any TerminalSocket)?
    private var socketSize: (columns: Int, rows: Int) = (0, 0)
    private var socketHadOutput = false
    private var didFirstOutputTimeOut = false
    /// When the shell the person was looking at stopped, until the next shell announces itself.
    private var droppedAt: Date?

    public init(
        threadID: String, client: APIClient, origin: String, connector: any TerminalSocketConnector,
        clock: any YakClock, allowsInsecure: Bool, sandboxState: String, timings: TerminalTimings = .standard
    ) {
        self.threadID = threadID
        self.client = client
        self.origin = origin
        self.connector = connector
        self.clock = clock
        self.allowsInsecure = allowsInsecure
        self.sandboxState = sandboxState
        self.timings = timings
        let initial = TerminalSnapshot(phase: .idle, droppedKeys: false, columns: 80, rows: 24)
        snapshot = initial
        phases = Broadcast(initial)
    }

    public func snapshots() -> AsyncStream<TerminalSnapshot> { phases.stream() }

    public func output() -> AsyncStream<TerminalOutput> {
        let (stream, continuation) = AsyncStream<TerminalOutput>.makeStream(bufferingPolicy: .unbounded)
        guard !isClosed else {
            continuation.finish()
            return stream
        }
        nextReceiver += 1
        let identifier = nextReceiver
        outputReceivers[identifier] = continuation
        continuation.onTermination = { [weak self] _ in
            Task { await self?.removeReceiver(identifier) }
        }
        return stream
    }

    /// Starts once; a second call changes nothing.
    public func start(columns: Int, rows: Int) async {
        guard !isStarted, !isClosed else { return }
        isStarted = true
        update {
            $0.columns = columns
            $0.rows = rows
        }
        startDriver()
    }

    /// Sent only while connected; otherwise dropped and `droppedKeys` set.
    public func send(_ bytes: [UInt8]) async {
        guard !isClosed else { return }
        guard snapshot.phase == .connected, let socket else {
            update { $0.droppedKeys = true }
            return
        }
        do {
            try await socket.send(bytes: Data(bytes))
        } catch {
            // The reader reports the close; the keys of this write are lost.
            update { $0.droppedKeys = true }
        }
    }

    /// Remembered for the next connect; sent as a resize frame while connected, only when it changed.
    public func resize(columns: Int, rows: Int) async {
        guard !isClosed else { return }
        update {
            $0.columns = columns
            $0.rows = rows
        }
        await sendResizeIfChanged()
    }

    /// Retry, New shell or Reconnect: from any phase but `connected`, a fresh `opening`, `waitingForSlot` and
    /// `restoring`, asks again. A slow `opening` can be retried.
    public func reconnectNow() async {
        guard isStarted, !isClosed else { return }
        switch snapshot.phase {
        case .opening(_, _, true): break
        case .connected, .opening, .waitingForSlot, .restoring: return
        default: break
        }
        await restartDriver()
    }

    /// This app's Restore was accepted: for `restoreHint` from now, a waiting answer counts as restoring even
    /// without the body's `restoring` key. From `.refused(.sandboxRemoved)` it also asks again at once.
    public func restoreStarted() async {
        guard !isClosed else { return }
        restoreHintUntil = clock.now().addingTimeInterval(timings.restoreHint.timeInterval)
        if isStarted, snapshot.phase == .refused(.sandboxRemoved) { await restartDriver() }
    }

    public func networkChanged(isOnline: Bool) async {
        guard !isClosed else { return }
        isNetworkOnline = isOnline
        if !isOnline {
            await goOffline()
        } else if snapshot.phase == .offline, !isSuspended, isStarted {
            await restartDriver()
        }
    }

    /// The app's background time ran out: closes the socket with 1000. `resume()` reconnects as a new shell.
    public func suspend() async {
        guard !isClosed else { return }
        isSuspended = true
        await goOffline()
    }

    public func resume() async {
        guard !isClosed, isSuspended else { return }
        isSuspended = false
        if snapshot.phase == .offline, isNetworkOnline, isStarted { await restartDriver() }
    }

    public func close() async {
        guard !isClosed else { return }
        isClosed = true
        await stopDriver()
        phases.finish()
        for receiver in outputReceivers.values { receiver.finish() }
        outputReceivers = [:]
    }

    // MARK: State

    private func update(_ change: (inout TerminalSnapshot) -> Void) {
        var next = snapshot
        change(&next)
        guard next != snapshot else { return }
        if next.phase != snapshot.phase { logTerminal("phase \(next.phase.logName)") }
        snapshot = next
        phases.send(next)
    }

    /// Sets the phase for the attempt `owner`; a replaced attempt changes nothing.
    private func setPhase(_ phase: TerminalPhase, owner: Int) {
        guard owner == generation, !isClosed else { return }
        update {
            $0.phase = phase
            if phase == .connected { $0.droppedKeys = false }
        }
    }

    private func removeReceiver(_ identifier: Int) {
        outputReceivers[identifier] = nil
    }

    private func emit(_ output: TerminalOutput, owner: Int) {
        guard owner == generation, !isClosed else { return }
        for receiver in outputReceivers.values { receiver.yield(output) }
    }

    // MARK: Driver

    private func startDriver() {
        generation += 1
        let owner = generation
        driver = Task { await self.drive(owner: owner) }
    }

    /// Returns the generation this stop created; if it differs afterwards, another call replaced the attempt.
    @discardableResult
    private func stopDriver() async -> Int {
        generation += 1
        let stopped = generation
        driver?.cancel()
        driver = nil
        slowTimer?.cancel()
        slowTimer = nil
        let open = socket
        socket = nil
        await open?.close()
        return stopped
    }

    private func restartDriver() async {
        await stopDriver()
        guard !isClosed else { return }
        startDriver()
    }

    /// Closes the socket and cancels any request or wait; a live shell's stop time is kept for the divider.
    private func goOffline() async {
        guard isStarted else { return }
        switch snapshot.phase {
        case .idle, .waitedTooLong, .stillRestoring, .couldNotReconnect, .ended, .idleClosed, .tooMany, .denied,
            .broken, .refused, .failed, .offline:
            return
        case .opening, .waitingForSlot, .restoring, .connected, .lost:
            break
        }
        if socket != nil, socketHadOutput, droppedAt == nil { droppedAt = clock.now() }
        let stopped = await stopDriver()
        setPhase(.offline, owner: stopped)
    }

    private func drive(owner: Int) async {
        var tries = 0
        while owner == generation, !Task.isCancelled {
            let result = await attempt(owner: owner)
            guard owner == generation, !Task.isCancelled else { return }
            switch result {
            case .finished(let phase):
                setPhase(phase, owner: owner)
                return
            case .lost(let hadOutput):
                if hadOutput { tries = 0 }
                tries += 1
                guard tries <= timings.maximumTries else {
                    setPhase(.couldNotReconnect, owner: owner)
                    return
                }
                let delay = timings.backoff[min(tries - 1, timings.backoff.count - 1)]
                setPhase(
                    .lost(attempt: tries, retryAt: clock.now().addingTimeInterval(delay.timeInterval)), owner: owner)
                do { try await clock.sleep(for: delay) } catch { return }
            }
        }
    }

    // MARK: One attempt

    private func attempt(owner: Int) async -> AttemptResult {
        let began = clock.now()
        setPhase(.opening(step: .sandbox, since: began, isSlow: false), owner: owner)
        startSlowTimer(since: began, owner: owner)
        var slotWaitStart: Date?
        var restoreWaitStart: Date?
        let session: TerminalSession
        while true {
            let answer: TerminalSessionAnswer
            do {
                answer = try await client.send(.openTerminalSession(threadID, origin: origin))
            } catch {
                cancelSlowTimer()
                guard owner == generation else { return .finished(.idle) }
                return failure(error)
            }
            cancelSlowTimer()
            guard owner == generation else { return .finished(.idle) }
            switch answer {
            case .ready(let ready):
                session = ready
            case .waiting(let limit, let restoring):
                let now = clock.now()
                if restoring || (restoreHintUntil.map { now < $0 } ?? false) {
                    slotWaitStart = nil
                    let start = restoreWaitStart ?? now
                    restoreWaitStart = start
                    guard now.timeIntervalSince(start) < timings.restoreCap.timeInterval else {
                        return .finished(.stillRestoring)
                    }
                    setPhase(.restoring(since: start), owner: owner)
                } else {
                    restoreWaitStart = nil
                    let start = slotWaitStart ?? now
                    slotWaitStart = start
                    guard now.timeIntervalSince(start) < timings.waitCap.timeInterval else {
                        return .finished(.waitedTooLong)
                    }
                    setPhase(.waitingForSlot(limit: limit, since: start), owner: owner)
                }
                do { try await clock.sleep(for: timings.waitPoll) } catch { return .finished(.idle) }
                guard owner == generation else { return .finished(.idle) }
                continue
            }
            break
        }
        return await openSocket(session, owner: owner)
    }

    private func startSlowTimer(since: Date, owner: Int) {
        slowTimer?.cancel()
        let threshold = sandboxState == "stopped" ? timings.slowAfterStopped : timings.slowAfterPaused
        slowTimer = Task {
            do { try await clock.sleep(for: threshold) } catch { return }
            self.markSlow(owner: owner)
        }
    }

    private func cancelSlowTimer() {
        slowTimer?.cancel()
        slowTimer = nil
    }

    private func markSlow(owner: Int) {
        guard owner == generation, case .opening(.sandbox, let since, false) = snapshot.phase else { return }
        setPhase(.opening(step: .sandbox, since: since, isSlow: true), owner: owner)
    }

    /// Maps a failed request to the attempt's outcome.
    private func failure(_ failure: APIFailure) -> AttemptResult {
        if case .network = failure, !isNetworkOnline { return .finished(.offline) }
        guard case .server(let error) = failure else { return .finished(.failed(ThreadProblem(failure))) }
        switch error.code {
        case "origin_not_allowed": return .finished(.refused(.originNotAllowed(message: error.message)))
        case "forbidden": return .finished(.refused(.forbidden))
        case "thread_closed": return .finished(.refused(.threadClosed))
        case "thread_owned_by_device": return .finished(.refused(.onComputer))
        case "sandbox_destroyed": return .finished(.refused(.sandboxRemoved))
        case "no_sandbox": return .finished(.refused(.noSandbox))
        case "thread_blocked":
            let reason = error.details["reason"]?.stringValue ?? "unknown"
            return .finished(
                .refused(
                    reason == "restore_failed"
                        ? .restoreFailed(message: error.message) : .blocked(reason: reason, message: error.message)))
        default:
            if (400..<500).contains(error.status) {
                return .finished(.refused(.other(code: error.code, message: error.message)))
            }
            return .finished(.failed(ThreadProblem(failure)))
        }
    }

    private func openSocket(_ session: TerminalSession, owner: Int) async -> AttemptResult {
        guard var components = URLComponents(string: session.url),
            let scheme = components.scheme?.lowercased(), scheme == "wss" || (scheme == "ws" && allowsInsecure),
            components.host?.isEmpty == false
        else {
            return .finished(
                .failed(
                    .server(
                        code: "bad_terminal_url", message: "The server sent a terminal address the app cannot use.")))
        }
        let size = (columns: snapshot.columns, rows: snapshot.rows)
        components.queryItems =
            (components.queryItems ?? []) + [
                URLQueryItem(name: "cols", value: String(size.columns)),
                URLQueryItem(name: "rows", value: String(size.rows)),
            ]
        guard let url = components.url else {
            return .finished(
                .failed(
                    .server(
                        code: "bad_terminal_url", message: "The server sent a terminal address the app cannot use.")))
        }
        setPhase(.opening(step: .link, since: clock.now(), isSlow: false), owner: owner)
        let opened: any TerminalSocket
        do {
            opened = try await connector.connect(to: url, origin: origin, handshakeTimeout: timings.handshake)
        } catch {
            return .lost(hadOutput: false)
        }
        guard owner == generation, !isClosed else {
            await opened.close()
            return .finished(.idle)
        }
        socket = opened
        socketSize = size
        socketHadOutput = false
        setPhase(.opening(step: .shell, since: clock.now(), isSlow: false), owner: owner)
        if let dropped = droppedAt {
            droppedAt = nil
            emit(.newShell(droppedAt: dropped), owner: owner)
        }
        await sendResizeIfChanged()
        return await read(opened, owner: owner)
    }

    /// A shell that accepts the socket but prints nothing counts as a lost try.
    private func firstOutputTimedOut(owner: Int) async {
        guard owner == generation, !socketHadOutput, let open = socket else { return }
        didFirstOutputTimeOut = true
        await open.close()
    }

    private func sendResizeIfChanged() async {
        guard let socket, snapshot.columns != socketSize.columns || snapshot.rows != socketSize.rows else { return }
        socketSize = (snapshot.columns, snapshot.rows)
        do {
            try await socket.send(text: BridgeControl.resize(cols: snapshot.columns, rows: snapshot.rows))
        } catch {
            // The reader reports the close; the next shell starts at the remembered size.
        }
    }

    private func read(_ opened: any TerminalSocket, owner: Int) async -> AttemptResult {
        var exitCode: Int?
        var sawExit = false
        didFirstOutputTimeOut = false
        let firstOutputTimer = Task {
            do { try await clock.sleep(for: timings.handshake) } catch { return }
            await self.firstOutputTimedOut(owner: owner)
        }
        defer { firstOutputTimer.cancel() }
        while true {
            do {
                let frame = try await opened.receive()
                guard owner == generation else { return .finished(.idle) }
                switch frame {
                case .output(let data):
                    if !socketHadOutput {
                        socketHadOutput = true
                        setPhase(.connected, owner: owner)
                    }
                    emit(.bytes(data), owner: owner)
                case .control(let text):
                    if case .exit(let code) = BridgeMessage.parse(text) {
                        sawExit = true
                        exitCode = code
                    }
                }
            } catch {
                guard owner == generation else { return .finished(.idle) }
                let code = (error as? TerminalSocketClosed)?.code
                let hadOutput = socketHadOutput
                let timedOut = didFirstOutputTimeOut
                didFirstOutputTimeOut = false
                socket = nil
                await opened.close()
                logTerminal("closed \(code.map(String.init) ?? "none")")
                if timedOut { return .lost(hadOutput: false) }
                if hadOutput, droppedAt == nil { droppedAt = clock.now() }
                switch TerminalCloseKind.classify(code: code, sawExit: sawExit) {
                case .ended: return .finished(.ended(exitCode: exitCode))
                case .idle: return .finished(.idleClosed)
                case .tooMany: return .finished(.tooMany)
                case .denied: return .finished(.denied)
                case .broken: return .finished(.broken)
                case .lost:
                    return .lost(hadOutput: hadOutput)
                }
            }
        }
    }
}

extension Duration {
    fileprivate var timeInterval: TimeInterval {
        let parts = components
        return TimeInterval(parts.seconds) + TimeInterval(parts.attoseconds) / 1e18
    }
}
