import Foundation
import Testing

@testable import YakStub

@Suite struct ThreadActionRoutesTests {
    private func context(_ scenario: String, core name: String = "scenario-thread-list") throws -> StubContext {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let fixtures = try StubFixtures(
            directory: directory.appendingPathComponent("fixtures"),
            scenariosDirectory: directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
        let data = try Data(
            contentsOf: directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios/\(name).json"))
        return StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1", fixtures: fixtures, threads: StubThreads(),
            core: { .reply(status: 200, contentType: "application/json", body: data) })
    }

    private func call(
        _ feature: StubThreadActions, _ method: String, _ path: String, body: String = "",
        query: [String: String] = [:],
        scenario: String = "thread-actions", core: String = "scenario-thread-list"
    ) throws -> (status: Int, json: [String: Any]) {
        var request = StubRequest(method: method, path: "/api/app/v1/\(path)", query: query)
        request.body = Data(body.utf8)
        let reply = try #require(
            feature.answer(route: "\(method) \(path)", request: request, context: try context(scenario, core: core)))
        guard case .reply(let status, _, let data) = reply.answer else { throw URLError(.badServerResponse) }
        return (status, (try? JSONSerialization.jsonObject(with: data) as? [String: Any]) ?? [:])
    }

    private func ids(_ rows: Any?) -> [String] { (rows as? [[String: Any]] ?? []).compactMap { $0["id"] as? String } }

    @Test func aStarMovesTheRowAndAnswersItsRow() throws {
        let feature = StubThreadActions()
        let answer = try call(feature, "POST", "threads/a1b2c3d4/star", body: #"{"starred":true}"#)
        #expect(
            answer.status == 200 && answer.json["isStarred"] as? Bool == true
                && answer.json["type"] as? String == "thread")
        let list = try call(feature, "GET", "threads", query: ["filter": "mine"])
        #expect(ids(list.json["starred"]) == ["a1b2c3d4"] && ids(list.json["threads"]).isEmpty)
        _ = try call(feature, "POST", "threads/a1b2c3d4/star", body: #"{"starred":false}"#)
        #expect(ids(try call(feature, "GET", "threads", query: ["filter": "mine"]).json["threads"]) == ["a1b2c3d4"])
    }

    @Test func aClosedThreadLeavesMineAndShowsClosed() throws {
        let feature = StubThreadActions()
        let answer = try call(feature, "POST", "threads/a1b2c3d4/close")
        #expect(answer.json["status"] as? String == "closed")
        #expect(ids(try call(feature, "GET", "threads", query: ["filter": "mine"]).json["threads"]).isEmpty)
        let closed = try call(feature, "GET", "threads", query: ["filter": "closed"])
        let row = try #require((closed.json["threads"] as? [[String: Any]])?.first)
        #expect(row["status"] as? String == "closed")
        #expect((row["children"] as? [[String: Any]])?.first?["status"] as? String == "closed")
        let show = try call(feature, "GET", "threads/a1b2c3d4", core: "scenario-thread-show")
        #expect((show.json["composer"] as? [String: Any])?["state"] as? String == "disabled_closed")
    }

    @Test func aDeletedThreadIsGoneFromTheListAndItsPage() throws {
        let feature = StubThreadActions()
        let answer = try call(feature, "DELETE", "threads/a1b2c3d4")
        #expect(answer.status == 200 && answer.json["status"] as? String == "deleted")
        #expect(ids(try call(feature, "GET", "threads", query: ["filter": "mine"]).json["threads"]).isEmpty)
        #expect(try call(feature, "GET", "threads/a1b2c3d4", core: "scenario-thread-show").status == 404)
    }

    @Test func theRefusalsAreTheServers() throws {
        let forbidden = StubThreadActions()
        #expect(
            try call(forbidden, "POST", "threads/a1b2c3d4/close", scenario: "thread-actions-forbidden").status == 403)
        #expect(try call(forbidden, "DELETE", "threads/a1b2c3d4", scenario: "thread-actions-recipient").status == 403)
        let gone = StubThreadActions()
        #expect(try call(gone, "DELETE", "threads/a1b2c3d4", scenario: "thread-actions-gone").status == 404)
        let conflict = StubThreadActions()
        let first = try call(conflict, "DELETE", "threads/a1b2c3d4", scenario: "thread-actions-conflict")
        #expect(first.status == 409 && first.json["error"] as? String == "sandbox_unavailable")
        #expect(first.json["message"] as? String == StubThreadActions.sandboxSentence)
        #expect(try call(conflict, "DELETE", "threads/a1b2c3d4", scenario: "thread-actions-conflict").status == 200)
    }

    @Test func aRecipientReadsTheThreadAsReadOnly() throws {
        let feature = StubThreadActions()
        let list = try call(feature, "GET", "threads", scenario: "thread-actions-recipient")
        let rows = (list.json["threads"] as? [[String: Any]] ?? [])
        #expect(rows.allSatisfy { $0["canDelete"] as? Bool == false })
        let show = try call(
            feature, "GET", "threads/a1b2c3d4", scenario: "thread-actions-recipient", core: "scenario-thread-show")
        #expect(show.json["canDrive"] as? Bool == false)
    }

    @Test func theLogRecordsWhatTheAppSent() throws {
        let feature = StubThreadActions()
        _ = try call(feature, "POST", "threads/a1b2c3d4/star", body: #"{"starred":true}"#)
        _ = try call(feature, "DELETE", "threads/a1b2c3d4")
        let reply = try #require(feature.control(StubRequest(method: "GET", path: "/__stub/thread-actions/log")))
        guard case .reply(_, _, let data) = reply.answer else { throw URLError(.badServerResponse) }
        #expect(
            String(decoding: data, as: UTF8.self)
                == "POST threads/a1b2c3d4/star {\"starred\":true}\nDELETE threads/a1b2c3d4")
    }
}

@Suite struct ThreadActionHoldTests {
    @Test func theSlowScenarioHoldsItsDeleteUntilTheRelease() throws {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let fixtures = try StubFixtures(
            directory: directory.appendingPathComponent("fixtures"),
            scenariosDirectory: directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
        let context = StubContext(
            scenario: "thread-actions-slow", baseURL: "http://127.0.0.1", fixtures: fixtures, threads: StubThreads(),
            core: { .drop })
        let feature = StubThreadActions()
        let held = try #require(
            feature.answer(
                route: "DELETE threads/a1b2c3d4", request: StubRequest(method: "DELETE", path: "x"), context: context))
        #expect(held.hold != nil)
        let plain = StubThreadActions()
        let quick = try #require(
            plain.answer(
                route: "DELETE threads/a1b2c3d4", request: StubRequest(method: "DELETE", path: "x"),
                context: StubContext(
                    scenario: "thread-actions", baseURL: "http://127.0.0.1", fixtures: fixtures,
                    threads: StubThreads(), core: { .drop })))
        #expect(quick.hold == nil)
    }
}
