import Foundation
import Testing

@testable import YakStub

private var iosDirectory: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory
}

private let hexToken = String(repeating: "0123456789abcdef", count: 4)

@Suite struct PushRoutesTests {
    private let push = StubPush()
    private let fixtureDirectory: URL

    init(fixtureDirectory: URL? = nil) {
        self.fixtureDirectory = fixtureDirectory ?? iosDirectory.appendingPathComponent("fixtures")
    }

    private func call(
        _ method: String, _ path: String, _ body: String = "", scenario: String = "paired",
        fixtureDirectory: URL? = nil
    ) throws -> (Int, [String: Any]) {
        let fixtures = try StubFixtures(
            directory: fixtureDirectory ?? self.fixtureDirectory,
            scenariosDirectory: iosDirectory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
        let request = StubRequest(
            method: method, path: Scenario.apiPrefix + path, headers: ["authorization": "Bearer stub"],
            body: Data(body.utf8))
        let core: @Sendable () -> StubAnswer = {
            Scenario.paired.reply(to: request, baseURL: "http://127.0.0.1", fixtures: fixtures).answer
        }
        let context = StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1", fixtures: fixtures, threads: StubThreads(), core: core)
        let reply = try #require(push.answer(route: "\(method) \(path)", request: request, context: context))
        guard case .reply(let status, _, let data) = reply.answer else { return (-1, [:]) }
        let object = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:]
        return (status, object)
    }

    private func snapshot() throws -> [String: Any] {
        let reply = try #require(push.control(StubRequest(method: "GET", path: "/__stub/push")))
        guard case .reply(_, _, let data) = reply.answer else { return [:] }
        return try #require(try JSONSerialization.jsonObject(with: data) as? [String: Any])
    }

    private func tokens() throws -> [String: Any] { try snapshot()["tokens"] as? [String: Any] ?? [:] }

    @Test func storesTokensAndRefusesBadOnes() throws {
        let body = "{\"token\":\"\(hexToken)\",\"environment\":\"production\"}"
        #expect(try call("PUT", "push-tokens/alert", body).0 == 204)
        #expect(try tokens().keys.contains("alert"))
        let refused = try call("PUT", "push-tokens/alert", "{\"token\":\"xyz\",\"environment\":\"production\"}")
        #expect(refused.0 == 422)
        #expect(refused.1["error"] as? String == "validation_failed")
        #expect(try call("PUT", "push-tokens/live_activity", body).0 == 404)
        #expect(try call("DELETE", "push-tokens/alert").0 == 204)
        #expect(try tokens().isEmpty)
    }

    @Test func theTestNeedsAToken() throws {
        let missing = try call("POST", "push-tokens/alert/test")
        #expect(missing.0 == 409)
        #expect(missing.1["error"] as? String == "no_push_token")
        _ = try call("PUT", "push-tokens/alert", "{\"token\":\"\(hexToken)\",\"environment\":\"production\"}")
        let sent = try call("POST", "push-tokens/alert/test")
        #expect(sent.0 == 200)
        #expect(sent.1["status"] as? String == "sent")
    }

    @Test func preferencesMergeAndReadBack() throws {
        let updated = try call("PUT", "notifications", "{\"events\":{\"turn_finished\":false,\"bogus\":true}}")
        let events = try #require(updated.1["events"] as? [String: Any])
        #expect(events["turn_finished"] as? Bool == false)
        #expect(events["bogus"] == nil)
        let read = try call("GET", "notifications")
        #expect((read.1["events"] as? [String: Any])?["turn_finished"] as? Bool == false)
        #expect(try call("PUT", "notifications", "{\"events\":{}}").0 == 422)
    }

    @Test func theChannelIsOnlyForListedThreads() throws {
        let listed = try call("POST", "threads/d0e515ef/live-activity-channel")
        #expect(listed.0 == 200)
        #expect(listed.1["channel"] as? String == "c2NlbmFyaW8tY2hhbm5lbA")
        #expect(try call("POST", "threads/zzzzzzzz/live-activity-channel").0 == 404)
        let closed = try call("POST", "threads/c10sed00/live-activity-channel")
        #expect(closed.0 == 409)
        #expect(closed.1["error"] as? String == "thread_closed")
    }

    @Test func theActivitySetDropsUnknownThreads() throws {
        #expect(try call("PUT", "live-activities", "{\"threads\":[\"d0e515ef\",\"zzzzzzzz\"]}").0 == 204)
        #expect(try snapshot()["activities"] as? [String] == ["d0e515ef"])
    }

    @Test(arguments: ["push-unavailable", "push-other-build", "push-other-environment"])
    func pushScenariosEditMe(name: String) throws {
        let me = try call("GET", "me", scenario: name)
        #expect(me.0 == 200)
        let block = try #require(me.1["push"] as? [String: Any])
        switch name {
        case "push-unavailable":
            #expect(block["apns"] as? Bool == false)
            #expect(block["bundleId"] is NSNull)
            #expect(
                try call("POST", "push-tokens/alert/test", scenario: name).1["error"] as? String == "push_unavailable")
            #expect(
                try call("POST", "threads/d0e515ef/live-activity-channel", scenario: name).1["error"] as? String
                    == "push_unavailable")
        case "push-other-build":
            #expect(block["bundleId"] as? String == "com.example.other")
            #expect(block["environment"] as? String == "production")
        default:
            #expect(block["environment"] as? String == "sandbox")
            #expect(block["bundleId"] as? String == "com.example.yak")
        }
        #expect((me.1["user"] as? [String: Any])?["name"] as? String == "Test Person")
    }

    @Test func failingScenariosRefuse() throws {
        let body = "{\"token\":\"\(hexToken)\",\"environment\":\"production\"}"
        #expect(try call("PUT", "push-tokens/alert", body, scenario: "push-registration-failing").0 == 503)
        _ = try call("PUT", "push-tokens/alert", body)
        let rejected = try call("POST", "push-tokens/alert/test", scenario: "push-test-rejected")
        #expect(rejected.0 == 409)
        #expect(rejected.1["error"] as? String == "push_rejected")
        #expect((rejected.1["details"] as? [String: Any])?["reason"] as? String == "BadDeviceToken")
        let saving = try call(
            "PUT", "notifications", "{\"events\":{\"needs_you\":false}}", scenario: "notifications-failing")
        #expect(saving.0 == 503)
    }

    @Test func aNewScenarioResetsPushState() throws {
        _ = try call("PUT", "push-tokens/alert", "{\"token\":\"\(hexToken)\",\"environment\":\"production\"}")
        push.reset()
        #expect(try tokens().isEmpty)
    }

    @Test func serverFixturesServeTheirPushFiles() throws {
        let directory = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        for name in ["instance", "me", "device-code", "device-pair", "device-token"] {
            try Data(contentsOf: fixtureDirectory.appendingPathComponent("\(name).json"))
                .write(to: directory.appendingPathComponent("\(name).json"))
        }
        defer { try? FileManager.default.removeItem(at: directory) }
        let file = directory.appendingPathComponent("notifications.json")
        try Data(
            "{\"events\":{\"needs_you\":false,\"turn_finished\":false,\"stack_usage\":false,\"pull_request\":false}}"
                .utf8
        )
        .write(to: file)
        let served = try call("GET", "notifications", scenario: "server-fixtures", fixtureDirectory: directory)
        #expect((served.1["events"] as? [String: Any])?["needs_you"] as? Bool == false)
        let paired = try call("GET", "notifications", fixtureDirectory: directory)
        #expect((paired.1["events"] as? [String: Any])?["needs_you"] as? Bool == true)
    }
}
