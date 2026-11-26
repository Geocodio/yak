import Foundation

/// `GET search` as the server answers it: one flat list of thread, repository and pull request results, at most ten
/// of each kind, and a 422 for a query under two characters. The results come from the scenario's own thread list and
/// from the repositories and pull requests below. A query of "retry" finds all three groups, a pull request with a
/// thread and one without.
///
/// Scenarios: `search` (answers), `search-empty` (never finds anything), `search-slow` (the answer waits for
/// `POST /__stub/search/release`), `search-failing` (500 until `POST /__stub/search/relent`) and `search-limited`
/// (429 with `Retry-After: 2` until the same relent). `GET /__stub/search/log` lists the queries asked, one per line.
final class StubSearch: StubFeature, @unchecked Sendable {
    static let longestHold = Duration.seconds(120)
    static let limitedWaitSeconds = 2

    let name = "search"
    let scenarios = ["search", "search-empty", "search-slow", "search-failing", "search-limited"]

    private struct State {
        var isReleased = false
        var isRelented = false
        var log: [String] = []
    }

    private let state = Locked(State())

    private static let repositories: [(slug: String, name: String)] = [
        ("example/web", "Web app"), ("example/api", "API"), ("example/retry-worker", "Retry worker"),
    ]

    private static let pullRequests: [(title: String, repo: String, number: Int, threadID: String?, path: String?)] = [
        ("Retry failed rows when the import times out", "example/web", 2301, "d0e515ef", nil),
        ("Retry worker client timeouts", "example/retry-worker", 88, nil, "/pr-reviews/88"),
    ]

    func reset() { state.set(State()) }

    func control(_ request: StubRequest) -> StubReply? {
        switch (request.method, request.path) {
        case ("GET", "/__stub/search/log"):
            return text(state.get().log.joined(separator: "\n"))
        case ("POST", "/__stub/search/release"):
            state.mutate { $0.isReleased = true }
            return text("ok")
        case ("POST", "/__stub/search/relent"):
            state.mutate { $0.isRelented = true }
            return text("ok")
        default:
            return nil
        }
    }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        guard scenarios.contains(context.scenario), route == "GET search" else { return nil }
        let query = (request.query["q"] ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        guard query.count >= 2 else {
            return failure(422, "validation_failed", "The q field must be at least 2 characters.")
        }
        state.mutate { $0.log.append(query) }
        let current = state.get()
        switch context.scenario {
        case "search-failing" where !current.isRelented:
            return failure(500, "server_error", "Search is not available.")
        case "search-limited" where !current.isRelented:
            var limited = failure(429, "too_many_requests", "Too many searches.")
            limited.headers["Retry-After"] = String(Self.limitedWaitSeconds)
            return limited
        default:
            break
        }
        var reply = json(["results": context.scenario == "search-empty" ? [] : results(for: query, context)])
        if context.scenario == "search-slow" {
            reply.hold = { [state] in
                let deadline = ContinuousClock.now + Self.longestHold
                while !state.get().isReleased, ContinuousClock.now < deadline {
                    try? await Task.sleep(for: .milliseconds(100))
                }
            }
        }
        return reply
    }

    // MARK: Results

    private func results(for query: String, _ context: StubContext) -> [[String: Any]] {
        func matches(_ text: String) -> Bool { text.localizedStandardContains(query) }
        let list = context.fixtures.scenario("scenario-thread-list", baseURL: context.baseURL)
        func flatten(_ row: [String: Any]) -> [[String: Any]] {
            [row] + (row["children"] as? [[String: Any]] ?? []).flatMap(flatten)
        }
        let rows = ["needsYou", "starred", "threads"].flatMap { key in
            (list[key] as? [[String: Any]] ?? []).filter { $0["type"] as? String != "task" }.flatMap(flatten)
        }
        let threads = rows.filter { matches($0["title"] as? String ?? "") && $0["id"] is String }.prefix(10).map { row in
            let id = row["id"] as? String ?? ""
            return [
                "type": "thread", "id": id, "title": row["title"] as? String ?? "",
                "subtitle": "\(row["repo"] as? String ?? "") / yak/\(id)", "threadId": id,
                "path": "/threads/\(id)", "url": NSNull(),
            ] as [String: Any]
        }
        let repositories = Self.repositories.filter { matches($0.name) || matches($0.slug) }.map { repository in
            [
                "type": "repository", "id": repository.slug, "title": repository.name, "subtitle": repository.slug,
                "threadId": NSNull(), "path": "/repos/\(repository.slug)/edit",
                "url": "https://github.com/\(repository.slug)",
            ] as [String: Any]
        }
        let pullRequests = Self.pullRequests.filter { matches($0.title) || matches($0.repo) }.map { pullRequest in
            let url = "https://github.com/\(pullRequest.repo)/pull/\(pullRequest.number)"
            return [
                "type": "pull_request", "id": url, "title": pullRequest.title,
                "subtitle": "\(pullRequest.repo) #\(pullRequest.number)",
                "threadId": pullRequest.threadID ?? NSNull(), "path": pullRequest.path ?? NSNull(), "url": url,
            ] as [String: Any]
        }
        return Array(threads) + repositories + pullRequests
    }

    // MARK: Replies

    private func json(_ object: Any) -> StubReply {
        let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
    }

    private func text(_ string: String) -> StubReply {
        StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(string.utf8)))
    }

    private func failure(_ status: Int, _ code: String, _ message: String) -> StubReply {
        StubReply(answer: Scenario.error(status, code, message))
    }
}
