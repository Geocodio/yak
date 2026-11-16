import Foundation
import Network

/// An HTTP/1.1 server on the loopback interface that plays one `Scenario` at a time. The scenario is
/// switched by `POST /__stub/scenario` with the scenario name as the body, so one running server serves
/// every UI test. `GET /__stub/online` answers `yes` or `no`; `POST /__stub/online` with that body sets it, and
/// the app in test mode reads it as its network state. A new scenario puts it back to `yes`. While offline every
/// API request is dropped.
final class StubServer: Sendable {
    private let fixtures: StubFixtures
    private let current: Locked<Scenario>
    private let online = Locked(true)
    private let attachments: StubAttachments
    private let attachmentScenario = Locked<String?>(nil)
    private let threads = StubThreads()
    private let features: [any StubFeature]
    private let featureScenario = Locked<String?>(nil)
    private let listener: NWListener
    private let socket: PusherSocket
    private let socketPort = Locked<UInt16>(0)

    /// `scenarios` is the folder of hand-written scenario files that the attachment scenarios read.
    init(
        port: UInt16, fixtures: StubFixtures, scenario: Scenario, scenarios: URL? = nil,
        playbackInterval: Duration = .milliseconds(400), features: [any StubFeature] = StubFeatures.all
    ) throws {
        self.features = features
        self.fixtures = fixtures
        attachments = StubAttachments { name in
            guard let folder = scenarios else { return nil }
            do {
                return try Data(contentsOf: folder.appendingPathComponent("\(name).json"))
            } catch {
                FileHandle.standardError.write(Data("stub scenario \(name) unreadable: \(type(of: error))\n".utf8))
                return nil
            }
        }
        let current = Locked(scenario)
        let online = self.online
        self.current = current
        socket = try PusherSocket(
            fixtures: fixtures, threads: threads, playbackInterval: playbackInterval,
            scenario: { current.get() }, isOnline: { online.get() })
        let parameters = NWParameters.tcp
        parameters.requiredInterfaceType = .loopback
        listener = try NWListener(using: parameters, on: NWEndpoint.Port(rawValue: port) ?? .any)
    }

    /// The port of the WebSocket listener, known after `start()`.
    var realtimePort: UInt16 { socketPort.get() }

