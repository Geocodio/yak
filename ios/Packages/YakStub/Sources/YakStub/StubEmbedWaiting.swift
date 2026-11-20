import Foundation

/// `embed-working`: a message sent while the turn runs is saved as a waiting (queued) message, which the thread's
/// show lists after the conversation until it is changed or withdrawn. It runs before the features that answer the
/// thread's show in every scenario.
final class StubEmbedWaiting: StubFeature, @unchecked Sendable {
    let name = "embed-waiting"
    private let lock = NSLock()
    private var waiting: [[String: Any]] = []

    func reset() { lock.withLock { waiting = [] } }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        guard context.scenario == StubEmbed.workingScenario else { return nil }
        let parts = route.split(separator: " ", maxSplits: 1).map(String.init)
        let path = parts.count == 2 ? parts[1].split(separator: "/").map(String.init) : []
        guard path.count >= 2, path[0] == "threads" else { return nil }
        switch (parts[0], Array(path.dropFirst(2))) {
        case ("GET", []):
            guard case .reply(200, let contentType, let body) = context.core(),
                var show = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
            else { return nil }
            show["messages"] = (show["messages"] as? [[String: Any]] ?? []) + lock.withLock { waiting }
            let edited = (try? JSONSerialization.data(withJSONObject: show)) ?? body
            return StubReply(answer: .reply(status: 200, contentType: contentType, body: edited))
        case ("POST", ["messages"]):
            let object = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
            let text = (object["message"] ?? object["text"]) as? String ?? ""
            let mode = String(decoding: request.body, as: UTF8.self).contains("after_turn") ? "queue" : "steer"
            let uuid = UUID().uuidString.lowercased()
            let row: [String: Any] = [
                "id": "queued-message-\(lock.withLock { waiting.count } + 1)", "uuid": uuid, "text": text,
                "authorName": NSNull(), "authorInitials": "ME", "state": "pending", "mode": mode,
                "sendKey": object["send_key"] ?? NSNull(), "sentAt": Date().formatted(.iso8601),
                "bodyHtml": "<p>\(text)</p>", "canEditText": true, "isRetry": false,
            ]
            lock.withLock { waiting.append(row) }
            let reply = ["status": "queued", "uuid": uuid]
            let data = (try? JSONSerialization.data(withJSONObject: reply)) ?? Data()
            return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
        case ("DELETE", let tail) where tail.count == 2 && tail[0] == "queued-messages":
            lock.withLock { waiting.removeAll { $0["uuid"] as? String == tail[1] } }
            return StubReply(answer: .reply(status: 204, contentType: "application/json", body: Data()))
        case ("PATCH", let tail) where tail.count == 2 && tail[0] == "queued-messages":
            let object = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
            lock.withLock {
                guard let index = waiting.firstIndex(where: { $0["uuid"] as? String == tail[1] }) else { return }
                if let mode = object["mode"] as? String { waiting[index]["mode"] = mode == "steer" ? "steer" : "queue" }
                if let text = object["text"] as? String { waiting[index]["text"] = text }
            }
            let data = (try? JSONSerialization.data(withJSONObject: ["status": "updated"])) ?? Data()
            return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
        default:
            return nil
        }
    }
}
