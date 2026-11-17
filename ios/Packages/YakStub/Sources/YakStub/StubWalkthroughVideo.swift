import Foundation

/// A Range header read against a body of `length` bytes. Anything that is not one plain `bytes=` range answers
/// the whole body.
enum ByteRange: Equatable, Sendable {
    /// No header, or one that does not parse: 200 with the whole body.
    case whole
    /// 206 with `Content-Range`.
    case partial(ClosedRange<Int>)
    /// 416 with `Content-Range: bytes */<length>`.
    case unsatisfiable

    static let maximumHeaderLength = 200

    static func parse(_ header: String?, length: Int) -> ByteRange {
        guard let header, header.utf8.count <= maximumHeaderLength, header.hasPrefix("bytes="), length > 0 else {
            return .whole
        }
        let spec = header.dropFirst("bytes=".count)
        guard !spec.contains(","), let dash = spec.firstIndex(of: "-") else { return .whole }
        let first = spec[..<dash]
        let last = spec[spec.index(after: dash)...]
        func isDigits(_ text: Substring) -> Bool { text.allSatisfy { ("0"..."9").contains($0) } }
        guard isDigits(first), isDigits(last), !(first.isEmpty && last.isEmpty) else { return .whole }
        // Digits that do not fit an Int are a position beyond any body.
        if first.isEmpty {
            guard let count = Int(last), count > 0 else { return .unsatisfiable }
            return .partial(max(length - count, 0)...(length - 1))
        }
        guard let start = Int(first), start < length else { return .unsatisfiable }
        if last.isEmpty { return .partial(start...(length - 1)) }
        guard let end = Int(last) else { return .partial(start...(length - 1)) }
        return end < start ? .unsatisfiable : .partial(start...min(end, length - 1))
    }
}

/// One line of the request log. Only these four fields: never a header value, a query string or a body.
struct WalkthroughVideoRequest: Codable, Equatable, Sendable {
    let method: String
    let path: String
    let hasRange: Bool
    let hasAuthorization: Bool
}

/// A walkthrough video the player can fetch: the task's `videoUrl` points here. Three scenarios: the video plays,
/// its link has expired (403), or the video answers after 12 seconds.
final class StubWalkthroughVideo: StubFeature, @unchecked Sendable {
    let name = "walkthrough-video"
    let scenarios = ["task-walkthrough", "task-walkthrough-expired", "task-walkthrough-slow"]
    let scenarioDataNames = ["scenario-task-walkthrough"]
    static let maximumLogEntries = 50
    static let slowDelay: Duration = .seconds(12)
    private static let base = "/__stub/walkthrough-video"

    private let log = Locked<[WalkthroughVideoRequest]>([])
    private let playing = Locked("")

    func reset() { log.set([]) }
    func played(scenario: String) { playing.set(scenario) }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        guard route == "GET tasks/k3m9p2qa", scenarios.contains(context.scenario) else { return nil }
        var body = context.fixtures.scenario("scenario-task-walkthrough", baseURL: context.baseURL)
        let file = context.scenario == "task-walkthrough-expired" ? "expired.mp4" : "sample.mp4"
        body["walkthrough"] = [
            "status": "ready", "error": NSNull(),
            "videoUrl": "\(context.baseURL)\(Self.base)/\(file)",
            "posterUrl": "\(context.baseURL)\(Self.base)/poster.png",
        ]
        let data = (try? JSONSerialization.data(withJSONObject: body)) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
    }

    func control(_ request: StubRequest) -> StubReply? {
        let file = request.path.dropFirst(Self.base.count).drop { $0 == "/" }
        switch file {
        case "sample.mp4":
            note(request)
            return video(request)
        case "expired.mp4":
            note(request)
            return text(403, "This link has expired.")
        case "poster.png":
            return StubReply(answer: .reply(status: 200, contentType: "image/png", body: StubWalkthroughMedia.poster))
        case "log":
            let data = (try? JSONEncoder().encode(log.get())) ?? Data("[]".utf8)
            return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
        default:
            return text(404, "Not found.")
        }
    }

    private func note(_ request: StubRequest) {
        let entry = WalkthroughVideoRequest(
            method: request.method, path: request.path, hasRange: request.headers["range"] != nil,
            hasAuthorization: request.headers["authorization"] != nil)
        log.mutate {
            $0.append(entry)
            if $0.count > Self.maximumLogEntries { $0.removeFirst($0.count - Self.maximumLogEntries) }
        }
    }

    private func video(_ request: StubRequest) -> StubReply {
        let whole = StubWalkthroughMedia.video
        let delay: Duration = playing.get() == "task-walkthrough-slow" ? Self.slowDelay : .zero
        var headers = ["Accept-Ranges": "bytes"]
        switch ByteRange.parse(request.headers["range"], length: whole.count) {
        case .whole:
            return StubReply(
                answer: .reply(status: 200, contentType: "video/mp4", body: whole), delay: delay, headers: headers)
        case .partial(let range):
            headers["Content-Range"] = "bytes \(range.lowerBound)-\(range.upperBound)/\(whole.count)"
            return StubReply(
                answer: .reply(status: 206, contentType: "video/mp4", body: Data(whole[range])), delay: delay,
                headers: headers)
        case .unsatisfiable:
            headers["Content-Range"] = "bytes */\(whole.count)"
            return StubReply(
                answer: .reply(status: 416, contentType: "text/plain", body: Data()), delay: delay, headers: headers)
        }
    }

    private func text(_ status: Int, _ message: String) -> StubReply {
        StubReply(answer: .reply(status: status, contentType: "text/plain", body: Data(message.utf8)))
    }
}
