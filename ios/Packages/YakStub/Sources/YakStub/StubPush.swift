import Foundation

/// Push tokens, notification preferences, the test push, Live Activity channels and the reported activity set.
/// It stores what the app sends and replays scenario data; it decides nothing about Yak beyond the tables here.
/// It logs route names only, never a token or a body.
final class StubPush: StubFeature, @unchecked Sendable {
    let name = "push"

    let scenarios = [
        "push-unavailable", "push-other-build", "push-other-environment", "push-registration-failing",
        "push-test-rejected", "notifications-failing",
    ]
    let fixturePairs = [
        StubFixturePair(server: "notifications", scenario: "scenario-push-preferences"),
        StubFixturePair(server: "push-test", scenario: "scenario-push-test"),
        StubFixturePair(server: "thread-live-activity-channel", scenario: "scenario-push-channel"),
    ]

    private static let tokenKinds: Set<String> = ["alert", "live_activity_start"]
    private static let knownEvents: Set<String> = ["needs_you", "turn_finished", "stack_usage", "pull_request"]
    private static let closedThreadID = "c10sed00"

    private struct State {
        var tokens: [String: [String: String]] = [:]
        var activities: [String] = []
        var preferences: [String: Bool] = [:]
    }

    private let state = Locked(State())

    func reset() { state.set(State()) }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        let parts = route.split(separator: " ", maxSplits: 1).map(String.init)
        guard parts.count == 2 else { return nil }
        let method = parts[0]
        let path = parts[1].split(separator: "/").map(String.init)
        let scenario = context.scenario

