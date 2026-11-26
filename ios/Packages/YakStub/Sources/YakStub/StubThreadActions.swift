import Foundation

/// Star, close and delete a thread. The list and the thread pages of the core stub are edited by what the app did,
/// so a star, a closed thread and a deleted one show in the next list and page the app asks for. A new scenario
/// resets everything.
final class StubThreadActions: StubFeature, @unchecked Sendable {
    static let slowDelay = Duration.seconds(5)
    static let sandboxSentence = "The sandbox could not be destroyed yet. Try again in a moment."

    let name = "thread-actions"
    let scenarios = [
        "thread-actions", "thread-actions-recipient", "thread-actions-forbidden", "thread-actions-gone",
        "thread-actions-conflict", "thread-actions-slow",
    ]

    private struct State {
        var starred: Set<String> = []
        var closed: Set<String> = []
        var deleted: Set<String> = []
        var deleteAttempts = 0
        var log: [String] = []
    }

    private let state = Locked(State())

    func reset() { state.set(State()) }

    func control(_ request: StubRequest) -> StubReply? {
        switch (request.method, request.path) {
        case ("GET", "/__stub/thread-actions/log"):
            return text(state.get().log.joined(separator: "\n"))
        case ("POST", "/__stub/thread-actions/delete-elsewhere"):
            let id = String(decoding: request.body, as: UTF8.self).trimmingCharacters(in: .whitespacesAndNewlines)
            state.mutate { $0.deleted.insert(id) }
            return text("ok")
        default:
            return nil
        }
    }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        guard scenarios.contains(context.scenario) else { return nil }
        let parts = route.split(separator: " ", maxSplits: 1).map(String.init)
        guard parts.count == 2 else { return nil }
        let method = parts[0]
        let path = parts[1].split(separator: "/").map(String.init)
        guard path.first == "threads" else { return nil }
        switch (method, path.count) {
        case ("GET", 1): return list(request, context)
        case ("GET", 2) where path[1] != "new": return show(path[1], context)
        case ("POST", 3) where path[2] == "star": return star(path[1], request, context)
        case ("POST", 3) where path[2] == "close": return close(path[1], context)
        case ("DELETE", 2): return delete(path[1], context)
        default: return nil
        }
    }

    // MARK: Reading

    private func object(_ answer: StubAnswer) -> [String: Any]? {
        guard case .reply(_, _, let body) = answer else { return nil }
        return (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
    }

    private func reply(_ object: Any, status: Int = 200, delay: Duration = .zero) -> StubReply {
        let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: status, contentType: "application/json", body: data), delay: delay)
    }

    private func text(_ string: String) -> StubReply {
        StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(string.utf8)))
    }

    private func failure(_ status: Int, _ code: String, _ message: String, delay: Duration = .zero) -> StubReply {
        StubReply(answer: Scenario.error(status, code, message), delay: delay)
    }

    private func notFound() -> StubReply { failure(404, "not_found", "This thread does not exist.") }

    /// One row as the list shows it now, nil once it is deleted.
    private func edited(_ row: [String: Any], scenario: String, parentIsClosed: Bool = false) -> [String: Any]? {
        let id = row["id"] as? String ?? ""
        let current = state.get()
        guard !current.deleted.contains(id) else { return nil }
        var row = row
        row["isStarred"] = current.starred.contains(id)
        if scenario == "thread-actions-recipient" { row["canDelete"] = false }
        let isClosed = parentIsClosed || current.closed.contains(id)
        if isClosed {
            row["status"] = "closed"
            row["needsYouReason"] = NSNull()
            row["needsYouLabel"] = NSNull()
        }
        row["children"] = (row["children"] as? [[String: Any]] ?? []).compactMap {
            edited($0, scenario: scenario, parentIsClosed: isClosed)
        }
        return row
    }

    private func flatten(_ row: [String: Any]) -> [[String: Any]] {
        [row] + (row["children"] as? [[String: Any]] ?? []).flatMap(flatten)
    }

    /// The rows of the scenario's own list, before anything was done to them.
    private func baseRows(_ context: StubContext) -> [[String: Any]] {
        let base = context.fixtures.scenario("scenario-thread-list", baseURL: context.baseURL)
        return
            ((base["needsYou"] as? [[String: Any]] ?? []).filter { $0["type"] as? String == "thread" }
            + (base["starred"] as? [[String: Any]] ?? []) + (base["threads"] as? [[String: Any]] ?? []))
            .flatMap(flatten)
    }

    private func list(_ request: StubRequest, _ context: StubContext) -> StubReply? {
        guard var base = object(context.core()) else { return nil }
        let wantsClosed = request.query["filter"] == "closed"
        let current = state.get()
        func keep(_ row: [String: Any]) -> Bool {
            current.closed.contains(row["id"] as? String ?? "") == wantsClosed
        }
        let items = (base["needsYou"] as? [[String: Any]] ?? []).compactMap { item -> [String: Any]? in
            item["type"] as? String == "thread" ? edited(item, scenario: context.scenario) : item
        }
        let threadRows = (base["starred"] as? [[String: Any]] ?? []) + (base["threads"] as? [[String: Any]] ?? [])
        let rows = (items.filter { $0["type"] as? String == "thread" } + threadRows)
            .compactMap { edited($0, scenario: context.scenario) }
            .filter(keep)
        let tasks = wantsClosed ? [] : items.filter { $0["type"] as? String != "thread" }
        let needy = rows.filter { $0["needsYouReason"] is String }
        let others = rows.filter { !($0["needsYouReason"] is String) }
        base["needsYou"] = needy + tasks
        base["starred"] = others.filter { $0["isStarred"] as? Bool == true }
        base["threads"] = others.filter { $0["isStarred"] as? Bool != true }
        base["needsYouCount"] = needy.count + tasks.count
        return reply(base)
    }

    private func show(_ id: String, _ context: StubContext) -> StubReply? {
        guard !state.get().deleted.contains(id) else { return notFound() }
        guard var page = object(context.core()) else { return nil }
        let current = state.get()
        var thread = page["thread"] as? [String: Any] ?? [:]
        thread["isStarred"] = current.starred.contains(id)
        let isClosed = current.closed.contains(id)
        if isClosed {
            thread["status"] = "closed"
            var composer = page["composer"] as? [String: Any] ?? [:]
            composer["state"] = "disabled_closed"
            page["composer"] = composer
            page["actions"] = NSNull()
        }
        if context.scenario == "thread-actions-recipient" {
            page["canDrive"] = false
            thread["isOwner"] = false
            var composer = page["composer"] as? [String: Any] ?? [:]
            composer["state"] = "readonly"
            page["composer"] = composer
        }
        page["thread"] = thread
        return reply(page)
    }

    // MARK: Acting

    private func rowAnswer(_ id: String, _ context: StubContext) -> [String: Any]? {
        guard let base = baseRows(context).first(where: { $0["id"] as? String == id }),
            var row = edited(base, scenario: context.scenario)
        else { return nil }
        row["type"] = "thread"
        return row
    }

    private func note(_ line: String) { state.mutate { $0.log.append(line) } }

    private func star(_ id: String, _ request: StubRequest, _ context: StubContext) -> StubReply? {
        note("POST threads/\(id)/star \(String(decoding: request.body, as: UTF8.self))")
        if context.scenario == "thread-actions-gone" { return notFound() }
        let body = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
        let isStarred = body["starred"] as? Bool ?? true
        state.mutate {
            if isStarred { $0.starred.insert(id) } else { $0.starred.remove(id) }
        }
        return rowAnswer(id, context).map { reply($0) } ?? notFound()
    }

    private func close(_ id: String, _ context: StubContext) -> StubReply? {
        note("POST threads/\(id)/close")
        switch context.scenario {
        case "thread-actions-gone": return notFound()
        case "thread-actions-forbidden", "thread-actions-recipient":
            return failure(403, "forbidden", "You cannot do this on this thread.")
        default: break
        }
        state.mutate { _ = $0.closed.insert(id) }
        let delay = context.scenario == "thread-actions-slow" ? Self.slowDelay : Duration.zero
        return rowAnswer(id, context).map { reply($0, delay: delay) } ?? notFound()
    }

    private func delete(_ id: String, _ context: StubContext) -> StubReply? {
        note("DELETE threads/\(id)")
        let attempt = state.mutate { current -> Int in
            current.deleteAttempts += 1
            return current.deleteAttempts
        }
        switch context.scenario {
        case "thread-actions-gone": return notFound()
        case "thread-actions-forbidden", "thread-actions-recipient":
            return failure(403, "forbidden", "You cannot do this on this thread.")
        case "thread-actions-conflict" where attempt == 1:
            return failure(409, "sandbox_unavailable", Self.sandboxSentence)
        default: break
        }
        let delay = context.scenario == "thread-actions-slow" ? Self.slowDelay : Duration.zero
        state.mutate { _ = $0.deleted.insert(id) }
        return reply(["id": id, "status": "deleted"], delay: delay)
    }
}
