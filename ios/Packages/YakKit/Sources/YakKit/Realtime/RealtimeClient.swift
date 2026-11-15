import Foundation

#if canImport(os)
    import os
#endif

public enum ConnectionState: Equatable, Sendable { case live, reconnecting, offline }

public typealias ChannelAuthorizer =
    @Sendable (_ socketID: String, _ channelName: String) async throws(APIFailure) -> ChannelAuthorization

/// One Pusher connection to the instance's Reverb server: handshake, ping, and reconnects with backoff.
public actor RealtimeClient {
    public static let backoff: [Duration] = [.seconds(1), .seconds(2), .seconds(4), .seconds(8), .seconds(15)]

    private static let handshakeTimeout: Duration = .seconds(15)
    private static let pongTimeout: Duration = .seconds(10)

    private enum Phase { case handshake, quiet, awaitingPong }
    private enum Outcome { case dropped, permanent, immediate }

    private let configuration: RealtimeConfiguration?
    private let connector: any WebSocketConnector
    private let authorizer: ChannelAuthorizer
    private let clock: any YakClock
    private let stateBroadcast = Broadcast<ConnectionState>(.offline)

    private var generation = 0
    private var runTask: Task<Void, Never>?
    private var backoffTask: Task<Void, Never>?
    private var networkWaiter: CheckedContinuation<Void, Never>?
    private var connection: (any WebSocketConnection)?
    private var timerTask: Task<Void, Never>?
    private var phase = Phase.handshake
    private var activityTimeout: Duration = .seconds(30)
    private var attempt = 0
    private var hasNetwork = true
    private var currentSocketID: String?
    private var sessionOutcome: Outcome?

    public init(
        configuration: RealtimeConfiguration?, connector: any WebSocketConnector,
        authorizer: @escaping ChannelAuthorizer, clock: any YakClock
    ) {
        self.configuration = configuration
        self.connector = connector
        self.authorizer = authorizer
        self.clock = clock
    }

    /// Does nothing without a configuration (the engines then poll) and when already started.
    public func start() {
        guard configuration != nil, runTask == nil else { return }
        generation += 1
        let current = generation
        attempt = 0
        runTask = Task { await self.run(generation: current) }
    }

    /// Closes the socket, sets `offline` and stops reconnecting.
    public func stop() async {
        let task = runTask
        runTask = nil
        generation += 1
        task?.cancel()
        backoffTask?.cancel()
        timerTask?.cancel()
        wakeNetworkWaiter()
        // State is cleared before the first suspension so a start() that runs during the awaits owns
        // a clean slate.
        let closing = connection
        connection = nil
        currentSocketID = nil
        publish(.offline)
        await closing?.close()
        await task?.value
    }

    public func states() -> AsyncStream<ConnectionState> {
        stateBroadcast.stream()
    }

    public func socketID() -> String? { currentSocketID }

    /// Unsatisfied: stops the attempts and shows `offline`, closing nothing. Satisfied: skips any
    /// backoff wait and tries at once with the backoff restarted.
    public func networkPathChanged(isSatisfied: Bool) {
        hasNetwork = isSatisfied
        guard runTask != nil else { return }
        if !isSatisfied {
            backoffTask?.cancel()
            publish(.offline)
            return
        }
        attempt = 0
        backoffTask?.cancel()
        wakeNetworkWaiter()
        publish(currentSocketID != nil ? .live : .reconnecting)
    }

    // MARK: - Run loop

    /// Broadcasts only a change, so subscribers never see the same state twice in a row.
    private func publish(_ state: ConnectionState) {
        if stateBroadcast.value != state { stateBroadcast.send(state) }
    }

    private func isCurrent(_ generation: Int) -> Bool {
        generation == self.generation && !Task.isCancelled
    }

    private func run(generation: Int) async {
        while isCurrent(generation) {
            if !hasNetwork {
                await withCheckedContinuation { networkWaiter = $0 }
                continue
            }
            publish(.reconnecting)
            let outcome = await attemptConnection(generation: generation)
            guard isCurrent(generation) else { break }
            switch outcome {
            case .permanent:
                publish(.offline)
                finish(generation: generation)
                return
            case .immediate:
                continue
            case .dropped:
                guard hasNetwork else {
                    publish(.offline)
                    continue
                }
                publish(.reconnecting)
                let wait = Self.backoff[min(attempt, Self.backoff.count - 1)]
                attempt += 1
                await backoffWait(wait)
            }
        }
        finish(generation: generation)
    }

    private func finish(generation: Int) {
        if generation == self.generation { runTask = nil }
    }

    private func wakeNetworkWaiter() {
        let waiter = networkWaiter
        networkWaiter = nil
        waiter?.resume()
    }

    private func backoffWait(_ duration: Duration) async {
        let clock = clock
        let task = Task {
            // A cancelled wait is how the network coming back or stop() cuts the wait short.
            do { try await clock.sleep(for: duration) } catch {}
        }
        backoffTask = task
        await task.value
        if backoffTask == task { backoffTask = nil }
    }

    // MARK: - One connection

    private func attemptConnection(generation: Int) async -> Outcome {
        guard let configuration else { return .permanent }
        phase = .handshake
        sessionOutcome = nil
        let opened: any WebSocketConnection
        do {
            opened = try await connector.connect(to: configuration.url)
        } catch {
            log("connect failed: \(type(of: error))")
            return .dropped
        }
        guard isCurrent(generation) else {
            await opened.close()
            return .dropped
        }
        connection = opened
        armTimer(Self.handshakeTimeout, generation: generation)
        while true {
            let text: String
            do {
                text = try await opened.receive()
            } catch {
                log("receive failed: \(type(of: error))")
                break
            }
            guard isCurrent(generation) else { break }
            await handle(text, on: opened, generation: generation)
            if sessionOutcome != nil { break }
        }
        let wasEstablished = currentSocketID != nil
        if generation == self.generation {
            timerTask?.cancel()
            currentSocketID = nil
            connection = nil
        }
        await opened.close()
        if wasEstablished, isCurrent(generation), hasNetwork { publish(.reconnecting) }
        return sessionOutcome ?? .dropped
    }

    private func handle(_ text: String, on opened: any WebSocketConnection, generation: Int) async {
        guard let frame = PusherFrame.decode(text) else { return }
        switch frame.event {
        case "pusher:connection_established":
            guard phase == .handshake, let established = Self.parseEstablished(frame.data) else {
                if phase == .handshake { sessionOutcome = .dropped }
                return
            }
            currentSocketID = established.socketID
            activityTimeout = .seconds(min(established.activityTimeout, 30))
            attempt = 0
            if hasNetwork { publish(.live) }
        case "pusher:ping":
            do { try await opened.send(PusherFrame(event: "pusher:pong").encoded()) } catch {
                log("pong send failed: \(type(of: error))")
                sessionOutcome = .dropped
                return
            }
        case "pusher:error":
            sessionOutcome = Self.outcome(forErrorData: frame.data)
            return
        default:
            break
        }
        if currentSocketID != nil {
            phase = .quiet
            armTimer(activityTimeout, generation: generation)
        }
    }

    private static func parseEstablished(_ data: String?) -> (socketID: String, activityTimeout: Double)? {
        guard let bytes = data?.data(using: .utf8),
            let object = try? JSONSerialization.jsonObject(with: bytes) as? [String: Any],
            let socketID = object["socket_id"] as? String, !socketID.isEmpty
        else { return nil }
        let reported = (object["activity_timeout"] as? NSNumber)?.doubleValue ?? 30
        return (socketID, reported.isFinite ? max(1, reported) : 30)
    }

    private static func outcome(forErrorData data: String?) -> Outcome {
        guard let bytes = data?.data(using: .utf8),
            let object = try? JSONSerialization.jsonObject(with: bytes) as? [String: Any],
            let code = (object["code"] as? NSNumber)?.doubleValue
        else { return .dropped }
        switch code {
        case 4000...4099: return .permanent
        case 4200...4299: return .immediate
        default: return .dropped
        }
    }

    // MARK: - Timers

    private func armTimer(_ duration: Duration, generation: Int) {
        timerTask?.cancel()
        let clock = clock
        timerTask = Task {
            do { try await clock.sleep(for: duration) } catch { return }
            await self.timerFired(generation: generation)
        }
    }

    private func timerFired(generation: Int) async {
        guard isCurrent(generation), let connection else { return }
        switch phase {
        case .handshake:
            sessionOutcome = .dropped
            await connection.close()
        case .quiet:
            phase = .awaitingPong
            armTimer(Self.pongTimeout, generation: generation)
            do { try await connection.send(PusherFrame(event: "pusher:ping").encoded()) } catch {
                log("ping send failed: \(type(of: error))")
                sessionOutcome = .dropped
                await connection.close()
            }
        case .awaitingPong:
            sessionOutcome = .dropped
            await connection.close()
        }
    }

    private func log(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "YakKit", category: "RealtimeClient").error("\(message, privacy: .public)")
        #endif
    }
}
