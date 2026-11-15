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
        let (_, _, socketPort) = try await startedServer(.paired)
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
        let (_, port, socketPort) = try await startedServer(.oneThreadWorking)
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

    @Test func offlineClosesSocketsAndPausesPlayback() async throws {
        let (_, port, socketPort) = try await startedServer(.oneThreadWorking)
        let client = Client(socketPort)
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
        let refused = Client(socketPort)
        await #expect(throws: (any Error).self) { _ = try await refused.receive() }
        try await post(port, "/__stub/online", "yes")
        try await Task.sleep(for: .milliseconds(300))
        let published = try await get(port, "/api/app/v1/threads/d0e515ef/events?after=0&limit=500", authorized: true)
        #expect((published["latestSequence"] as? Int ?? 0) >= 3)
        let again = Client(socketPort)
        _ = try await again.receive()
        try await again.send(#"{"event":"pusher:subscribe","channel":"private-thread.d0e515ef"}"#)
        #expect(try await again.receive()["event"] as? String == "pusher_internal:subscription_succeeded")
    }

    @Test func meNamesTheSocket() async throws {
        let (_, port, socketPort) = try await startedServer(.paired)
        let me = try await get(port, "/api/app/v1/me", authorized: true)
        let realtime = me["realtime"] as? [String: Any]
        #expect(realtime?["host"] as? String == "127.0.0.1")
        #expect(realtime?["port"] as? Int == Int(socketPort))
        #expect(realtime?["scheme"] as? String == "http")
        #expect(realtime?["key"] as? String == "stub-key")
    }
}
