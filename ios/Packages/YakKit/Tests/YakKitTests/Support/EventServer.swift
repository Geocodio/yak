import Foundation

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

/// Plays the server's side of the events endpoints for one thread.
actor EventServer {
    private let threadID: String
    private let events: [ThreadEvent]
    private var publishedThrough = 0
    private(set) var requestedAfters: [Int] = []

    /// Stored events only.
    init(threadID: String, events: [ThreadEvent]) {
        self.threadID = threadID
        self.events = events.filter(\.isStored).sorted { $0.sequence < $1.sequence }
    }

    /// What the server holds so far; `latestSequence` answers with this.
    func publish(through sequence: Int) {
        publishedThrough = sequence
    }

    func install(on transport: ScriptedTransport) async {
        await transport.on(prefix: "GET threads/\(threadID)/events") { request in
            await self.answer(request)
        }
    }

    private func answer(_ request: URLRequest) -> FakeTransport.Result {
        let path = request.url?.path ?? ""
        let encoder = JSONEncoder()
        if let last = path.split(separator: "/").last, let sequence = Int(last), path.contains("/events/") {
            guard sequence <= publishedThrough, let event = events.first(where: { $0.sequence == sequence }),
                let body = try? encoder.encode(event)
            else { return .json(404, #"{"error":"not_found","message":"Not found."}"#) }
            return .json(200, String(decoding: body, as: UTF8.self))
        }
        let items = URLComponents(url: request.url!, resolvingAgainstBaseURL: false)?.queryItems ?? []
        let after = items.first { $0.name == "after" }?.value.flatMap(Int.init) ?? 0
        let limit = min(items.first { $0.name == "limit" }?.value.flatMap(Int.init) ?? 500, 500)
        requestedAfters.append(after)
        let page = events.filter { $0.sequence > after && $0.sequence <= publishedThrough }.prefix(limit)
        struct Body: Encodable {
            let events: [ThreadEvent]
            let latestSequence: Int
            let source: String
        }
        guard
            let body = try? encoder.encode(
                Body(events: Array(page), latestSequence: publishedThrough, source: "test"))
        else { return .json(500, "{}") }
        return .json(200, String(decoding: body, as: UTF8.self))
    }
}
