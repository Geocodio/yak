import Foundation
import Testing

@testable import YakStub

@Suite struct AgentsRoutesTests {
    private let thread = "d0e515ef"
    private let show = #"{"thread":{"id":"d0e515ef"},"canDrive":true,"composer":{"state":"ready"}}"#

    private func context(_ scenario: String) throws -> StubContext {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        return StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1:5555",
            fixtures: try StubFixtures(directory: directory.appendingPathComponent("fixtures")),
            threads: StubThreads(), core: { .reply(status: 200, contentType: "application/json", body: Data(show.utf8)) })
    }

    private func started(_ scenario: String) throws -> (StubAgents, StubContext) {
        let feature = StubAgents()
        feature.reset()
        feature.played(scenario: scenario)
        return (feature, try context(scenario))
    }

    private func call(_ feature: StubAgents, _ method: String, _ path: String, _ context: StubContext) throws
        -> (status: Int, object: [String: Any], headers: [String: String])
    {
        let request = StubRequest(method: method, path: "/api/app/v1/\(path)", headers: [:], body: Data())
        let reply = try #require(feature.answer(route: "\(method) \(path)", request: request, context: context))
        guard case .reply(let status, _, let body) = reply.answer else { return (0, [:], [:]) }
        return (status, (try? JSONSerialization.jsonObject(with: body)) as? [String: Any] ?? [:], reply.headers)
    }

    private func control(_ feature: StubAgents, _ method: String, _ path: String, _ body: String = "") -> String? {
        let request = StubRequest(method: method, path: "/__stub/agents/\(path)", headers: [:], body: Data(body.utf8))
        guard case .reply(200, _, let data)? = feature.control(request)?.answer else { return nil }
        return String(decoding: data, as: UTF8.self)
    }

    @Test func theShowListsTheAgentsThatRun() throws {
        let (feature, context) = try started("agents-three")
        let agents = try #require(try call(feature, "GET", "threads/\(thread)", context).object["agents"] as? [String: Any])
        #expect(agents["runningCount"] as? Int == 3 && (agents["running"] as? [Any])?.count == 3)
    }

    @Test func stoppingAnswersLikeTheServerAndCountsTheRequest() throws {
        let (feature, context) = try started("agents-two")
        let answer = try call(feature, "POST", "threads/\(thread)/agents/stop", context)
        #expect(answer.status == 200 && answer.object["status"] as? String == "stopping")
        #expect(answer.object["stopped"] as? Int == 2)
        let agents = try #require(answer.object["agents"] as? [String: Any])
        #expect(agents["runningCount"] as? Int == 2)
        #expect(control(feature, "GET", "log") == "POST threads/\(thread)/agents/stop")
    }

    @Test func zeroAnswersIdleAndTheReportFollows() throws {
        let (feature, context) = try started("agents-two")
        #expect(control(feature, "POST", "mode", "zero") == "ok")
        let answer = try call(feature, "POST", "threads/\(thread)/agents/stop", context)
        #expect(answer.object["status"] as? String == "idle")
        #expect((answer.object["agents"] as? [String: Any])?["runningCount"] as? Int == 0)
        #expect(control(feature, "GET", "report")?.contains("\"running\":0") == true)
    }

    @Test func failureAndRateLimitAnswerTheServersErrors() throws {
        let (feature, context) = try started("agents-two")
        _ = control(feature, "POST", "mode", "fail")
        #expect(try call(feature, "POST", "threads/\(thread)/agents/stop", context).status == 500)
        _ = control(feature, "POST", "mode", "limited")
        let limited = try call(feature, "POST", "threads/\(thread)/agents/stop", context)
        #expect(limited.status == 429 && limited.object["error"] as? String == "rate_limited")
        #expect(limited.headers["Retry-After"] == "120")
    }

    @Test func aViewerCannotStopAndDoesNotDrive() throws {
        let (feature, context) = try started("agents-viewer")
        let refused = try call(feature, "POST", "threads/\(thread)/agents/stop", context)
        #expect(refused.status == 403 && refused.object["error"] as? String == "forbidden")
        #expect(try call(feature, "GET", "threads/\(thread)", context).object["canDrive"] as? Bool == false)
    }

    @Test func aSlowAnswerWaitsForTheRelease() throws {
        let (feature, context) = try started("agents-one")
        _ = control(feature, "POST", "mode", "slow")
        let request = StubRequest(method: "POST", path: "/", headers: [:], body: Data())
        let reply = try #require(
            feature.answer(route: "POST threads/\(thread)/agents/stop", request: request, context: context))
        #expect(reply.hold != nil)
    }

    @Test func otherScenariosAreLeftAlone() throws {
        let (feature, _) = try started("agents-two")
        let other = try context("embed-basic")
        let request = StubRequest(method: "GET", path: "/", headers: [:], body: Data())
        #expect(feature.answer(route: "GET threads/\(thread)", request: request, context: other) == nil)
    }
}
