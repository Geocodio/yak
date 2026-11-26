import Foundation
import Testing

@testable import YakStub

@Suite struct SearchRoutesTests {
    private func context(_ scenario: String) throws -> StubContext {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let fixtures = try StubFixtures(
            directory: directory.appendingPathComponent("fixtures"),
            scenariosDirectory: directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
        return StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1", fixtures: fixtures, threads: StubThreads(),
            core: { .reply(status: 200, contentType: "application/json", body: Data("{}".utf8)) })
    }

    private func search(
        _ feature: StubSearch, _ query: String?, scenario: String = "search"
    ) throws -> (reply: StubReply, status: Int, json: [String: Any]) {
        var request = StubRequest(method: "GET", path: "/api/app/v1/search", query: [:])
        if let query { request.query["q"] = query }
        let reply = try #require(
            feature.answer(route: "GET search", request: request, context: try context(scenario)))
        guard case .reply(let status, _, let data) = reply.answer else { throw URLError(.badServerResponse) }
        return (reply, status, (try? JSONSerialization.jsonObject(with: data) as? [String: Any]) ?? [:])
    }

    private func results(_ json: [String: Any]) -> [[String: Any]] { json["results"] as? [[String: Any]] ?? [] }

    private func control(_ feature: StubSearch, _ method: String, _ path: String) -> StubReply? {
        feature.control(StubRequest(method: method, path: "/__stub/search/\(path)"))
    }

    @Test func aQueryOfRetryFindsAllThreeGroupsWithAndWithoutAThread() throws {
        let found = try search(StubSearch(), "retry")
        #expect(found.status == 200)
        let rows = results(found.json)
        #expect(rows.compactMap { $0["type"] as? String } == ["thread", "repository", "pull_request", "pull_request"])
        let withThread = try #require(rows.first { $0["id"] as? String == "https://github.com/example/web/pull/2301" })
        let without = try #require(rows.last)
        #expect(withThread["threadId"] as? String == "d0e515ef" && without["threadId"] is NSNull)
        #expect(without["path"] as? String == "/pr-reviews/88")
        let thread = try #require(rows.first)
        #expect(thread["threadId"] as? String == thread["id"] as? String)
        #expect((rows[1]["path"] as? String)?.hasPrefix("/") == true)
    }

    @Test func everyResultHasTheServersFields() throws {
        let keys: Set<String> = ["type", "id", "title", "subtitle", "threadId", "path", "url"]
        for row in results(try search(StubSearch(), "retry").json) { #expect(Set(row.keys) == keys) }
    }

    @Test func aShortOrMissingQueryIsRefusedLikeTheServer() throws {
        for query in [nil, "", "a", " a "] {
            let refused = try search(StubSearch(), query)
            #expect(refused.status == 422 && refused.json["error"] as? String == "validation_failed")
        }
    }

    @Test func noMatchAnswersAnEmptyList() throws {
        #expect(results(try search(StubSearch(), "zzzz").json).isEmpty)
        #expect(results(try search(StubSearch(), "retry", scenario: "search-empty").json).isEmpty)
    }

    @Test func theQueriesAreLoggedButARefusedOneIsNot() throws {
        let feature = StubSearch()
        _ = try search(feature, "a")
        _ = try search(feature, " retry ")
        let log = try #require(control(feature, "GET", "log"))
        guard case .reply(_, _, let body) = log.answer else { return }
        #expect(String(decoding: body, as: UTF8.self) == "retry")
    }

    @Test func theFailingScenarioFailsUntilItRelents() throws {
        let feature = StubSearch()
        #expect(try search(feature, "retry", scenario: "search-failing").status == 500)
        _ = control(feature, "POST", "relent")
        #expect(try search(feature, "retry", scenario: "search-failing").status == 200)
    }

    @Test func theLimitedScenarioSaysWhenToTryAgainUntilItRelents() throws {
        let feature = StubSearch()
        let limited = try search(feature, "retry", scenario: "search-limited")
        #expect(limited.status == 429 && limited.reply.headers["Retry-After"] == "2")
        _ = control(feature, "POST", "relent")
        #expect(try search(feature, "retry", scenario: "search-limited").status == 200)
    }

    @Test func theOneScenarioAnswersASingleThreadForAnyQuery() throws {
        for query in ["retry", "zzzz"] {
            let rows = results(try search(StubSearch(), query, scenario: "search-one").json)
            #expect(rows.count == 1 && rows.first?["type"] as? String == "thread")
        }
    }

    @Test func theLongLimitedScenarioWaitsThirtySeconds() throws {
        let limited = try search(StubSearch(), "retry", scenario: "search-limited-long")
        #expect(limited.status == 429 && limited.reply.headers["Retry-After"] == "30")
    }

    @Test func theSlowScenarioHoldsTheAnswerUntilReleased() async throws {
        let feature = StubSearch()
        #expect(try search(feature, "retry").reply.hold == nil)
        let hold = try #require(try search(feature, "retry", scenario: "search-slow").reply.hold)
        _ = control(feature, "POST", "release")
        await hold()
    }

    @Test func otherScenariosAndRoutesAreNotItsOwn() throws {
        var request = StubRequest(method: "GET", path: "/api/app/v1/search", query: ["q": "retry"])
        #expect(StubSearch().answer(route: "GET search", request: request, context: try context("paired")) == nil)
        request.path = "/api/app/v1/threads"
        #expect(StubSearch().answer(route: "GET threads", request: request, context: try context("search")) == nil)
    }
}
