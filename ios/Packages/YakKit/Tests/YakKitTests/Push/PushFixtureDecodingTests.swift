import Foundation
import Testing

@testable import YakKit

/// One decode test per push fixture. A fixture that is not on the branch yet skips its test visibly.
private func decodeFixture<T: Decodable>(_ type: T.Type, _ name: String) throws -> T {
    try JSONDecoder().decode(type, from: Fixtures.data(name))
}

@Test(.enabled(if: Fixtures.exists("notifications")))
func decodesNotifications() throws {
    let preferences = try decodeFixture(NotificationPreferences.self, "notifications")
    for event in PushEvent.switches { #expect(preferences.events[event.rawValue] != nil) }
}

@Test(.enabled(if: Fixtures.exists("notifications-updated")))
func decodesNotificationsUpdated() throws {
    let preferences = try decodeFixture(NotificationPreferences.self, "notifications-updated")
    for event in PushEvent.switches { #expect(preferences.events[event.rawValue] != nil) }
    #expect(preferences.events.count == 4)
}

@Test(.enabled(if: Fixtures.exists("push-test")))
func decodesPushTest() throws {
    #expect(!(try decodeFixture(PushTestResult.self, "push-test").status.isEmpty))
}

@Test(.enabled(if: Fixtures.exists("thread-live-activity-channel")))
func decodesTheChannel() throws {
    #expect(!(try decodeFixture(LiveActivityChannel.self, "thread-live-activity-channel").channel.isEmpty))
}

private func checkPushError(_ name: String, code: String) throws {
    let object = try #require(try Fixtures.object(name) as? [String: Any])
    #expect(object["error"] as? String == code)
    let details = object["details"] as? [String: Any] ?? [:]
    if code == "push_environment_mismatch" {
        #expect(details["expected"] is String && details["given"] is String)
    }
    if code == "push_rejected" { #expect(details["reason"] is String) }
    let members = try JSONDecoder().decode(
        [String: JSONValue].self, from: JSONSerialization.data(withJSONObject: details))
    let error = APIError(status: 409, code: code, message: object["message"] as? String ?? "", details: members)
    if case .other = PushProblem(.server(error)) { Issue.record("\(name) maps to .other") }
}

@Test(.enabled(if: Fixtures.exists("error-push-unavailable")))
func decodesThePushUnavailableError() throws {
    try checkPushError("error-push-unavailable", code: "push_unavailable")
}

@Test(.enabled(if: Fixtures.exists("error-push-environment-mismatch")))
func decodesThePushEnvironmentMismatchError() throws {
    try checkPushError("error-push-environment-mismatch", code: "push_environment_mismatch")
}

@Test(.enabled(if: Fixtures.exists("error-push-rejected")))
func decodesThePushRejectedError() throws {
    try checkPushError("error-push-rejected", code: "push_rejected")
}

@Test(.enabled(if: Fixtures.exists("error-no-push-token")))
func decodesTheNoPushTokenError() throws {
    try checkPushError("error-no-push-token", code: "no_push_token")
}

private func threadFixtureHasAChannel() -> Bool {
    guard Fixtures.exists("thread"), let object = try? Fixtures.object("thread") as? [String: Any] else {
        return false
    }
    return object["liveActivityChannel"] != nil
}

@Test(.enabled(if: threadFixtureHasAChannel()))
func theThreadFixtureCarriesAChannel() throws {
    let object = try #require(try Fixtures.object("thread") as? [String: Any])
    #expect(object["liveActivityChannel"] is String)
    #expect(try decodeFixture(ThreadShow.self, "thread").liveActivityChannel != nil)
}

@Test(.enabled(if: Fixtures.exists("thread-question")))
func theQuestionFixtureHasNoChannel() throws {
    #expect(try decodeFixture(ThreadShow.self, "thread-question").liveActivityChannel == nil)
}

private func checkAlert(_ name: String) throws -> PushPayload.Yak {
    try #require(try decodeFixture(PushPayload.self, name).yak)
}

@Test(.enabled(if: Fixtures.exists("push/alert-needs-you")))
func decodesTheNeedsYouAlert() throws { _ = try checkAlert("push/alert-needs-you") }

@Test(.enabled(if: Fixtures.exists("push/alert-turn-finished")))
func decodesTheTurnFinishedAlert() throws { _ = try checkAlert("push/alert-turn-finished") }

@Test(.enabled(if: Fixtures.exists("push/alert-pull-request")))
func decodesThePullRequestAlert() throws { _ = try checkAlert("push/alert-pull-request") }

@Test(.enabled(if: Fixtures.exists("push/alert-private")))
func decodesThePrivateAlert() throws { _ = try checkAlert("push/alert-private") }

@Test(.enabled(if: Fixtures.exists("push/alert-account")))
func decodesTheAccountAlert() throws { #expect(try checkAlert("push/alert-account").id == nil) }

private func checkLiveActivity(_ name: String) throws {
    let object = try #require(try Fixtures.object(name) as? [String: Any])
    let aps = try #require(object["aps"] as? [String: Any])
    let state = try JSONDecoder().decode(
        ThreadActivityContentState.self,
        from: JSONSerialization.data(withJSONObject: try #require(aps["content-state"])))
    guard name.hasPrefix("push/live-activity-start") else { return }
    #expect(aps["attributes-type"] as? String == "ThreadActivityAttributes")
    #expect(Set((aps["attributes"] as? [String: Any] ?? [:]).keys) == ["instance", "threadId", "title"])
    if name.hasSuffix("-private") { #expect(state.isPrivate && state.step.isEmpty) }
}

@Test(.enabled(if: Fixtures.exists("push/live-activity-start")))
func decodesTheLiveActivityStart() throws { try checkLiveActivity("push/live-activity-start") }

@Test(.enabled(if: Fixtures.exists("push/live-activity-start-private")))
func decodesThePrivateLiveActivityStart() throws { try checkLiveActivity("push/live-activity-start-private") }

@Test(.enabled(if: Fixtures.exists("push/live-activity-update")))
func decodesTheLiveActivityUpdate() throws { try checkLiveActivity("push/live-activity-update") }

@Test(.enabled(if: Fixtures.exists("push/live-activity-end")))
func decodesTheLiveActivityEnd() throws { try checkLiveActivity("push/live-activity-end") }
