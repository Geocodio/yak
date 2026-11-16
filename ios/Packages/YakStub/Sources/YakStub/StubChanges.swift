import Foundation

/// The tasks and observations routes. Every scenario answers them; the scenarios below change one thing each.
/// Decisions are remembered until the next scenario, so a walkthrough sees the task after the person decided.
final class StubChanges: StubFeature, @unchecked Sendable {
    let name = "changes"
    let scenarios = [
        "changes", "tasks-empty", "tasks-nulls", "tasks-slow", "task-slow-approve", "task-refused", "task-not-linked",
        "task-access-error", "task-early-warning", "task-early-warning-tagged", "observations-declined",
    ]
    let fixturePairs = [
        StubFixturePair(server: "tasks", scenario: "scenario-tasks"),
        StubFixturePair(server: "tasks-empty", scenario: "scenario-tasks-empty"),
        StubFixturePair(server: "task", scenario: "scenario-task"),
        StubFixturePair(server: "task-question", scenario: "scenario-task-question"),
        StubFixturePair(server: "task-read-only", scenario: "scenario-task-read-only"),
        StubFixturePair(server: "task-co-owner-candidates", scenario: "scenario-task-co-owner-candidates"),
        StubFixturePair(server: "observations", scenario: "scenario-observations"),
        StubFixturePair(server: "observations-empty", scenario: "scenario-observations-empty"),
    ]

    private struct State {
        var decision: (status: String, by: String)?
        var tagged: String?
        var declinedEarlyWarning = false
        var answeredQuestions = false
        var lastBody = ""
    }
    private let state = Locked(State())

    func reset() { state.set(State()) }

