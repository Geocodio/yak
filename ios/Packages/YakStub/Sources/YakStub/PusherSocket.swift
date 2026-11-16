import Foundation
import Network

/// A WebSocket listener on the loopback interface that speaks the subset of the Pusher protocol the app uses
/// and plays an event list over it. One playback runs per thread and scenario, started by the first
/// subscription to `private-thread.<id>`. Only frame event names are logged, never their data.
final class PusherSocket: Sendable {
    private struct Client {
        let connection: NWConnection
        var channels: Set<String> = []
    }

    private struct State {
        var clients: [Int: Client] = [:]
        var connectionCount = 0
        var playing: Set<String> = []
        var playbacks: [Task<Void, Never>] = []
    }

    private let listener: NWListener
    private let state = Locked(State())
    private let scenario: @Sendable () -> Scenario
    private let isOnline: @Sendable () -> Bool
    private let fixtures: StubFixtures
    private let threads: StubThreads
    private let onSubscribe: @Sendable (String) -> Void
    private let playbackMilliseconds: Locked<Int>
    private var playbackInterval: Duration { .milliseconds(playbackMilliseconds.get()) }

    /// Sets the pause before each broadcast of the next playback; a running playback keeps reading it.
    func setPlaybackMilliseconds(_ milliseconds: Int) { playbackMilliseconds.set(max(milliseconds, 1)) }

    init(
        fixtures: StubFixtures, threads: StubThreads, playbackInterval: Duration,
        scenario: @escaping @Sendable () -> Scenario, isOnline: @escaping @Sendable () -> Bool,
        onSubscribe: @escaping @Sendable (String) -> Void = { _ in }
    ) throws {
        self.onSubscribe = onSubscribe
        self.fixtures = fixtures
        self.threads = threads
        playbackMilliseconds = Locked(
            Int(
                playbackInterval.components.seconds * 1000 + playbackInterval.components.attoseconds
                    / 1_000_000_000_000_000))
        self.scenario = scenario
        self.isOnline = isOnline
        let parameters = NWParameters.tcp
        parameters.requiredInterfaceType = .loopback
        let options = NWProtocolWebSocket.Options()
        options.autoReplyPing = true
        parameters.defaultProtocolStack.applicationProtocols.insert(options, at: 0)
        listener = try NWListener(using: parameters, on: .any)
    }

    /// Starts listening and returns the port in use.
    func start() async throws -> UInt16 {
        listener.newConnectionHandler = { [self] connection in accept(connection) }
        return try await withCheckedThrowingContinuation { continuation in
            let once = Once()
            listener.stateUpdateHandler = { [listener] state in
                switch state {
                case .ready:
                    if once.first() { continuation.resume(returning: listener.port?.rawValue ?? 0) }
                case .failed(let error):
                    if once.first() { continuation.resume(throwing: error) }
                default: break
                }
            }
            listener.start(queue: .global())
        }
    }

    /// Closes every open socket; called when the stub goes offline.
    func closeAll() {
        let open = state.mutate { state -> [NWConnection] in
            let open = state.clients.values.map(\.connection)
            state.clients = [:]
            return open
        }
        for connection in open { connection.cancel() }
    }

    /// Stops listening, cancels every playback and closes every socket.
    func stop() {
        listener.cancel()
        reset()
    }

    /// Cancels every playback and forgets which threads played; called for a new scenario.
    func reset() {
        let tasks = state.mutate { state -> [Task<Void, Never>] in
            state.playing = []
            defer { state.playbacks = [] }
            return state.playbacks
        }
        for task in tasks { task.cancel() }
        closeAll()
    }

    private func accept(_ connection: NWConnection) {
        guard isOnline() else {
            connection.cancel()
            return
        }
        let identifier = state.mutate { state -> Int in
            state.connectionCount += 1
            state.clients[state.connectionCount] = Client(connection: connection)
            return state.connectionCount
        }
        connection.start(queue: .global())
        send(
            [
                "event": "pusher:connection_established",
                "data": encode(["socket_id": "\(identifier).\(identifier)", "activity_timeout": 30]),
            ],
            to: connection)
        receive(identifier, connection)
    }

    private func receive(_ identifier: Int, _ connection: NWConnection) {
        connection.receiveMessage { [self] data, context, _, error in
            let metadata = context?.protocolMetadata(definition: NWProtocolWebSocket.definition)
            let opcode = (metadata as? NWProtocolWebSocket.Metadata)?.opcode
            guard error == nil, opcode != .close else {
                state.mutate { _ = $0.clients.removeValue(forKey: identifier) }
                connection.cancel()
                return
            }
            if opcode == .text, let data, let frame = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
            {
                handle(frame, from: identifier, connection)
            }
            receive(identifier, connection)
        }
    }

    private func handle(_ frame: [String: Any], from identifier: Int, _ connection: NWConnection) {
        let event = frame["event"] as? String ?? ""
        log("socket \(event)")
        switch event {
        case "pusher:subscribe":
            let data = frame["data"] as? [String: Any] ?? [:]
            guard let channel = (frame["channel"] ?? data["channel"]) as? String else { return }
            state.mutate { $0.clients[identifier]?.channels.insert(channel) }
            send(["event": "pusher_internal:subscription_succeeded", "channel": channel, "data": "{}"], to: connection)
            if channel.hasPrefix("private-thread.") {
                let id = String(channel.dropFirst("private-thread.".count))
                startPlayback(id)
                onSubscribe(id)
            }
        case "pusher:ping":
            send(["event": "pusher:pong", "data": "{}"], to: connection)
        case "pusher:unsubscribe":
            if let channel = frame["channel"] as? String {
                state.mutate { $0.clients[identifier]?.channels.remove(channel) }
            }
        default:
            break
        }
    }

    private func startPlayback(_ id: String) {
        let current = scenario()
        guard current.playsLive else { return }
        let items = current.broadcasts(id, fixtures, "http://127.0.0.1")
        state.mutate { state in
            guard state.playing.insert(id).inserted else { return }
            state.playbacks.append(
                Task { [self] in
                    for item in items {
                        while !isOnline() {
                            if (try? await Task.sleep(for: .milliseconds(25))) == nil { return }
                        }
                        if (try? await Task.sleep(for: playbackInterval)) == nil { return }
                        while !isOnline() {
                            if (try? await Task.sleep(for: .milliseconds(25))) == nil { return }
                        }
                        if Task.isCancelled || threads.isStopped(id) { return }
                        threads.publish(id, through: item.sequence)
                        publish(item.json, thread: id)
                    }
                })
        }
    }

    func publish(_ json: String, thread id: String) {
        let channel = "private-thread.\(id)"
        let subscribed = state.mutate { state in
            state.clients.values.filter { $0.channels.contains(channel) }.map(\.connection)
        }
        for connection in subscribed {
            send(["event": "thread.event", "channel": channel, "data": json], to: connection)
        }
    }

    private func encode(_ object: Any) -> String {
        let data = (try? JSONSerialization.data(withJSONObject: object, options: [.sortedKeys])) ?? Data("{}".utf8)
        return String(decoding: data, as: UTF8.self)
    }

    private func send(_ frame: [String: Any], to connection: NWConnection) {
        let metadata = NWProtocolWebSocket.Metadata(opcode: .text)
        let context = NWConnection.ContentContext(identifier: "frame", metadata: [metadata])
        connection.send(
            content: Data(encode(frame).utf8), contentContext: context, isComplete: true, completion: .idempotent)
    }
}
