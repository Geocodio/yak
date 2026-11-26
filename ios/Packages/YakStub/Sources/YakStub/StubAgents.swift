import Foundation

/// The running agents of a thread and "Stop all", on the core thread: `agents-three`, `agents-two` and `agents-one`
/// start with that many agents running, `agents-viewer` has two running for a person who may not drive the thread
/// (the stop route answers 403), `agents-missing` names a newest card the page does not draw, and `agents-slash` has two running and a list of slash commands, so the bar and the
/// commands menu can be seen together. The stop route mirrors the server: it answers `stopping` with the agents that still
/// run, or `idle` when none does, and every request is counted. A test changes what it answers with `/__stub/agents/`:
/// `mode` (`ok`, `fail`, `slow`, `zero`, `limited`), `release` (lets held answers go), `running` (the number that
/// run now), `newest` (the card fragment the page names) and `log` (one line per stop request).
final class StubAgents: StubFeature, @unchecked Sendable {
    /// The longest a held answer waits for the release, so a test that never releases it cannot hang the stub.
    static let longestHold = Duration.seconds(120)

    let name = "agents"
    let scenarios = ["agents-three", "agents-two", "agents-one", "agents-viewer", "agents-slash", "agents-missing"]

    private struct State {
        var scenario = ""
        var running = 0
        var mode = "ok"
        var newest = "agent-card-a0"
        var isReleased = false
        var log: [String] = []
    }

    private let state = Locked(State())

    func reset() { state.set(State()) }

    func played(scenario: String) {
        let count =
            [
                "agents-three": 3, "agents-two": 2, "agents-one": 1, "agents-viewer": 2, "agents-slash": 2,
                "agents-missing": 2,
            ][scenario] ?? 0
        state.mutate {
            $0.scenario = scenario
            $0.running = count
            if scenario == "agents-missing" { $0.newest = "agent-card-gone" }
        }
    }

    // MARK: Control

    func control(_ request: StubRequest) -> StubReply? {
        let body = String(decoding: request.body, as: UTF8.self).trimmingCharacters(in: .whitespacesAndNewlines)
        switch (request.method, request.path) {
        case ("GET", "/__stub/agents/log"):
            return text(state.get().log.joined(separator: "\n"))
        case ("GET", "/__stub/agents/report"):
            return report()
        case ("POST", "/__stub/agents/mode"):
            guard ["ok", "fail", "slow", "zero", "limited"].contains(body) else { return nil }
            state.mutate { $0.mode = body }
            return text("ok")
        case ("POST", "/__stub/agents/release"):
            state.mutate { $0.isReleased = true }
            return text("ok")
        case ("POST", "/__stub/agents/running"):
            guard let count = Int(body), count >= 0 else { return nil }
            state.mutate { $0.running = count }
            return text("ok")
        case ("POST", "/__stub/agents/newest"):
            state.mutate { $0.newest = body }
            return text("ok")
        default:
            return nil
        }
    }

    /// What the fixture page reports over the bridge: the number that run, the card it names as the newest (which a
    /// test can point at a card that is not on the page) and the cards it draws. 404 outside the agents scenarios.
    private func report() -> StubReply? {
        let current = state.get()
        guard scenarios.contains(current.scenario) else { return nil }
        let cards = (0..<current.running).map { "agent-card-a\($0)" }
        let object: [String: Any] = [
            "running": current.running, "newest": current.running > 0 ? current.newest : NSNull(), "cards": cards,
        ]
        return reply(object)
    }

    // MARK: Routes

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        guard scenarios.contains(context.scenario) else { return nil }
        let parts = route.split(separator: " ", maxSplits: 1).map(String.init)
        guard parts.count == 2 else { return nil }
        let path = parts[1].split(separator: "/").map(String.init)
        guard path.count >= 2, path[0] == "threads" else { return nil }
        switch (parts[0], Array(path.dropFirst(2))) {
        case ("GET", []): return show(context)
        case ("POST", ["agents", "stop"]): return stop(path[1], context)
        default: return nil
        }
    }

    private func agents() -> [String: Any] {
        let current = state.get()
        let isStopping = current.log.count > 0 && current.mode != "fail"
        let rows = (0..<min(current.running, 20)).map { index -> [String: Any] in
            [
                "id": "a\(index)", "name": "Agent \(index + 1)", "state": isStopping ? "stopping" : "running",
                "startedAt": "2026-11-15T12:00:00+00:00",
            ]
        }
        return ["runningCount": current.running, "running": rows]
    }

    private func show(_ context: StubContext) -> StubReply? {
        guard case .reply(200, let contentType, let body) = context.core(),
            var page = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
        else { return nil }
        page["agents"] = agents()
        if context.scenario == "agents-viewer" {
            page["canDrive"] = false
            var composer = page["composer"] as? [String: Any] ?? [:]
            composer["state"] = "readonly"
            page["composer"] = composer
        }
        if context.scenario == "agents-slash" {
            let commands = [
                ("review", "Review the diff", "[focus]", "command"),
                ("compact", "Summarise the conversation", nil, "command"),
                ("write-tests", "Add tests for the change on this branch", nil, "skill"),
            ].map {
                ["name": $0.0, "description": $0.1, "argumentHint": $0.2 ?? NSNull(), "kind": $0.3] as [String: Any]
            }
            page["slashCommands"] = ["commands": commands, "capturedAt": "2026-11-15T12:00:00+00:00"]
        }
        let edited = (try? JSONSerialization.data(withJSONObject: page)) ?? body
        return StubReply(answer: .reply(status: 200, contentType: contentType, body: edited))
    }

    private func stop(_ id: String, _ context: StubContext) -> StubReply? {
        state.mutate { $0.log.append("POST threads/\(id)/agents/stop") }
        if context.scenario == "agents-viewer" {
            return failure(403, "forbidden", "You cannot do this on this thread.")
        }
        let current = state.get()
        switch current.mode {
        case "fail": return failure(500, "server_error", "Something went wrong.")
        case "limited": return failure(429, "rate_limited", "Too many requests.", headers: ["Retry-After": "120"])
        default: break
        }
        let stopped = current.running
        if current.mode == "zero" { state.mutate { $0.running = 0 } }
        let answer = reply([
            "status": stopped == 0 || current.mode == "zero" ? "idle" : "stopping", "stopped": stopped,
            "agents": agents(),
        ])
        return current.mode == "slow" ? held(answer) : answer
    }

    // MARK: Replies

    private func held(_ reply: StubReply) -> StubReply {
        var held = reply
        held.hold = { [state] in
            let deadline = ContinuousClock.now + Self.longestHold
            while !state.get().isReleased, ContinuousClock.now < deadline {
                try? await Task.sleep(for: .milliseconds(100))
            }
        }
        return held
    }

    private func reply(_ object: Any) -> StubReply {
        let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
    }

    private func text(_ string: String) -> StubReply {
        StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(string.utf8)))
    }

    private func failure(_ status: Int, _ code: String, _ message: String, headers: [String: String] = [:])
        -> StubReply
    {
        StubReply(answer: Scenario.error(status, code, message), headers: headers)
    }
}