    /// Starts listening and returns the port in use.
    func start() async throws -> UInt16 {
        socketPort.set(try await socket.start())
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

    /// Starts every feature's listeners, each within five seconds. A feature that throws or runs out of time
    /// stops the rest and is named in the error.
    func startFeatures() async throws {
        for feature in features {
            do {
                try await Self.bounded(.seconds(5)) { try await feature.start() }
            } catch let error as StubFeatureError {
                throw StubFeatureError(feature: feature.name, isTimeout: error.isTimeout)
            } catch {
                throw StubFeatureError(feature: feature.name, isTimeout: false)
            }
        }
    }

    /// Runs `work` and gives up after `limit`. The timer is cancelled when the work finishes first, so no task keeps
    /// sleeping for the full limit; `sleep` is replaced in tests to see that.
    static func bounded(
        _ limit: Duration,
        sleep: @escaping @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) },
        _ work: @escaping @Sendable () async throws -> Void
    ) async throws {
        let once = Once()
        let timer = TaskHolder()
        try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, Error>) in
            let job = Task {
                do {
                    try await work()
                    if once.first() { continuation.resume() }
                } catch {
                    if once.first() { continuation.resume(throwing: error) }
                }
                timer.cancel()
            }
            timer.set(
                Task {
                    do { try await sleep(limit) } catch { return }
                    if once.first() {
                        job.cancel()
                        continuation.resume(throwing: StubFeatureError(feature: "", isTimeout: true))
                    }
                })
        }
    }

    /// Stops both listeners and closes every WebSocket.
    func stop() {
        listener.cancel()
        socket.stop()
    }

    private func handle(_ connection: NWConnection) {
        connection.start(queue: .global())
        Task {
            let request: StubRequest
            switch await Self.readRequest(from: connection) {
            case .request(let read): request = read
            case .tooLarge:
                let reply = StubReply(answer: .reply(status: 413, contentType: "text/plain", body: Data()))
                Self.write(reply, for: StubRequest(method: "-", path: "-"), to: connection)
                return
            case .failed:
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
                let owners = features.filter { $0.scenarios.contains(name) }
                if StubAttachments.scenarioNames.contains(name) {
                    current.set(.paired)
                    attachmentScenario.set(name)
                    featureScenario.set(nil)
                    attachments.begin()
                } else if owners.count == 1 {
                    current.set(owners[0].coreScenario(for: name).flatMap(Scenario.init(rawValue:)) ?? .paired)
                    attachmentScenario.set(nil)
                    featureScenario.set(name)
                } else {
                    guard let scenario = Scenario(rawValue: name) else {
                        return StubReply(answer: Scenario.error(400, "unknown_scenario", "No scenario named \(name)."))
                    }
                    current.set(scenario)
                    attachmentScenario.set(nil)
                    featureScenario.set(nil)
                }
                online.set(true)
                attachments.setOnline(true)
                threads.reset()
                socket.reset()
                for feature in features {
                    feature.reset()
                    feature.played(scenario: name)
                    feature.setOnline(true)
                }
            }
            let body = Data((featureScenario.get() ?? attachmentScenario.get() ?? current.get().rawValue).utf8)
            return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: body))
        }
        if request.path == "/__stub/online" {
            if request.method == "POST" {
                let text = String(decoding: request.body, as: UTF8.self)
                let state = text.trimmingCharacters(in: .whitespacesAndNewlines)
                guard state == "yes" || state == "no" else {
                    return StubReply(
                        answer: Scenario.error(400, "unknown_online_state", "Send yes or no, not \(state)."))
                }
                online.set(state == "yes")
                attachments.setOnline(state == "yes")
                if state == "no" { socket.closeAll() }
                for feature in features { feature.setOnline(state == "yes") }
            }
            return StubReply(
                answer: .reply(status: 200, contentType: "text/plain", body: Data((online.get() ? "yes" : "no").utf8)))
        }
        if request.path == "/__stub/last-message" {
            return StubReply(
                answer: .reply(status: 200, contentType: "text/plain", body: Data(threads.lastMessage.utf8)))
        }
        if request.path == "/deployments/auth-bounce" {
            let page = "<!doctype html><title>Bounce</title><p>The app showed the dashboard bounce.</p>"
            return StubReply(answer: .reply(status: 200, contentType: "text/html", body: Data(page.utf8)))
        }
        for feature in features where Self.controlPath(of: feature.name, matches: request.path) {
            if let reply = feature.control(request) { return reply }
        }
        let isAPI = request.path.hasPrefix(Scenario.apiPrefix)
        if !online.get(), isAPI { return StubReply(answer: .drop) }
        let host = request.headers["host"] ?? "127.0.0.1"
        let core: @Sendable () -> StubReply = { [self] in coreReply(to: request, baseURL: "http://\(host)") }
        if isAPI, !features.isEmpty {
            let context = StubContext(
                scenario: featureScenario.get() ?? attachmentScenario.get() ?? current.get().rawValue,
                baseURL: "http://\(host)", fixtures: fixtures, threads: threads, core: { core().answer })
            let route = "\(request.method) \(request.path.dropFirst(Scenario.apiPrefix.count))"
            for feature in features {
                if let reply = feature.answer(route: route, request: request, context: context) { return reply }
            }
        }
        return core()
    }

    private static func controlPath(of name: String, matches path: String) -> Bool {
        path == "/__stub/\(name)" || path.hasPrefix("/__stub/\(name)/")
    }

    private func coreReply(to request: StubRequest, baseURL: String) -> StubReply {
        if let name = attachmentScenario.get(),
            let reply = attachments.reply(to: request, scenario: name, baseURL: baseURL)
        {
            return reply
        }
        return current.get().reply(
            to: request, baseURL: baseURL, fixtures: fixtures, threads: threads, realtimePort: socketPort.get())
    }

    private static func write(_ reply: StubReply, for request: StubRequest, to connection: NWConnection) {
        guard case .reply(let status, let contentType, let body) = reply.answer else {
            connection.cancel()
            return
        }
        // Method and status only: a request's path, headers and body can carry a token or a pairing code.
        log("\(request.method) -> \(status)")
        var head = "HTTP/1.1 \(status) \(status < 300 ? "OK" : "Error")\r\n"
        head += "Content-Type: \(contentType)\r\nContent-Length: \(body.count)\r\nConnection: close\r\n"
        head += "X-Yak-Protocol: \(reply.protocolHeader)\r\nX-Yak-Min-Protocol: 1\r\n\r\n"
        connection.send(
            content: Data(head.utf8) + body, contentContext: .finalMessage, isComplete: true,
            completion: .contentProcessed { _ in connection.cancel() })
    }

    private enum ReadOutcome {
        case request(StubRequest)
        case tooLarge
        case failed
    }

    /// Reads until the headers and the announced body have arrived. The headers come first, so the limit on the body
    /// is chosen from the content type once they are parsed; a larger announced length is refused unread.
    private static func readRequest(from connection: NWConnection) async -> ReadOutcome {
        var buffer = Data()
        let separator = Data("\r\n\r\n".utf8)
        var parsed: (start: [Substring], headers: [String: String], bodyStart: Data.Index, length: Int)?
        while true {
            if parsed == nil, let range = buffer.range(of: separator) {
                let head = String(decoding: buffer[..<range.lowerBound], as: UTF8.self)
                var lines = head.components(separatedBy: "\r\n")
                let start = lines.removeFirst().split(separator: " ")
                guard start.count >= 2 else { return .failed }
                var headers: [String: String] = [:]
                for line in lines {
                    guard let colon = line.firstIndex(of: ":") else { continue }
                    headers[line[..<colon].lowercased()] = line[line.index(after: colon)...].trimmingCharacters(
                        in: .whitespaces)
                }
                let length = Int(headers["content-length"] ?? "") ?? 0
                guard length <= StubRequestLimits.limit(contentType: headers["content-type"]) else { return .tooLarge }
                parsed = (start, headers, range.upperBound, length)
            } else if parsed == nil, buffer.count >= StubRequestLimits.plain {
                return .failed
            }
            if let parsed, buffer.count - parsed.bodyStart >= parsed.length {
                let target = parseTarget(String(parsed.start[1]))
                return .request(
                    StubRequest(
                        method: String(parsed.start[0]), path: target.path, query: target.query,
                        headers: parsed.headers,
                        body: Data(buffer[parsed.bodyStart...].prefix(parsed.length))))
            }
            guard let chunk = await receive(from: connection) else { return .failed }
            buffer += chunk
        }
    }

    /// The part of a request target before `?`, and its decoded query items.
    static func parseTarget(_ target: String) -> (path: String, query: [String: String]) {
        let path = String(target.prefix { $0 != "?" })
        var query: [String: String] = [:]
        for item in URLComponents(string: target)?.queryItems ?? [] { query[item.name] = item.value ?? "" }
        return (path, query)
    }

    private static func receive(from connection: NWConnection) async -> Data? {
        await withCheckedContinuation { continuation in
            connection.receive(minimumIncompleteLength: 1, maximumLength: 65536) { data, _, isComplete, error in
                continuation.resume(returning: error == nil && !(data?.isEmpty ?? true) ? data : nil)
            }
        }
    }
}

/// A value readable and changeable from any connection.
final class Locked<Value: Sendable>: @unchecked Sendable {
    private let lock = NSLock()
    private var value: Value

    init(_ value: Value) { self.value = value }
    func get() -> Value { lock.withLock { value } }
    func set(_ new: Value) { lock.withLock { value = new } }
    @discardableResult func mutate<Result>(_ change: (inout Value) -> Result) -> Result {
        lock.withLock { change(&value) }
    }
}

/// Lets only the first of several callbacks through.
/// Holds the timer of `bounded` so the finished work can cancel it, even if it finishes before the timer is stored.
final class TaskHolder: @unchecked Sendable {
    private let lock = NSLock()
    private var task: Task<Void, Never>?
    private var isCancelled = false
    func set(_ new: Task<Void, Never>) {
        lock.withLock {
            task = new
            if isCancelled { new.cancel() }
        }
    }
    func cancel() {
        lock.withLock {
            isCancelled = true
            task?.cancel()
        }
    }
}

final class Once: @unchecked Sendable {
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
