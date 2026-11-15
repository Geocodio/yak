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
        await connection?.close()
        await task?.value
        connection = nil
        currentSocketID = nil
        stateBroadcast.send(.offline)
    }

    public func states() -> AsyncStream<ConnectionState> {
        stateBroadcast.stream()
    }

    public func socketID() -> String? { currentSocketID }

    /// Unsatisfied: stops the attempts and shows `offline`, closing nothing. Satisfied: skips any
    /// backoff wait and tries at once with the backoff restarted.
    public func networkPathChanged(isSatisfied: Bool) {
        guard runTask != nil else { return }
        hasNetwork = isSatisfied
        if !isSatisfied {
            backoffTask?.cancel()
            stateBroadcast.send(.offline)
            return
        }
        attempt = 0
        backoffTask?.cancel()
        wakeNetworkWaiter()
        if currentSocketID != nil { stateBroadcast.send(.live) }
    }

    // MARK: - Run loop

    private func isCurrent(_ generation: Int) -> Bool {
        generation == self.generation && !Task.isCancelled
    }

    private func run(generation: Int) async {
        while isCurrent(generation) {
            if !hasNetwork {
                await withCheckedContinuation { networkWaiter = $0 }
                continue
            }
            stateBroadcast.send(.reconnecting)
            let outcome = await attemptConnection(generation: generation)
            guard isCurrent(generation) else { break }
            switch outcome {
            case .permanent:
                stateBroadcast.send(.offline)
                finish(generation: generation)
                return
            case .immediate:
                continue
            case .dropped:
                guard hasNetwork else {
                    stateBroadcast.send(.offline)
                    continue
                }
                stateBroadcast.send(.reconnecting)
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
        backoffTask = nil
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
            log("connect failed")
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
                break
            }
            guard isCurrent(generation) else { break }
            await handle(text, on: opened, generation: generation)
            if sessionOutcome != nil { break }
        }
        timerTask?.cancel()
        let wasEstablished = currentSocketID != nil
        currentSocketID = nil
        connection = nil
        await opened.close()
        if wasEstablished, isCurrent(generation), hasNetwork { stateBroadcast.send(.reconnecting) }
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
            if hasNetwork { stateBroadcast.send(.live) }
        case "pusher:ping":
            do { try await opened.send(PusherFrame(event: "pusher:pong").encoded()) } catch {
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
            Logger(subsystem: "app.yak", category: "realtime").debug("\(message, privacy: .public)")
        #endif
    }
}
