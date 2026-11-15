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

@Test(
    arguments: [
        ("error-push-unavailable", "push_unavailable"),
        ("error-push-environment-mismatch", "push_environment_mismatch"),
        ("error-push-rejected", "push_rejected"),
        ("error-no-push-token", "no_push_token"),
    ])
func decodesThePushErrors(name: String, code: String) throws {
    guard Fixtures.exists(name) else { return }
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

@Test(.enabled(if: Fixtures.exists("thread")))
func theThreadFixtureCarriesAChannel() throws {
    let object = try #require(try Fixtures.object("thread") as? [String: Any])
    guard object["liveActivityChannel"] != nil else { return }
    #expect(try decodeFixture(ThreadShow.self, "thread").liveActivityChannel != nil)
}

@Test(.enabled(if: Fixtures.exists("thread-question")))
func theQuestionFixtureHasNoChannel() throws {
    #expect(try decodeFixture(ThreadShow.self, "thread-question").liveActivityChannel == nil)
}

@Test(
    arguments: [
        "push/alert-needs-you", "push/alert-turn-finished", "push/alert-pull-request", "push/alert-private",
        "push/alert-account",
    ])
func decodesTheAlertPayloads(name: String) throws {
    guard Fixtures.exists(name) else { return }
    let payload = try decodeFixture(PushPayload.self, name)
    let yak = try #require(payload.yak)
    if name == "push/alert-account" { #expect(yak.id == nil) }
}

@Test(
    arguments: [
        "push/live-activity-start", "push/live-activity-start-private", "push/live-activity-update",
        "push/live-activity-end",
    ])
func decodesTheLiveActivityPayloads(name: String) throws {
    guard Fixtures.exists(name) else { return }
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
