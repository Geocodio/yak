import Foundation

private struct PushTokenBody: Encodable, Sendable {
    let token: String
    let environment: PushEnvironment
}

private struct PreferenceBody: Encodable, Sendable {
    let events: [String: Bool]
}

private struct LiveActivitiesBody: Encodable, Sendable {
    let threads: [String]
}

extension Endpoint where Response == EmptyResponse {
    public static func putPushToken(kind: PushTokenKind, token: String, environment: PushEnvironment)
        -> Endpoint<EmptyResponse>
    {
        Endpoint(
            method: .put, path: "push-tokens/\(kind.rawValue)",
            body: PushTokenBody(token: token, environment: environment))
    }

    public static func deletePushToken(kind: PushTokenKind) -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "push-tokens/\(kind.rawValue)")
    }

    /// Replaces the set of threads this phone shows a Live Activity for.
    public static func reportLiveActivities(threads: [String]) -> Endpoint<EmptyResponse> {
        Endpoint(method: .put, path: "live-activities", body: LiveActivitiesBody(threads: threads))
    }
}

extension Endpoint where Response == PushTestResult {
    /// Sent once: a repeat would show two test notifications.
    public static func sendTestPush() -> Endpoint<PushTestResult> {
        Endpoint(method: .post, path: "push-tokens/alert/test")
    }
}

extension Endpoint where Response == NotificationPreferences {
    public static func notificationPreferences() -> Endpoint<NotificationPreferences> {
        Endpoint(method: .get, path: "notifications")
    }

    /// Sets one switch; the answer is the full set.
    public static func updateNotificationPreference(_ event: PushEvent, isOn: Bool)
        -> Endpoint<NotificationPreferences>
    {
        Endpoint(method: .put, path: "notifications", body: PreferenceBody(events: [event.rawValue: isOn]))
    }
}

extension Endpoint where Response == LiveActivityChannel {
    /// The server answers within 10 seconds plus connect, inside the 15 second request timeout.
    public static func liveActivityChannel(thread id: String) -> Endpoint<LiveActivityChannel> {
        Endpoint(method: .post, path: "threads/\(Endpoint.segment(id))/live-activity-channel")
    }
}
