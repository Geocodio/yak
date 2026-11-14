import Foundation

/// One request as the stub sees it. Header names are lowercased.
struct StubRequest: Sendable {
    var method: String
    var path: String
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

/// The fixture files, read once, with the values that change per run filled in.
struct StubFixtures: Sendable {
    let files: [String: Data]

    init(directory: URL) throws {
        var files: [String: Data] = [:]
        for name in ["instance", "me", "device-code", "device-pair", "device-token"] {
            files[name] = try Data(contentsOf: directory.appendingPathComponent("\(name).json"))
        }
        self.files = files
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
    /// An instance that is down: every connection is closed without an answer.
    case down
    /// An instance that fails: every path answers 500.
    case broken
    /// The pairing code was already used: `device/pair` answers 400 `pairing_code_invalid`.
    case codeUsed = "code-used"
    /// The pairing code is too old: `device/pair` answers 400 `pairing_code_expired`.
    case codeExpired = "code-expired"

    static let apiPrefix = "/api/app/v1/"
    static let slowDelay = Duration.seconds(10)

    func reply(to request: StubRequest, baseURL: String, fixtures: StubFixtures) -> StubReply {
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
        var reply = StubReply(answer: answer(route: route, request: request, baseURL: baseURL, fixtures: fixtures))
        if self == .slow { reply.delay = Self.slowDelay }
        if self == .serverTooOld { reply.protocolHeader = 0 }
        return reply
    }

    private func answer(route: String, request: StubRequest, baseURL: String, fixtures: StubFixtures) -> StubAnswer {
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
            default: return json("me")
            }
        case "POST device/pair":
            switch self {
            case .codeUsed: return Self.error(400, "pairing_code_invalid", "This code did not work.")
            case .codeExpired: return Self.error(400, "pairing_code_expired", "This code expired.")
            default: return json("device-pair")
            }
        case "POST device/code": return json("device-code")
        case "POST device/token": return json("device-token")
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
