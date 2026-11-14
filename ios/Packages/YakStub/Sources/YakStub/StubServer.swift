import Foundation
import Network

/// An HTTP/1.1 server on the loopback interface that plays one `Scenario` at a time. The scenario is
/// switched by `POST /__stub/scenario` with the scenario name as the body, so one running server serves
/// every UI test.
final class StubServer: Sendable {
    private let fixtures: StubFixtures
    private let current: ScenarioBox
    private let listener: NWListener

    init(port: UInt16, fixtures: StubFixtures, scenario: Scenario) throws {
        self.fixtures = fixtures
        self.current = ScenarioBox(scenario)
        let parameters = NWParameters.tcp
        parameters.requiredInterfaceType = .loopback
        listener = try NWListener(using: parameters, on: NWEndpoint.Port(rawValue: port) ?? .any)
    }

    /// Starts listening and returns the port in use.
    func start() async throws -> UInt16 {
        listener.newConnectionHandler = { [self] connection in handle(connection) }
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

    private func handle(_ connection: NWConnection) {
        connection.start(queue: .global())
        Task {
            guard let request = await Self.readRequest(from: connection) else {
                connection.cancel()
                return
            }
            let reply = await route(request)
            if reply.delay > .zero { try? await Task.sleep(for: reply.delay) }
            Self.write(reply, for: request, to: connection)
        }
    }

    private func route(_ request: StubRequest) async -> StubReply {
        if request.path == "/__stub/scenario" {
            if request.method == "POST" {
                let name = String(decoding: request.body, as: UTF8.self).trimmingCharacters(in: .whitespacesAndNewlines)
                guard let scenario = Scenario(rawValue: name) else {
                    return StubReply(answer: Scenario.error(400, "unknown_scenario", "No scenario named \(name)."))
                }
                current.set(scenario)
            }
            let body = Data(current.get().rawValue.utf8)
            return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: body))
        }
        let host = request.headers["host"] ?? "127.0.0.1"
        return current.get().reply(to: request, baseURL: "http://\(host)", fixtures: fixtures)
    }

    private static func write(_ reply: StubReply, for request: StubRequest, to connection: NWConnection) {
        guard case .reply(let status, let contentType, let body) = reply.answer else {
            connection.cancel()
            return
        }
        // Method and path only: a request's headers and body can carry a token or a pairing code.
        log("\(request.method) \(request.path) -> \(status)")
        var head = "HTTP/1.1 \(status) \(status < 300 ? "OK" : "Error")\r\n"
        head += "Content-Type: \(contentType)\r\nContent-Length: \(body.count)\r\nConnection: close\r\n"
        head += "X-Yak-Protocol: \(reply.protocolHeader)\r\nX-Yak-Min-Protocol: 1\r\n\r\n"
        connection.send(
            content: Data(head.utf8) + body, contentContext: .finalMessage, isComplete: true,
            completion: .contentProcessed { _ in connection.cancel() })
    }

    /// Reads until the headers and the announced body have arrived.
    private static func readRequest(from connection: NWConnection) async -> StubRequest? {
        var buffer = Data()
        let separator = Data("\r\n\r\n".utf8)
        while buffer.count < 1_000_000 {
            if let range = buffer.range(of: separator) {
                let head = String(decoding: buffer[..<range.lowerBound], as: UTF8.self)
                var lines = head.components(separatedBy: "\r\n")
                let start = lines.removeFirst().split(separator: " ")
                guard start.count >= 2 else { return nil }
                var headers: [String: String] = [:]
                for line in lines {
                    guard let colon = line.firstIndex(of: ":") else { continue }
                    headers[line[..<colon].lowercased()] = line[line.index(after: colon)...].trimmingCharacters(
                        in: .whitespaces)
                }
                let length = Int(headers["content-length"] ?? "") ?? 0
                let body = buffer[range.upperBound...]
                if body.count >= length {
                    return StubRequest(
                        method: String(start[0]), path: String(start[1].prefix { $0 != "?" }), headers: headers,
                        body: Data(body.prefix(length)))
                }
            }
            guard let chunk = await receive(from: connection) else { return nil }
            buffer += chunk
        }
        return nil
    }

    private static func receive(from connection: NWConnection) async -> Data? {
        await withCheckedContinuation { continuation in
            connection.receive(minimumIncompleteLength: 1, maximumLength: 65536) { data, _, isComplete, error in
                continuation.resume(returning: error == nil && !(data?.isEmpty ?? true) ? data : nil)
            }
        }
    }
}

/// The scenario in play, readable and changeable from any connection.
private final class ScenarioBox: @unchecked Sendable {
    private let lock = NSLock()
    private var scenario: Scenario

    init(_ scenario: Scenario) { self.scenario = scenario }
    func get() -> Scenario { lock.withLock { scenario } }
    func set(_ new: Scenario) { lock.withLock { scenario = new } }
}

/// Lets only the first of several callbacks through.
private final class Once: @unchecked Sendable {
    private let lock = NSLock()
    private var done = false
    func first() -> Bool {
        lock.withLock {
            defer { done = true }
            return !done
        }
    }
}

func log(_ message: String) {
    FileHandle.standardError.write(Data((message + "\n").utf8))
}
