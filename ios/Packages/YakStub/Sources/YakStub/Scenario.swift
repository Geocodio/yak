import Foundation

/// One request as the stub sees it. Header names are lowercased.
struct StubRequest: Sendable {
    var method: String
    var path: String
    /// The decoded query items; a key that repeats keeps its last value.
    var query: [String: String] = [:]
    var headers: [String: String] = [:]
    var body = Data()
}

/// What the stub does with a request: answer it, or close the connection without answering.
enum StubAnswer: Sendable, Equatable {
    case drop
    case reply(status: Int, contentType: String, body: Data)
}

struct StubReply: Sendable {
    var answer: StubAnswer
    /// Waited out before the answer is sent.
    var delay: Duration = .zero
    /// The value of the `X-Yak-Protocol` response header.
    var protocolHeader = 1
}

/// A scenario data file that cannot be read. Names the file and the feature that asked for it.
struct StubFixtureError: Error, CustomStringConvertible {
    let scenario: String
    /// The owning feature; nil for the core table.
    let feature: String?

    var description: String {
        "scenario file \(scenario).json is missing or unreadable" + (feature.map { " (feature \($0))" } ?? "")
    }
}

/// The fixture files, read once, with the values that change per run filled in.
struct StubFixtures: Sendable {
    /// Server fixture name and scenario data name of every thread file the stub serves. A renamed file is a
    /// one-line change here. `thread-events`, `thread-event` and `thread-share` are not served: catch-up pages
    /// are computed from the event lists.
    static let coreNames: [(server: String, scenario: String)] = [
        ("threads", "scenario-thread-list"),
        ("thread", "scenario-thread-show"),
        ("thread-question", "scenario-thread-question"),
        ("thread-new", "scenario-thread-new"),
        ("thread-store", "scenario-thread-store"),
        ("thread-message-sent", "scenario-message-sent"),
        ("thread-message-queued", "scenario-message-queued"),
        ("thread-message-clear", "scenario-message-clear"),
        ("thread-repository-access", "scenario-thread-repository-access"),
        ("events/simple-turn", "events/simple-turn"),
        ("events/steer", "events/steer"),
        ("events/question", "events/question"),
        ("events/failed-turn", "events/failed-turn"),
        ("events/stream-reset", "events/stream-reset"),
        ("events/pointer", "events/pointer"),
    ]
    /// The core table and every feature's fixture pairs.
    static var names: [(server: String, scenario: String)] {
        coreNames + StubFeatures.all.flatMap(\.fixturePairs).map { ($0.server, $0.scenario) }
    }
    private static let authorizationScenarioNames = [
        "scenario-broadcasting-auth-presence", "scenario-broadcasting-auth-private",
    ]

    /// Server fixtures by name. The thread fixtures are present only when the file exists.
    let files: [String: Data]
    /// Hand-written scenario data by name, from the `--scenarios` directory only.
    let scenarioFiles: [String: Data]

    init(directory: URL, scenariosDirectory: URL? = nil) throws {
        var files: [String: Data] = [:]
        for name in ["instance", "me", "device-code", "device-pair", "device-token"] {
            files[name] = try Data(contentsOf: directory.appendingPathComponent("\(name).json"))
        }
        for pair in Self.names {
            files[pair.server] = try? Data(contentsOf: directory.appendingPathComponent("\(pair.server).json"))
        }
        self.files = files
        var scenarioFiles: [String: Data] = [:]
        if let scenariosDirectory {
            for name in Self.names.map(\.scenario) + Self.authorizationScenarioNames
                + StubFeatures.all.flatMap(\.scenarioFileNames)
            {
                do {
                    scenarioFiles[name] = try Data(
                        contentsOf: scenariosDirectory.appendingPathComponent("\(name).json"))
                } catch {
                    let owner = StubFeatures.all.first { $0.fixturePairs.contains { $0.scenario == name } }?.name
                    throw StubFixtureError(scenario: name, feature: owner)
                }
            }
        }
        self.scenarioFiles = scenarioFiles
    }

    /// The scenario data file `name`, filled like a fixture. Empty when the file is not loaded.
    func scenario(_ name: String, baseURL: String = "http://127.0.0.1") -> [String: Any] {
        object(scenarioFiles[name], baseURL: baseURL)
    }

    /// The server fixture paired with `name` (a server or a scenario name) when its file exists, the
    /// scenario data file otherwise.
    func serverFirst(_ name: String, baseURL: String = "http://127.0.0.1") -> [String: Any] {
        guard let pair = Self.names.first(where: { $0.server == name || $0.scenario == name }) else {
            return scenario(name, baseURL: baseURL)
        }
        if let data = files[pair.server] { return object(data, baseURL: baseURL) }
        return scenario(pair.scenario, baseURL: baseURL)
    }

