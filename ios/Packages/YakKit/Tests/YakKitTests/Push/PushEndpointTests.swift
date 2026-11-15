import Foundation
import Testing

@testable import YakKit

private let tokenText = String(repeating: "0123456789abcdef", count: 4)

private func bodyText<R>(_ endpoint: Endpoint<R>) throws -> String? {
    guard let body = endpoint.body else { return nil }
    let encoder = JSONEncoder()
    encoder.outputFormatting = .sortedKeys
    return String(decoding: try encoder.encode(body), as: UTF8.self)
}

@Test func buildsEveryPushRequest() throws {
    let alert = Endpoint.putPushToken(kind: .alert, token: tokenText, environment: .production)
    #expect(alert.method == .put && alert.path == "push-tokens/alert")
    #expect(try bodyText(alert) == #"{"environment":"production","token":"\#(tokenText)"}"#)

    let start = Endpoint.putPushToken(kind: .liveActivityStart, token: tokenText, environment: .sandbox)
    #expect(start.path == "push-tokens/live_activity_start")

    let removal = Endpoint.deletePushToken(kind: .alert)
    #expect(removal.method == .delete && removal.path == "push-tokens/alert" && removal.body == nil)

    let test = Endpoint.sendTestPush()
    #expect(test.method == .post && test.path == "push-tokens/alert/test" && test.body == nil)
    #expect(!test.retriesTransientFailures || test.method != .get)

    let read = Endpoint.notificationPreferences()
    #expect(read.method == .get && read.path == "notifications" && !read.retriesTransientFailures)

    let update = Endpoint.updateNotificationPreference(.turnFinished, isOn: false)
    #expect(update.method == .put && update.path == "notifications")
    #expect(try bodyText(update) == #"{"events":{"turn_finished":false}}"#)

    let channel = Endpoint.liveActivityChannel(thread: "d0e515ef")
    #expect(channel.method == .post && channel.path == "threads/d0e515ef/live-activity-channel")
    #expect(channel.body == nil)
    #expect(Endpoint.liveActivityChannel(thread: "../x").path == "threads/..%2Fx/live-activity-channel")

    let report = Endpoint.reportLiveActivities(threads: ["d0e515ef", "a1b2c3d4"])
    #expect(report.method == .put && report.path == "live-activities")
    #expect(try bodyText(report) == #"{"threads":["d0e515ef","a1b2c3d4"]}"#)
    #expect(try bodyText(Endpoint.reportLiveActivities(threads: [])) == #"{"threads":[]}"#)
}

@Test func preferencesReadMissingKeysAsOn() throws {
    let preferences = try decoded(NotificationPreferences.self, "scenario-push-preferences")
    #expect(preferences.isOn(.needsYou) && !preferences.isOn(.stackUsage))
    #expect(NotificationPreferences(events: [:]).isOn(.pullRequest))
    #expect(PushEvent.switches.map(\.rawValue) == ["needs_you", "turn_finished", "pull_request", "stack_usage"])
    #expect(!PushEvent.switches.contains(.account) && !PushEvent.switches.contains(.test))
    let updated = try decoded(NotificationPreferences.self, "scenario-push-preferences-updated")
    #expect(!updated.isOn(.turnFinished))
    #expect(try decoded(PushTestResult.self, "scenario-push-test").status == "sent")
    #expect(try decoded(LiveActivityChannel.self, "scenario-push-channel").channel == "c2NlbmFyaW8tY2hhbm5lbA")
}

private func serverFailure(_ status: Int, _ code: String, _ details: [String: String]) -> APIFailure {
    .server(
        APIError(status: status, code: code, message: "m", details: details.mapValues { JSONValue.string($0) }))
}

@Test(
    arguments: [
        (
            409, "push_environment_mismatch", ["expected": "production", "given": "sandbox"],
            PushProblem.environmentMismatch(expected: "production", given: "sandbox")
        ),
        (409, "push_environment_mismatch", [:], .environmentMismatch(expected: "", given: "")),
        (409, "push_rejected", ["reason": "BadDeviceToken"], .rejected(reason: "BadDeviceToken", message: "m")),
        (409, "push_rejected", [:], .rejected(reason: "", message: "m")),
        (409, "push_unavailable", [:], .pushUnavailable), (409, "no_push_token", [:], .noPushToken),
        (409, "thread_closed", [:], .threadClosed), (404, "not_found", [:], .notFound),
        (422, "validation_failed", [:], .invalid(message: "m")), (503, "unavailable", [:], .unavailable),
        (500, "server_error", [:], .unavailable), (409, "something_new", [:], .other(message: "m")),
        (403, "forbidden", [:], .other(message: "m")),
    ])
func mapsEveryServerRefusal(status: Int, code: String, details: [String: String], expected: PushProblem) {
    #expect(PushProblem(serverFailure(status, code, details)) == expected)
}

@Test func mapsTransportFailures() {
    #expect(PushProblem(.network(.notConnectedToInternet)) == .offline)
    #expect(PushProblem(.network(.timedOut)) == .unavailable)
    #expect(PushProblem(.unauthorized(code: "invalid_token")) == .needsPairing)
    #expect(PushProblem(.appTooOld(minimum: 2)) == .appTooOld)
    #expect(PushProblem(.serverTooOld(current: 0)) == .serverTooOld)
    #expect(PushProblem(.notYak) == .other(message: ""))
    #expect(PushProblem(.encodingFailed) == .other(message: ""))
    let limited = APIError(status: 429, code: "rate_limited", message: "m", details: [:], retryAfterSeconds: 30)
    #expect(PushProblem(.server(limited)) == .rateLimited(retryAfterSeconds: 30))
    let unhinted = APIError(status: 429, code: "rate_limited", message: "m", details: [:])
    #expect(PushProblem(.server(unhinted)) == .rateLimited(retryAfterSeconds: nil))
}

@Test func threadShowWithoutAChannelDecodesNil() throws {
    #expect(try decoded(ThreadShow.self, "scenario-thread-show").liveActivityChannel == nil)
    let data = try Scenarios.data(
        "scenario-thread-show", setting: ["liveActivityChannel"], toJSON: "\"c2NlbmFyaW8tY2hhbm5lbA\"")
    #expect(try JSONDecoder().decode(ThreadShow.self, from: data).liveActivityChannel == "c2NlbmFyaW8tY2hhbm5lbA")
}
