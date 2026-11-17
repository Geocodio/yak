import Foundation

/// The widget push token routes and the widget thread lists. It stores what the app sends and replays scenario
/// data. It logs nothing and never echoes a token.
final class StubWidgets: StubFeature, @unchecked Sendable {
    let name = "widgets"

    let scenarios = ["widgets-busy", "widgets-empty", "widgets-old-server", "widgets-other-environment"]
    let scenarioDataNames = ["scenario-widgets-thread-list-busy", "scenario-widgets-thread-list-empty"]

    private struct State {
        var tokens: [[String: String]] = []
        var deletes = 0
    }

    private let state = Locked(State())

    func reset() { state.set(State()) }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        let parts = route.split(separator: " ", maxSplits: 1).map(String.init)
        guard parts.count == 2 else { return nil }
        let path = parts[1].split(separator: "/").map(String.init)
        if parts[0] == "GET", path == ["threads"] {
            switch context.scenario {
            case "widgets-busy": return json(context.fixtures.scenario("scenario-widgets-thread-list-busy", baseURL: context.baseURL))
            case "widgets-empty": return json(context.fixtures.scenario("scenario-widgets-thread-list-empty", baseURL: context.baseURL))
            default: return nil
            }
        }
        guard path == ["push-tokens", "widget"] else { return nil }
        if parts[0] == "DELETE" {
            state.mutate { $0.deletes += 1 }
            return noContent
        }
        guard parts[0] == "PUT" else { return nil }
        switch context.scenario {
        case "widgets-old-server":
            return failure(404, "not_found", "Not found.")
        case "widgets-other-environment":
            return failure(
                409, "push_environment_mismatch", "This Yak sends notifications for another build of the app.")
        default:
            break
        }
        let body = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
        let token = body["token"] as? String ?? ""
        guard (64...200).contains(token.count), token.allSatisfy({ $0.isHexDigit }) else {
            return failure(
                422, "validation_failed", "The token is not valid.",
                details: ["errors": ["token": ["The token must be 64 to 200 hexadecimal characters."]]])
        }
        let environment = body["environment"] as? String ?? "production"
        state.mutate { $0.tokens.append(["token": token, "environment": environment]) }
        return noContent
    }

    func control(_ request: StubRequest) -> StubReply? {
        guard request.path == "/__stub/widgets", request.method == "GET" else { return nil }
        let snapshot = state.get()
        let body: [String: Any] = ["tokens": snapshot.tokens, "deletes": snapshot.deletes]
        let data = (try? JSONSerialization.data(withJSONObject: body, options: [.sortedKeys])) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
    }

    private var noContent: StubReply {
        StubReply(answer: .reply(status: 204, contentType: "application/json", body: Data()))
    }

    private func json(_ object: [String: Any]) -> StubReply {
        let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
    }

    private func failure(_ status: Int, _ code: String, _ message: String, details: [String: Any] = [:])
        -> StubReply
    {
        StubReply(answer: Scenario.error(status, code, message, details: details))
    }
}
