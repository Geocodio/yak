import Foundation

#if canImport(os)
    import os
#endif

public enum ConnectionState: Equatable, Sendable { case live, reconnecting, offline }

/// What one thread's stream delivers.
public enum ThreadChannelMessage: Equatable, Sendable {
    /// Every state change, current state first.
    case connection(ConnectionState)
    /// Both channels are subscribed on a new socket; the engine catches up.
    case subscribed
    /// Authorization or subscription was refused; nil when the server's `pusher:subscription_error` said so.
    case subscriptionFailed(APIFailure?)
    case event(ThreadEvent)
    case pointer(sequence: Int)
}

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
    private var subscriptions: [String: ThreadSubscription] = [:]
    private var subscriptionTasks: [Task<Void, Never>] = []
    private var isSuspended = false
    private var nextToken = 0

    private struct ThreadSubscription {
        let token: Int
        let continuation: AsyncStream<ThreadChannelMessage>.Continuation
        var confirmedChannels: Set<String> = []
        var hasAnnounced = false
    }

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
        guard configuration != nil, runTask == nil, !isSuspended else { return }
        generation += 1
        let current = generation
        attempt = 0
        runTask = Task { await self.run(generation: current) }
    }

    /// Closes the socket, sets `offline`, stops reconnecting and ends every thread stream.
    public func stop() async {
        let finished = subscriptions.values
        subscriptions = [:]
        for subscription in finished { subscription.continuation.finish() }
        await closeConnection()
    }

    private func closeConnection() async {
        for task in subscriptionTasks { task.cancel() }
        subscriptionTasks = []
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
        guard stateBroadcast.value != state else { return }
        stateBroadcast.send(state)
        for subscription in subscriptions.values { subscription.continuation.yield(.connection(state)) }
    }

    // MARK: - Thread channels

    private static func channelNames(for thread: String) -> [String] {
        ["private-thread.\(thread)", "presence-thread-presence.\(thread)"]
    }

    private static func thread(forChannel channel: String) -> String? {
        for prefix in ["private-thread.", "presence-thread-presence."] where channel.hasPrefix(prefix) {
            let id = String(channel.dropFirst(prefix.count))
            return id.isEmpty ? nil : id
        }
        return nil
    }

    /// One stream per call; a second call for the same thread ends the first stream.
    public func subscribe(thread id: String) -> AsyncStream<ThreadChannelMessage> {
        let (stream, continuation) = AsyncStream<ThreadChannelMessage>.makeStream(bufferingPolicy: .unbounded)
        subscriptions[id]?.continuation.finish()
        nextToken += 1
        subscriptions[id] = ThreadSubscription(token: nextToken, continuation: continuation)
        continuation.yield(.connection(stateBroadcast.value))
        if let socketID = currentSocketID {
            subscriptionTasks.append(Task { await self.subscribeChannels(of: [id], socketID: socketID) })
        }
        return stream
    }

    public func unsubscribe(thread id: String) async {
        guard let subscription = subscriptions.removeValue(forKey: id) else { return }
        await sendUnsubscribe(for: [id])
        subscription.continuation.finish()
    }

    /// The app went to the background: leaves every channel, closes the socket and shows `offline`.
    /// The threads stay registered for `resume()`.
    public func suspend() async {
        isSuspended = true
        await sendUnsubscribe(for: Array(subscriptions.keys))
        await closeConnection()
    }

    /// The app is back: connects at once and subscribes every kept thread again.
    public func resume() {
        isSuspended = false
        start()
    }

    private func sendUnsubscribe(for threads: [String]) async {
        guard currentSocketID != nil, let connection else { return }
        for thread in threads {
            for name in Self.channelNames(for: thread) {
                let frame = PusherFrame(
                    event: "pusher:unsubscribe", data: Self.json(["channel": name])
                ).encoded()
                do { try await connection.send(frame) } catch { log("unsubscribe send failed: \(type(of: error))") }
            }
        }
    }

    private static func json(_ object: [String: String]) -> String {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys, .withoutEscapingSlashes]
        guard let bytes = try? encoder.encode(object) else { return "{}" }
        return String(decoding: bytes, as: UTF8.self)
    }

    /// Authorizes with this socket's id and subscribes, one channel at a time. Stops at once when the
    /// socket is gone, so the next socket starts over with its own id.
    private func subscribeChannels(of threads: [String], socketID: String) async {
        for thread in threads {
            for name in Self.channelNames(for: thread) {
                guard currentSocketID == socketID, subscriptions[thread] != nil, let connection else { return }
                let authorization: ChannelAuthorization
                do throws(APIFailure) {
                    authorization = try await authorizer(socketID, name)
                } catch {
                    guard currentSocketID == socketID else { return }
                    subscriptions[thread]?.continuation.yield(.subscriptionFailed(error))
                    break
                }
                guard currentSocketID == socketID, subscriptions[thread] != nil else { return }
                var data = ["auth": authorization.auth, "channel": name]
                if let channelData = authorization.channelData { data["channel_data"] = channelData }
                do {
                    try await connection.send(PusherFrame(event: "pusher:subscribe", data: Self.json(data)).encoded())
                } catch {
                    log("subscribe send failed: \(type(of: error))")
                    return
                }
            }
        }
    }

    private func handleChannelFrame(_ frame: PusherFrame) {
        guard let channel = frame.channel, let thread = Self.thread(forChannel: channel),
            var subscription = subscriptions[thread]
        else { return }
        switch frame.event {
        case "pusher_internal:subscription_succeeded":
            subscription.confirmedChannels.insert(channel)
            if !subscription.hasAnnounced, subscription.confirmedChannels.count == 2 {
                subscription.hasAnnounced = true
                subscription.continuation.yield(.subscribed)
            }
            subscriptions[thread] = subscription
        case "pusher:subscription_error":
            subscription.continuation.yield(.subscriptionFailed(nil))
        case "thread.event":
            guard channel == "private-thread.\(thread)" else { return }
            switch frame.data?.data(using: .utf8).flatMap(SocketEvent.decode) {
            case .event(let event): subscription.continuation.yield(.event(event))
            case .pointer(let sequence): subscription.continuation.yield(.pointer(sequence: sequence))
            case nil: log("dropped a thread event that does not decode")
            }
        default:
            break
        }
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
            for key in subscriptions.keys {
                subscriptions[key]?.confirmedChannels = []
                subscriptions[key]?.hasAnnounced = false
            }
            let socketID = established.socketID
            let threads = subscriptions.keys.sorted()
            for task in subscriptionTasks { task.cancel() }
            subscriptionTasks = []
            subscriptionTasks.append(Task { await self.subscribeChannels(of: threads, socketID: socketID) })
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
            handleChannelFrame(frame)
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
