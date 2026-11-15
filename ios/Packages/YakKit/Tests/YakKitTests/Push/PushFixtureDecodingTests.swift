import Foundation
import Testing

@testable import YakKit

/// One decode test per push fixture. A missing fixture fails its test.
private func requireFixture(_ name: String) throws {
    try #require(Fixtures.exists(name), "The server fixture \(name) is missing.")
}

private func decodeFixture<T: Decodable>(_ type: T.Type, _ name: String) throws -> T {
    try JSONDecoder().decode(type, from: Fixtures.data(name))
}

@Test
func decodesNotifications() throws {
    try requireFixture("notifications")
    let preferences = try decodeFixture(NotificationPreferences.self, "notifications")
    for event in PushEvent.switches { #expect(preferences.events[event.rawValue] != nil) }
}

@Test
func decodesNotificationsUpdated() throws {
    try requireFixture("notifications-updated")
    let preferences = try decodeFixture(NotificationPreferences.self, "notifications-updated")
    for event in PushEvent.switches { #expect(preferences.events[event.rawValue] != nil) }
    #expect(preferences.events.count == 4)
}

@Test
func decodesPushTest() throws {
    try requireFixture("push-test")
    #expect(!(try decodeFixture(PushTestResult.self, "push-test").status.isEmpty))
}

@Test
func decodesTheChannel() throws {
    try requireFixture("thread-live-activity-channel")
    #expect(!(try decodeFixture(LiveActivityChannel.self, "thread-live-activity-channel").channel.isEmpty))
}

private func checkPushError(_ name: String, code: String) throws {
    try requireFixture(name)
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

@Test
func decodesThePushUnavailableError() throws {
    try requireFixture("error-push-unavailable")
    try checkPushError("error-push-unavailable", code: "push_unavailable")
}

@Test
func decodesThePushEnvironmentMismatchError() throws {
    try requireFixture("error-push-environment-mismatch")
    try checkPushError("error-push-environment-mismatch", code: "push_environment_mismatch")
}

@Test
func decodesThePushRejectedError() throws {
    try requireFixture("error-push-rejected")
    try checkPushError("error-push-rejected", code: "push_rejected")
}

@Test
func decodesTheNoPushTokenError() throws {
    try requireFixture("error-no-push-token")
    try checkPushError("error-no-push-token", code: "no_push_token")
}

@Test
func theThreadFixtureCarriesAChannel() throws {
    try requireFixture("thread")
    let object = try #require(try Fixtures.object("thread") as? [String: Any])
    #expect(object["liveActivityChannel"] is String)
    #expect(try decodeFixture(ThreadShow.self, "thread").liveActivityChannel != nil)
}

@Test
func theQuestionFixtureHasNoChannel() throws {
    try requireFixture("thread-question")
    #expect(try decodeFixture(ThreadShow.self, "thread-question").liveActivityChannel == nil)
}

private func checkAlert(_ name: String) throws -> PushPayload.Yak {
    try requireFixture(name)
    return try #require(try decodeFixture(PushPayload.self, name).yak)
}

@Test
func decodesTheNeedsYouAlert() throws { _ = try checkAlert("push/alert-needs-you") }

@Test
func decodesTheTurnFinishedAlert() throws { _ = try checkAlert("push/alert-turn-finished") }

@Test
func decodesThePullRequestAlert() throws { _ = try checkAlert("push/alert-pull-request") }

@Test
func decodesThePrivateAlert() throws { _ = try checkAlert("push/alert-private") }

@Test
func decodesTheAccountAlert() throws { #expect(try checkAlert("push/alert-account").id == nil) }

private func checkLiveActivity(_ name: String) throws {
    try requireFixture(name)
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

@Test
func decodesTheLiveActivityStart() throws { try checkLiveActivity("push/live-activity-start") }

@Test
func decodesThePrivateLiveActivityStart() throws { try checkLiveActivity("push/live-activity-start-private") }

@Test
func decodesTheLiveActivityUpdate() throws { try checkLiveActivity("push/live-activity-update") }

@Test
func decodesTheLiveActivityEnd() throws { try checkLiveActivity("push/live-activity-end") }