    func control(_ request: StubRequest) -> StubReply? {
        text(state.get().lastBody)
    }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        let scenario = context.scenario
        let parts = route.split(separator: " ", maxSplits: 1).map(String.init)
        guard parts.count == 2 else { return nil }
        let path = parts[1].split(separator: "?", maxSplits: 1).first.map(String.init) ?? parts[1]
        let segments = path.split(separator: "/").map(String.init)
        guard let first = segments.first, first == "tasks" || first == "observations" else { return nil }
        var reply: StubReply?
        if first == "observations", parts[0] == "GET" {
            reply = observations(request, context: context)
        } else if segments == ["tasks"], parts[0] == "GET" {
            reply = list(request, context: context)
        } else if segments.count == 2, parts[0] == "GET" {
            reply = detail(segments[1], context: context)
        } else if segments.count >= 3, parts[0] == "POST" {
            reply = action(Array(segments.dropFirst()), request: request, context: context)
        } else if segments.count == 4, segments[2] == "co-owner", segments[3] == "candidates" {
            reply = json(context.fixtures.scenario("scenario-task-co-owner-candidates"))
        }
        if scenario == "tasks-slow", var slowed = reply {
            slowed.delay = .seconds(14)
            return slowed
        }
        return reply
    }

    // MARK: Lists

    private func list(_ request: StubRequest, context: StubContext) -> StubReply {
        let scenario = context.scenario
        if scenario == "tasks-empty" { return json(context.fixtures.scenario("scenario-tasks-empty")) }
        var body = context.fixtures.scenario("scenario-tasks")
        let tab = request.query["tab"] ?? "tasks"
        let page = Int(request.query["page"] ?? "1") ?? 1
        var rows = body["tasks"] as? [[String: Any]] ?? []
        if tab != "tasks" {
            rows = []
        } else if page >= 2 {
            rows = rows.suffix(1).map {
                var row = $0
                row["id"] = "t7u3x9yc"
                row["description"] = "Archive old exports"
                return row
            }
        }
        if scenario == "tasks-nulls" {
            rows = rows.map { row in
                var row = row
                if var pr = row["pr"] as? [String: Any] {
                    pr["url"] = NSNull()
                    row["pr"] = pr
                }
                return row
            }
        }
        if let repo = request.query["repo"] { rows = rows.filter { ($0["repo"] as? String) == repo } }
        if let status = request.query["status"] { rows = rows.filter { ($0["status"] as? String) == status } }
        body["tasks"] = rows
        body["nextPage"] = (tab == "tasks" && page == 1 && request.query["repo"] == nil) ? 2 : NSNull()
        return json(body)
    }

    private func observations(_ request: StubRequest, context: StubContext) -> StubReply {
        if context.scenario == "tasks-empty" { return json(context.fixtures.scenario("scenario-observations-empty")) }
        var body = context.fixtures.scenario("scenario-observations")
        var rows = body["observations"] as? [[String: Any]] ?? []
        if let outcome = request.query["outcome"] { rows = rows.filter { ($0["outcome"] as? String) == outcome } }
        if let repo = request.query["repo"] { rows = rows.filter { ($0["repo"] as? String) == repo } }
        body["observations"] = rows
        return json(body)
    }

    // MARK: Detail

    private func detail(_ id: String, context: StubContext) -> StubReply {
        var body: [String: Any]
        switch id {
        case "k3m9p2qa": body = pending(context)
        case "q5w8e2rt":
            body = context.fixtures.scenario("scenario-task-question")
            if state.get().answeredQuestions { body["questions"] = NSNull() }
        case "r7t3w9yb", "r3d0n1yx": body = context.fixtures.scenario("scenario-task-read-only")
        case "m4n8r2tw", "p6s2v8xa", "t7u3x9yc":
            body = context.fixtures.scenario("scenario-task")
            var task = body["task"] as? [String: Any] ?? [:]
            task["id"] = id
            task["status"] = id == "p6s2v8xa" ? "failed" : "running"
            task["statusLabel"] = id == "p6s2v8xa" ? "failed" : "running"
            task["pr"] = NSNull()
            body["task"] = task
            body["coOwner"] = NSNull()
            body["composer"] = ["state": "idle"]
        default:
            return StubReply(answer: Scenario.error(404, "not_found", "This task does not exist."))
        }
        return json(body)
    }

    /// The co-owner task with the decision the person made, and the scenario's variation of the viewer.
    private func pending(_ context: StubContext) -> [String: Any] {
        var body = context.fixtures.scenario("scenario-task")
        let current = state.get()
        var panel = body["coOwner"] as? [String: Any] ?? [:]
        var viewer = panel["viewer"] as? [String: Any] ?? [:]
        switch context.scenario {
        case "task-not-linked": viewer["isLinked"] = false
        case "task-access-error": viewer["access"] = "error"
        default: break
        }
        panel["viewer"] = viewer
        if let decision = current.decision {
            panel["status"] = decision.status
            panel["decidedBy"] = decision.by
        }
        body["coOwner"] = panel
        if context.scenario.hasPrefix("task-early-warning") {
            body["coOwner"] = NSNull()
            let tagged = current.tagged ?? (context.scenario == "task-early-warning-tagged" ? "Second person" : nil)
            var task = body["task"] as? [String: Any] ?? [:]
            task["status"] = "running"
            task["statusLabel"] = "running"
            body["task"] = task
            if current.declinedEarlyWarning {
                body["earlyWarning"] = NSNull()
            } else {
                body["earlyWarning"] = [
                    "state": tagged == nil ? "shown" : "tagged", "canPick": tagged == nil,
                    "taggedName": tagged as Any? ?? NSNull(), "fallbackName": "Third person", "repoName": "example/api",
                ]
            }
        }
        return body
    }

    // MARK: Actions

    private func action(_ segments: [String], request: StubRequest, context: StubContext) -> StubReply? {
        let verb = segments.dropFirst().joined(separator: "/")
        state.mutate { $0.lastBody = String(decoding: request.body, as: UTF8.self) }
        switch verb {
        case "co-owner/approve":
            var reply = status("approved")
            if context.scenario == "task-refused" {
                return StubReply(
                    answer: Scenario.error(409, "already_resolved", "This request was already decided."))
            }
            if context.scenario == "task-slow-approve" { reply.delay = .seconds(20) }
            state.mutate { $0.decision = ("approved", "Test person") }
            return reply
        case "co-owner/request-changes":
            if context.scenario == "task-refused" {
                return StubReply(
                    answer: Scenario.error(
                        422, "validation_failed", "The feedback is too long.",
                        details: ["errors": ["feedback": ["The feedback is too long."]]]))
            }
            state.mutate { $0.decision = ("changes_requested", "Test person") }
            return status("changes_requested")
        case "co-owner/reject":
            state.mutate { $0.decision = ("rejected", "Test person") }
            return status("rejected")
        case "co-owner/withdraw":
            state.mutate { $0.decision = ("withdrawn", "Test person") }
            return status("withdrawn")
        case "co-owner/tag":
            state.mutate { $0.tagged = "Second person" }
            return status("tagged")
        case "co-owner/decline":
            state.mutate { $0.declinedEarlyWarning = true }
            return status("declined")
        case "retry", "cancel", "rerun-review", "retry-render", "reroute": return status("queued")
        case "clarification-answers":
            state.mutate { $0.answeredQuestions = true }
            return status("sent")
        default: return nil
        }
    }

    // MARK: Replies

    private func status(_ word: String) -> StubReply { json(["status": word]) }

    private func text(_ body: String) -> StubReply {
        StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(body.utf8)))
    }

    private func json(_ object: [String: Any]) -> StubReply {
        let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
    }
}
