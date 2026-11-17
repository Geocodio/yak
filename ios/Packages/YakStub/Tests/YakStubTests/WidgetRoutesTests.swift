import Foundation
import Testing

@testable import YakStub

private var iosDirectory: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory
}

private let hexToken = String(repeating: "0123456789abcdef", count: 4)

@Suite struct WidgetRoutesTests {
    private let widgets = StubWidgets()

    private func call(_ method: String, _ path: String, _ body: String = "", scenario: String = "paired") throws
        -> (Int, [String: Any])
    {
        let fixtures = try StubFixtures(
            directory: iosDirectory.appendingPathComponent("fixtures"),
            scenariosDirectory: iosDirectory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
        let request = StubRequest(
            method: method, path: Scenario.apiPrefix + path, headers: ["authorization": "Bearer stub"],
            body: Data(body.utf8))
        let core: @Sendable () -> StubAnswer = {
            Scenario.paired.reply(to: request, baseURL: "http://127.0.0.1", fixtures: fixtures).answer
        }
        let context = StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1", fixtures: fixtures, threads: StubThreads(), core: core)
        guard let reply = widgets.answer(route: "\(method) \(path)", request: request, context: context) else {
            return (-1, [:])
        }
        guard case .reply(let status, _, let data) = reply.answer else { return (-1, [:]) }
        return (status, (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:])
    }

    private func control() throws -> [String: Any] {
        let reply = try #require(widgets.control(StubRequest(method: "GET", path: "/__stub/widgets")))
        guard case .reply(_, _, let data) = reply.answer else { return [:] }
        return try #require(try JSONSerialization.jsonObject(with: data) as? [String: Any])
    }

    @Test func storesAndDeletesWidgetTokens() throws {
        let body = "{\"token\":\"\(hexToken)\",\"environment\":\"production\"}"
        #expect(try call("PUT", "push-tokens/widget", body).0 == 204)
        #expect((try control()["tokens"] as? [[String: String]])?.count == 1)
        #expect(try call("DELETE", "push-tokens/widget").0 == 204)
        #expect(try control()["deletes"] as? Int == 1)
        #expect(try call("PUT", "push-tokens/widget", "{\"token\":\"xyz\"}").0 == 422)
    }

    @Test func anOldServerAnswers404() throws {
        #expect(try call("PUT", "push-tokens/widget", "{}", scenario: "widgets-old-server").0 == 404)
    }

    @Test func theOtherEnvironmentIsRefused() throws {
        let refused = try call("PUT", "push-tokens/widget", "{}", scenario: "widgets-other-environment")
        #expect(refused.0 == 409)
        #expect(refused.1["error"] as? String == "push_environment_mismatch")
    }

    @Test func theBusyListAnswersTheMineFilter() throws {
        let busy = try call("GET", "threads", scenario: "widgets-busy")
        #expect(busy.0 == 200 && (busy.1["needsYouCount"] as? Int ?? 0) == 4)
        let empty = try call("GET", "threads", scenario: "widgets-empty")
        #expect(empty.1["needsYouCount"] as? Int == 0)
    }

    @Test func pushStillOwnsTheOtherKinds() throws {
        let alert = "{\"token\":\"\(hexToken)\",\"environment\":\"production\"}"
        #expect(try call("PUT", "push-tokens/alert", alert).0 == -1)
        #expect(StubPush().answer(route: "PUT push-tokens/alert", request: StubRequest(method: "PUT", path: Scenario.apiPrefix + "push-tokens/alert", headers: [:], body: Data(alert.utf8)), context: StubContext(scenario: "paired", baseURL: "http://127.0.0.1", fixtures: try StubFixtures(directory: iosDirectory.appendingPathComponent("fixtures"), scenariosDirectory: iosDirectory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios")), threads: StubThreads(), core: { .reply(status: 200, contentType: "application/json", body: Data()) })) != nil)
    }
}