    private func object(_ data: Data?, baseURL: String) -> [String: Any] {
        guard let data, let parsed = try? JSONSerialization.jsonObject(with: data) else { return [:] }
        return fill(parsed, key: nil, baseURL: baseURL) as? [String: Any] ?? [:]
    }

    /// The fixture with typed markers (`"<volatile:string>"`, `"<volatile:int>"`, ...) swapped for values of
    /// that type, chosen by the key where the shape needs a particular value. `edit` changes the result.
    func body(_ name: String, baseURL: String, edit: (inout [String: Any]) -> Void = { _ in }) -> Data {
        guard let data = files[name],
            let object = try? JSONSerialization.jsonObject(with: data),
            var filled = fill(object, key: nil, baseURL: baseURL) as? [String: Any],
            let encoded = { () -> Data? in
                edit(&filled)
                return try? JSONSerialization.data(withJSONObject: filled)
            }()
        else { return Data("{}".utf8) }
        return encoded
    }

    private func fill(_ value: Any, key: String?, baseURL: String) -> Any {
        if let dictionary = value as? [String: Any] {
            return dictionary.reduce(into: [String: Any]()) {
                $0[$1.key] = fill($1.value, key: $1.key, baseURL: baseURL)
            }
        }
        if let array = value as? [Any] { return array.map { fill($0, key: key, baseURL: baseURL) } }
        switch value as? String {
        case "<volatile:string>":
            switch key {
            case "token": return "stub-token"
            case "pairedAt": return "2026-01-01T00:00:00+00:00"
            case "url": return baseURL
            case "wsUrl": return baseURL.replacingOccurrences(of: "http://", with: "ws://") + "/terminal"
            case "verification_url", "verification_url_complete": return baseURL + "/device"
            case "device_code": return "stub-device-code"
            case "user_code": return "STUB-CODE"
            default: return "stub"
            }
        case "<volatile:int>": return 1
        case "<volatile:bool>": return false
        case "<volatile:null>": return NSNull()
        default: return value
        }
    }
}

/// A behavior the stub can play, so a test or a walkthrough reaches one state of the app.
enum Scenario: String, CaseIterable, Sendable {
    /// A healthy instance that accepts the pairing code.
    case paired
    /// A healthy instance that refuses the device: `me` answers 401 `device_revoked`.
    case revoked
    /// An instance that needs a newer app: `instance` reports `api.min = 99` and `me` answers 426.
    case serverTooNew = "server-too-new"
    /// An instance older than the app supports: it stamps protocol 0 on every answer.
    case serverTooOld = "server-too-old"
    /// Something that is not Yak: every path answers an HTML page.
    case notYak = "not-yak"
    /// A healthy instance that answers each request after 10 seconds.
    case slow
    /// Like `slow`, but 14 seconds, so a screen that shows its "slow connection" hint after 8 seconds can be audited
    /// before the answer arrives (the app gives up after 15).
    case slower
    /// An instance that is down: every connection is closed without an answer.
    case down
    /// An instance that fails: every path answers 500.
    case broken
    /// The pairing code was already used: `device/pair` answers 400 `pairing_code_invalid`.
    case codeUsed = "code-used"
    /// The pairing code is too old: `device/pair` answers 400 `pairing_code_expired`.
    case codeExpired = "code-expired"

    /// The typed-address flow: the person has not approved yet, so `device/token` answers `authorization_pending` for ever.
    case approvalPending = "approval-pending"
    /// The person denied the request: `device/token` answers 400 `access_denied`.
    case approvalDenied = "approval-denied"
    /// The device code ran out: `device/token` answers 400 `expired_token`.
    case approvalExpired = "approval-expired"
    /// The server asks the app to poll less often: `device/token` answers 400 `slow_down`.
    case approvalSlowDown = "approval-slow-down"

    /// One needy thread and one needy task, so a cold start opens the drawer. The thread routes are the same in
    /// every scenario that does not override them; the scenarios below do.
    /// A thread with an open question: needsYou holds only `d0e515ef`, its show carries the question until answered.
    case questionOpen = "question-open"
    /// One running thread whose events play over the socket, one every 400 ms from the first subscription.
    case oneThreadWorking = "one-thread-working"
    /// The person has no threads of their own, the team has: `mine` is empty, `everyone` is the scenario list.
    case teamOnly = "team-only"
    /// Nobody has a thread.
    case empty
    /// One thread per state a thread can be in (closed, read only, on a computer, notices).
    case threadStates = "thread-states"
    /// `d0e515ef` is gone: its show answers 404.
    case threadMissing = "thread-missing"
    /// A thread's show answers after 14 seconds.
    case slowThread = "slow-thread"
    /// `d0e515ef` has 1200 events.
    case longHistory = "long-history"
    /// A message is refused: 409 `thread_closed`.
    case messageRefused = "message-refused"
    /// Starting a thread is refused: 422 `validation_failed`.
    case newThreadRefused = "new-thread-refused"
    /// Nobody has a thread and the instance has no repositories: `threads/new` answers `repos: []`.
    case noRepositories = "no-repositories"
    /// Nobody has a thread, and the new-thread options answer after 14 seconds.
    case slowNewThread = "slow-new-thread"
    /// Nobody has a thread, and the new-thread options fail with 500.
    case brokenNewThread = "broken-new-thread"
    /// The thread routes answer with the server's own fixtures.
    case serverFixtures = "server-fixtures"

