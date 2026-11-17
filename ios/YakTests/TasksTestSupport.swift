import Foundation
import YakKit

@testable import Yak

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct NoSockets: WebSocketConnector {
    struct Refused: Error {}
    func connect(to url: URL) async throws -> any WebSocketConnection { throw Refused() }
}

/// The hand-written scenario data of YakKit's tests, read from the repository.
enum ScenarioFile {
    static func data(_ name: String) -> Data {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let file = directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios/\(name).json")
        return (try? Data(contentsOf: file)) ?? Data()
    }

    /// The file as a dictionary the test may change before it is served.
    static func object(_ name: String) -> [String: Any] {
        (try? JSONSerialization.jsonObject(with: data(name))) as? [String: Any] ?? [:]
    }
}

/// Answers `me` and whatever `answer` returns for a route ("GET tasks"); every other route is unreachable. A
/// request is recorded as its method, path and query.
final class ScriptedTransport: HTTPTransport, @unchecked Sendable {
    typealias Answer = @Sendable (_ route: String, _ query: String, _ body: Data) throws -> (status: Int, body: Data)?

    private let lock = NSLock()
    private var requests: [String] = []
    private let answer: Answer

    init(answer: @escaping Answer) { self.answer = answer }

    static let me = """
        {"user":{"id":7,"name":"Test Person","email":"person@example.test","initials":"TP","isAdmin":false},
        "device":{"id":3,"name":"Test phone","kind":"phone","pairedAt":"2026-11-15T08:00:00Z"},
        "instance":{"name":"Example Yak","url":"https://example.test","version":"2026.11.1"},"needsYouCount":0,
        "realtime":null,"terminal":{"wsUrl":"wss://example.test/terminal"},
        "attachments":{"maxFiles":8,"maxFileBytes":10485760},
        "push":{"apns":false,"bundleId":"com.example.yak","environment":"production"},
        "features":{"liveActivities":false,"widgets":false}}
        """

    /// The recorded requests, each "GET tasks?tab=tasks&page=1".
    var all: [String] { lock.withLock { requests } }
    func count(where matches: (String) -> Bool) -> Int { all.filter(matches).count }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url ?? URL(string: "https://example.test")!
        let path = url.path.replacingOccurrences(of: "/api/app/v1/", with: "")
        let query = url.query ?? ""
        let method = request.httpMethod ?? "GET"
        lock.withLock { requests.append("\(method) \(path)\(query.isEmpty ? "" : "?\(query)")") }
        var status = 200
        var body = Data()
        if path == "me" {
            body = Data(Self.me.utf8)
        } else if let reply = try answer("\(method) \(path)", query, request.httpBody ?? Data()) {
            status = reply.status
            body = reply.body
        } else {
            // A cancelled request is not retried, so the failure reaches the caller at once.
            throw URLError(.cancelled)
        }
        let response = HTTPURLResponse(
            url: url, statusCode: status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (body, response)
    }
}

@MainActor struct TasksFixture {
    let transport: ScriptedTransport
    let session: InstanceSession

    init(answer: @escaping ScriptedTransport.Answer) async throws {
        transport = ScriptedTransport(answer: answer)
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
        let instance = try await instances.add(
            url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person", token: "token")
        session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens,
            environment: SessionEnvironment(
                http: transport, webSockets: NoSockets(), files: InMemoryFileStore(), clock: SystemClock(),
                makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false))
        await session.start()
    }

    func eventually(_ condition: () -> Bool) async -> Bool {
        for _ in 0..<800 {
            if condition() { return true }
            try? await Task.sleep(for: .milliseconds(5))
        }
        return condition()
    }
}

/// A JSON error body as the server writes it.
func errorBody(_ code: String, _ message: String, details: [String: Any] = [:]) -> Data {
    var body: [String: Any] = ["error": code, "message": message]
    if !details.isEmpty { body["details"] = details }
    return (try? JSONSerialization.data(withJSONObject: body)) ?? Data()
}
