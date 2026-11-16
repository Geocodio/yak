import Foundation
import Testing

@testable import YakStub

@Suite struct TaskRoutesTests {
    private func context(_ scenario: String) throws -> StubContext {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let fixtures = try StubFixtures(
            directory: directory.appendingPathComponent("fixtures"),
            scenariosDirectory: directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
        return StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1", fixtures: fixtures, threads: StubThreads(),
            core: { .drop })
    }

    private func body(_ reply: StubReply?) throws -> [String: Any] {
        guard case .reply(_, _, let data)? = reply?.answer else { throw URLError(.badServerResponse) }
        return try #require(try JSONSerialization.jsonObject(with: data) as? [String: Any])
    }

    @Test func theListPagesAndTheDecisionSticks() throws {
        let feature = StubChanges()
        let scenario = try context("changes")
        let first = try body(
            feature.answer(route: "GET tasks", request: StubRequest(method: "GET", path: "x", query: ["page": "1"]), context: scenario))
        #expect((first["tasks"] as? [Any])?.count == 3)
        #expect(first["nextPage"] as? Int == 2)
        let second = try body(
            feature.answer(route: "GET tasks", request: StubRequest(method: "GET", path: "x", query: ["page": "2"]), context: scenario))
        #expect((second["tasks"] as? [Any])?.count == 1)
        #expect(second["nextPage"] is NSNull)
        _ = feature.answer(
            route: "POST tasks/k3m9p2qa/co-owner/approve", request: StubRequest(method: "POST", path: "x"), context: scenario)
        let task = try body(
            feature.answer(route: "GET tasks/k3m9p2qa", request: StubRequest(method: "GET", path: "x"), context: scenario))
        #expect((task["coOwner"] as? [String: Any])?["status"] as? String == "approved")
    }

    @Test func anUnknownTaskIsNotFound() throws {
        let reply = StubChanges().answer(
            route: "GET tasks/zzzzzzzz", request: StubRequest(method: "GET", path: "x"), context: try context("changes"))
        guard case .reply(let status, _, _)? = reply?.answer else { throw URLError(.badServerResponse) }
        #expect(status == 404)
    }
}