    static let apiPrefix = "/api/app/v1/"
    static let slowDelay = Duration.seconds(10)
    static let slowerDelay = Duration.seconds(14)

    func reply(
        to request: StubRequest, baseURL: String, fixtures: StubFixtures, threads: StubThreads = StubThreads(),
        realtimePort: UInt16 = 0
    ) -> StubReply {
        switch self {
        case .notYak:
            return StubReply(answer: Self.html)
        case .down:
            return StubReply(answer: .drop)
        case .broken:
            return StubReply(answer: Self.error(500, "server_error", "Yak could not handle this request. Try again."))
        default:
            break
        }
        guard request.path.hasPrefix(Self.apiPrefix) else {
            return StubReply(answer: Self.error(404, "not_found", "Not found."))
        }
        let route = "\(request.method) \(request.path.dropFirst(Self.apiPrefix.count))"
        var reply =
            threadReply(route: route, request: request, baseURL: baseURL, fixtures: fixtures, threads: threads)
            ?? StubReply(
                answer: answer(
                    route: route, request: request, baseURL: baseURL, fixtures: fixtures, realtimePort: realtimePort))
        if self == .slow { reply.delay = Self.slowDelay }
        if self == .slower { reply.delay = Self.slowerDelay }
        if self == .serverTooOld { reply.protocolHeader = 0 }
        return reply
    }

    private func answer(
        route: String, request: StubRequest, baseURL: String, fixtures: StubFixtures, realtimePort: UInt16
    ) -> StubAnswer {
        func json(_ name: String, edit: (inout [String: Any]) -> Void = { _ in }) -> StubAnswer {
            .reply(
                status: 200, contentType: "application/json",
                body: fixtures.body(name, baseURL: baseURL, edit: edit))
        }
        switch route {
        case "GET instance":
            switch self {
            case .serverTooNew:
                return json("instance") { $0["api"] = ["current": 99, "min": 99] }
            case .serverTooOld:
                return json("instance") { $0["api"] = ["current": 0, "min": 0] }
            default:
                return json("instance")
            }
        case "GET me":
            guard request.headers["authorization"]?.hasPrefix("Bearer ") == true else {
                return Self.error(401, "invalid_token", "This device is not signed in.")
            }
            switch self {
            case .revoked: return Self.error(401, "device_revoked", "This device was removed.")
            case .serverTooNew:
                return Self.error(
                    426, "protocol_too_old", "Update the app.",
                    details: ["min_protocol": 99, "current_protocol": 99, "client_protocol": 1])
            default:
                return json("me") {
                    guard realtimePort != 0 else { return }
                    $0["realtime"] = [
                        "host": "127.0.0.1", "port": Int(realtimePort), "scheme": "http", "key": "stub-key",
                    ]
                }
            }
        case "POST device/pair":
            switch self {
            case .codeUsed: return Self.error(400, "pairing_code_invalid", "This code did not work.")
            case .codeExpired: return Self.error(400, "pairing_code_expired", "This code expired.")
            default: return json("device-pair")
            }
        case "POST device/code": return json("device-code")
        case "POST device/token":
            switch self {
            case .approvalPending: return Self.error(400, "authorization_pending", "Waiting for approval.")
            case .approvalDenied: return Self.error(400, "access_denied", "The request was denied.")
            case .approvalExpired: return Self.error(400, "expired_token", "This code expired.")
            case .approvalSlowDown: return Self.error(400, "slow_down", "Poll less often.")
            default: return json("device-token")
            }
        case "DELETE device": return .reply(status: 204, contentType: "application/json", body: Data())
        default: return Self.error(404, "not_found", "Not found.")
        }
    }

    private static let html = StubAnswer.reply(
        status: 200, contentType: "text/html", body: Data("<html><body><h1>Welcome</h1></body></html>".utf8))

    static func error(_ status: Int, _ code: String, _ message: String, details: [String: Any] = [:]) -> StubAnswer {
        var body: [String: Any] = ["error": code, "message": message]
        if !details.isEmpty { body["details"] = details }
        let data = (try? JSONSerialization.data(withJSONObject: body)) ?? Data()
        return .reply(status: status, contentType: "application/json", body: data)
    }
}
