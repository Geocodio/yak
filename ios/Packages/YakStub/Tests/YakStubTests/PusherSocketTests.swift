import Foundation
import Testing

@testable import YakStub

private var iosDirectory: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory
}

private func startedServer(_ scenario: Scenario) async throws -> (StubServer, UInt16, UInt16) {
    let fixtures = try StubFixtures(
        directory: iosDirectory.appendingPathComponent("fixtures"),
        scenariosDirectory: iosDirectory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
    let server = try StubServer(port: 0, fixtures: fixtures, scenario: scenario, playbackInterval: .milliseconds(30))
    let port = try await server.start()
    return (server, port, server.realtimePort)
}

private func get(_ port: UInt16, _ path: String, authorized: Bool = false) async throws -> [String: Any] {
    var request = URLRequest(url: URL(string: "http://127.0.0.1:\(port)\(path)")!)
    if authorized { request.setValue("Bearer test", forHTTPHeaderField: "Authorization") }
    let (data, _) = try await URLSession.shared.data(for: request)
    return try JSONSerialization.jsonObject(with: data) as? [String: Any] ?? [:]
}

private func post(_ port: UInt16, _ path: String, _ body: String) async throws {
    var request = URLRequest(url: URL(string: "http://127.0.0.1:\(port)\(path)")!)
    request.httpMethod = "POST"
    request.httpBody = Data(body.utf8)
    _ = try await URLSession.shared.data(for: request)
}

/// A client that fails a read after five seconds instead of hanging.
private final class Client: @unchecked Sendable {
    let task: URLSessionWebSocketTask

    init(_ socketPort: UInt16) {
        task = URLSession.shared.webSocketTask(with: URL(string: "ws://127.0.0.1:\(socketPort)/app/stub-key")!)
        task.resume()
    }

    func receive() async throws -> [String: Any] {
        let text = try await withThrowingTaskGroup(of: String.self) { group in
            group.addTask {
                guard case .string(let text) = try await self.task.receive() else { return "{}" }
                return text
            }
            group.addTask {
                try await Task.sleep(for: .seconds(5))
                self.task.cancel(with: .goingAway, reason: nil)
                throw URLError(.timedOut)
            }
            defer { group.cancelAll() }
            return try await group.next() ?? "{}"
        }
        return try JSONSerialization.jsonObject(with: Data(text.utf8)) as? [String: Any] ?? [:]
    }

    func send(_ frame: String) async throws { try await task.send(.string(frame)) }
}

@Suite struct PusherSocketTests {
    @Test func handshakeSubscribeAndPong() async throws {
        let (server, _, socketPort) = try await startedServer(.paired)
        defer { server.stop() }
        let first = Client(socketPort)
        let established = try await first.receive()
        #expect(established["event"] as? String == "pusher:connection_established")
        let data =
            try JSONSerialization.jsonObject(with: Data((established["data"] as? String ?? "").utf8)) as? [String: Any]
        #expect(data?["socket_id"] as? String == "1.1")
        #expect(data?["activity_timeout"] as? Int == 30)

        try await first.send(#"{"event":"pusher:subscribe","data":{"channel":"private-thread.d0e515ef"}}"#)
        let subscribed = try await first.receive()
        #expect(subscribed["event"] as? String == "pusher_internal:subscription_succeeded")
        #expect(subscribed["channel"] as? String == "private-thread.d0e515ef")
        try await first.send(#"{"event":"pusher:ping","data":{}}"#)
        #expect(try await first.receive()["event"] as? String == "pusher:pong")

        let second = Client(socketPort)
        let next = try await second.receive()
        let nextData =
            try JSONSerialization.jsonObject(with: Data((next["data"] as? String ?? "").utf8)) as? [String: Any]
        #expect(nextData?["socket_id"] as? String == "2.2")
    }

    @Test func playsTheEventListInOrder() async throws {
        let (server, port, socketPort) = try await startedServer(.oneThreadWorking)
        defer { server.stop() }
        let client = Client(socketPort)
        _ = try await client.receive()
        try await client.send(#"{"event":"pusher:subscribe","channel":"private-thread.d0e515ef"}"#)
        _ = try await client.receive()
        var sequences: [Int] = []
        var types: [Int: String] = [:]
        while sequences.last != 14 {
            let frame = try await client.receive()
            #expect(frame["event"] as? String == "thread.event")
            let item =
                try JSONSerialization.jsonObject(with: Data((frame["data"] as? String ?? "").utf8)) as? [String: Any]
            if let sequence = item?["sequence"] as? Int {
                sequences.append(sequence)
                types[sequence] = item?["type"] as? String
            }
        }
        #expect(sequences == Array(1...14))
        #expect(types[6] == "delta" && types[7] == "delta")
        let stored = try await get(port, "/api/app/v1/threads/d0e515ef/events?after=0&limit=500", authorized: true)
        #expect((stored["events"] as? [Any])?.count == 11)
    }

    @Test func liveEventsAreStampedWithTheTimeTheyArePlayed() async throws {
        let (server, _, socketPort) = try await startedServer(.oneThreadWorking)
        defer { server.stop() }
        let client = Client(socketPort)
        _ = try await client.receive()
        try await client.send(#"{"event":"pusher:subscribe","channel":"private-thread.d0e515ef"}"#)
        _ = try await client.receive()
        let frame = try await client.receive()
        let item = try JSONSerialization.jsonObject(with: Data((frame["data"] as? String ?? "").utf8)) as? [String: Any]
        let stamp = try #require(item?["createdAt"] as? String)
        let age = Date().timeIntervalSince(try #require(ISO8601DateFormatter().date(from: stamp)))
        #expect(age >= 0 && age < 30)
    }

    @Test func offlineClosesSocketsAndPausesPlayback() async throws {
        let (server, port, socketPort) = try await startedServer(.oneThreadWorking)
        defer { server.stop() }
        let client = Client(socketPort)
        defer { client.task.cancel(with: .normalClosure, reason: nil) }
        _ = try await client.receive()
        try await client.send(#"{"event":"pusher:subscribe","channel":"private-thread.d0e515ef"}"#)
        _ = try await client.receive()
        var last = 0
        while last < 3 {
            let frame = try await client.receive()
            let item =
                try JSONSerialization.jsonObject(with: Data((frame["data"] as? String ?? "").utf8)) as? [String: Any]
            last = item?["sequence"] as? Int ?? last
        }
        try await post(port, "/__stub/online", "no")
        // The open socket closes; frames already in flight may arrive first.
        var closeError: (any Error)?
        do {
            while true { _ = try await client.receive() }
        } catch { closeError = error }
        #expect(closeError != nil && (closeError as? URLError)?.code != .timedOut)
        // A new connection is refused while offline, not merely left to time out.
        let refused = Client(socketPort)
        defer { refused.task.cancel(with: .normalClosure, reason: nil) }
        var refusedError: (any Error)?
        do { _ = try await refused.receive() } catch { refusedError = error }
        #expect(refusedError != nil && (refusedError as? URLError)?.code != .timedOut)
        // Playback stays paused while offline. The API drops while offline, so the sequence is read right
        // after going online, which allows the one or two publishes that resume within milliseconds.
        try await Task.sleep(for: .milliseconds(300))
        try await post(port, "/__stub/online", "yes")
        let pausedAt = try await get(port, "/api/app/v1/threads/d0e515ef/events?after=0&limit=500", authorized: true)
        let paused = pausedAt["latestSequence"] as? Int ?? 0
        #expect(paused >= last && paused <= last + 3)

        // Playback resumes where it paused and reaches the end.
        let again = Client(socketPort)
        defer { again.task.cancel(with: .normalClosure, reason: nil) }
        _ = try await again.receive()
        try await again.send(#"{"event":"pusher:subscribe","channel":"private-thread.d0e515ef"}"#)
        #expect(try await again.receive()["event"] as? String == "pusher_internal:subscription_succeeded")
        var sequences: [Int] = []
        while sequences.last != 14 {
            let frame = try await again.receive()
            let item =
                try JSONSerialization.jsonObject(with: Data((frame["data"] as? String ?? "").utf8)) as? [String: Any]
            if let sequence = item?["sequence"] as? Int { sequences.append(sequence) }
        }
        #expect((sequences.first ?? 0) > paused)
    }

    @Test func meNamesTheSocket() async throws {
        let (server, port, socketPort) = try await startedServer(.paired)
        defer { server.stop() }
        let me = try await get(port, "/api/app/v1/me", authorized: true)
        let realtime = me["realtime"] as? [String: Any]
        #expect(realtime?["host"] as? String == "127.0.0.1")
        #expect(realtime?["port"] as? Int == Int(socketPort))
        #expect(realtime?["scheme"] as? String == "http")
        #expect(realtime?["key"] as? String == "stub-key")
    }
}