        switch (method, path) {
        case ("GET", ["me"]):
            return mePush(scenario: scenario, context: context)
        case ("PUT", _) where path.count == 2 && path[0] == "push-tokens",
            ("DELETE", _) where path.count == 2 && path[0] == "push-tokens":
            return tokenReply(method: method, kind: path[1], request: request, scenario: scenario)
        case ("POST", ["push-tokens", "alert", "test"]):
            return testReply(scenario: scenario, context: context)
        case ("GET", ["notifications"]):
            return reply(preferences(context))
        case ("PUT", ["notifications"]):
            return updatePreferences(request: request, scenario: scenario, context: context)
        case ("POST", _) where path.count == 3 && path[0] == "threads" && path[2] == "live-activity-channel":
            return channelReply(threadID: path[1], scenario: scenario, context: context)
        case ("PUT", ["live-activities"]):
            return activitiesReply(request: request, context: context)
        default:
            return nil
        }
    }

    func control(_ request: StubRequest) -> StubReply? {
        guard request.path == "/__stub/push", request.method == "GET" else { return nil }
        let snapshot = state.get()
        let body: [String: Any] = [
            "tokens": snapshot.tokens, "activities": snapshot.activities, "preferences": snapshot.preferences,
        ]
        let data = (try? JSONSerialization.data(withJSONObject: body, options: [.sortedKeys])) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
    }

    // MARK: Routes

    private func mePush(scenario: String, context: StubContext) -> StubReply {
        var answer = context.core()
        guard case .reply(200, let contentType, let body) = answer,
            var me = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
        else { return StubReply(answer: answer) }
        var push = me["push"] as? [String: Any] ?? [:]
        switch scenario {
        case "push-unavailable":
            push = ["apns": false, "bundleId": NSNull(), "environment": NSNull()]
        case "push-other-build":
            push["bundleId"] = "com.example.other"
        case "push-other-environment":
            push["environment"] = "sandbox"
        default:
            return StubReply(answer: answer)
        }
        me["push"] = push
        let edited = (try? JSONSerialization.data(withJSONObject: me)) ?? body
        answer = .reply(status: 200, contentType: contentType, body: edited)
        return StubReply(answer: answer)
    }

    private func tokenReply(method: String, kind: String, request: StubRequest, scenario: String) -> StubReply {
        guard Self.tokenKinds.contains(kind) else { return failure(404, "not_found", "Not found.") }
        if method == "DELETE" {
            state.mutate { _ = $0.tokens.removeValue(forKey: kind) }
            return noContent
        }
        if scenario == "push-registration-failing" {
            return failure(503, "unavailable", "Yak is busy. Try again in a moment.")
        }
        let body = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
        let token = body["token"] as? String ?? ""
        let isHex = token.allSatisfy { $0.isHexDigit }
        guard (64...200).contains(token.count), isHex else {
            return failure(
                422, "validation_failed", "The token is not valid.",
                details: ["errors": ["token": ["The token must be 64 to 200 hexadecimal characters."]]])
        }
        let environment = body["environment"] as? String ?? "production"
        state.mutate { $0.tokens[kind] = ["token": token, "environment": environment] }
        return noContent
    }

    private func testReply(scenario: String, context: StubContext) -> StubReply {
        if scenario == "push-unavailable" {
            return failure(409, "push_unavailable", "This Yak cannot send notifications.")
        }
        guard state.get().tokens["alert"] != nil else {
            return failure(409, "no_push_token", "This device has no notification token yet.")
        }
        if scenario == "push-test-rejected" {
            return failure(
                409, "push_rejected",
                "Apple did not accept this device's notification token. Turn notifications off and on for Yak in iOS Settings, then try again.",
                details: ["reason": "BadDeviceToken"])
        }
        return reply(context.serverFirst("scenario-push-test"))
    }

    private func preferences(_ context: StubContext) -> [String: Any] {
        var answer = context.serverFirst("scenario-push-preferences")
        var events = answer["events"] as? [String: Any] ?? [:]
        for (event, isOn) in state.get().preferences { events[event] = isOn }
        answer["events"] = events
        return answer
    }

    private func updatePreferences(request: StubRequest, scenario: String, context: StubContext) -> StubReply {
        if scenario == "notifications-failing" {
            return failure(503, "unavailable", "Yak could not save this right now. Try again.")
        }
        let body = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
        let changes = (body["events"] as? [String: Any] ?? [:]).compactMapValues { $0 as? Bool }
            .filter { Self.knownEvents.contains($0.key) }
        guard !changes.isEmpty else {
            return failure(
                422, "validation_failed", "Choose at least one notification to change.",
                details: ["errors": ["events": ["The events field is required."]]])
        }
        state.mutate { $0.preferences.merge(changes) { _, new in new } }
        return reply(preferences(context))
    }

    private func channelReply(threadID: String, scenario: String, context: StubContext) -> StubReply {
        if scenario == "push-unavailable" {
            return failure(409, "push_unavailable", "This Yak cannot send notifications.")
        }
        if threadID == Self.closedThreadID { return failure(409, "thread_closed", "This thread is closed.") }
        guard threadIDs(context).contains(threadID) else {
            return failure(404, "not_found", "This thread does not exist.")
        }
        return reply(context.serverFirst("scenario-push-channel"))
    }

    private func activitiesReply(request: StubRequest, context: StubContext) -> StubReply {
        let body = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
        let requested = body["threads"] as? [String] ?? []
        let known = threadIDs(context)
        state.mutate { $0.activities = requested.filter { known.contains($0) } }
        return noContent
    }

    // MARK: Helpers

    private func threadIDs(_ context: StubContext) -> Set<String> {
        var ids: Set<String> = []
        func collect(_ value: Any) {
            if let dictionary = value as? [String: Any] {
                if let id = dictionary["id"] as? String { ids.insert(id) }
                dictionary.values.forEach(collect)
            } else if let array = value as? [Any] {
                array.forEach(collect)
            }
        }
        collect(context.fixtures.scenario("scenario-thread-list", baseURL: context.baseURL))
        return ids
    }

    private var noContent: StubReply {
        StubReply(answer: .reply(status: 204, contentType: "application/json", body: Data()))
    }

    private func reply(_ object: [String: Any]) -> StubReply {
        let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
    }

    private func failure(_ status: Int, _ code: String, _ message: String, details: [String: Any] = [:])
        -> StubReply
    {
        StubReply(answer: Scenario.error(status, code, message, details: details))
    }
}

extension StubContext {
    /// The server fixture for `scenario` when the run plays server fixtures, the scenario data otherwise.
    fileprivate func serverFirst(_ scenario: String) -> [String: Any] {
        guard self.scenario == "server-fixtures" else { return fixtures.scenario(scenario, baseURL: baseURL) }
        return fixtures.serverFirst(scenario, baseURL: baseURL)
    }
}
