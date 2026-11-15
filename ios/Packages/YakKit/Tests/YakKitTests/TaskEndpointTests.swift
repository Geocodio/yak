import Foundation
import Testing

@testable import YakKit

private func jsonBody<R>(_ endpoint: Endpoint<R>) throws -> NSDictionary {
    let body = try #require(endpoint.body)
    let data = try JSONEncoder().encode(body)
    return try #require(try JSONSerialization.jsonObject(with: data) as? NSDictionary)
}

/// A scenario with one edit applied through `edit`, which gets the top-level JSON object.
private func edited(_ name: String, _ edit: (inout [String: Any]) -> Void) throws -> Data {
    var object = try #require(try JSONSerialization.jsonObject(with: Scenarios.data(name)) as? [String: Any])
    edit(&object)
    return try JSONSerialization.data(withJSONObject: object)
}

@Suite struct TaskEndpointTests {
    @Test func listQueriesFollowTheContract() {
        var query = TaskListQuery()
        query.tab = .reviews
        query.source = "slack"
        query.repo = "  example/api  "
        let endpoint = Endpoint.tasks(query, page: 1200)
        #expect(endpoint.query.map(\.name) == ["tab", "repo", "page"])
        #expect(endpoint.query.first(where: { $0.name == "repo" })?.value == "example/api")
        #expect(endpoint.query.last?.value == "1000")
        #expect(Endpoint.tasks(TaskListQuery(), page: 0).query.last?.value == "1")
    }

    @Test func listQueryItemsKeepTheirOrderAndLimits() {
        var query = TaskListQuery()
        query.status = .failed
        query.source = "slack"
        query.pullRequest = "open"
        query.search = " " + String(repeating: "a", count: 150) + " "
        let endpoint = Endpoint.tasks(query, page: 2)
        #expect(endpoint.query.map(\.name) == ["tab", "status", "source", "pr", "search", "page"])
        #expect(endpoint.query[4].value?.count == 100)
        query.search = "   "
        #expect(!Endpoint.tasks(query, page: 1).query.contains { $0.name == "search" })
    }

    @Test func observationQueriesFollowTheContract() {
        var query = ObservationQuery()
        query.repo = "example/web"
        query.outcome = .declined
        let endpoint = Endpoint.observations(query, page: 3)
        #expect(endpoint.path == "observations" && endpoint.query.map(\.name) == ["repo", "outcome", "page"])
        #expect(Endpoint.observations(ObservationQuery(), page: 1).query.map(\.name) == ["page"])
    }

    @Test func actionPathsAndBodies() throws {
        let approve = Endpoint.taskAction("k3m9p2qa", .approve)
        #expect(approve.method == .post && approve.path == "tasks/k3m9p2qa/co-owner/approve")
        #expect(approve.timeout == .seconds(60) && approve.body == nil)
        let changes = Endpoint.taskAction("k3m9p2qa", .requestChanges(feedback: " x "))
        #expect(changes.path == "tasks/k3m9p2qa/co-owner/request-changes")
        #expect(try jsonBody(changes) == ["feedback": "x"])
        let tag = Endpoint.taskAction("k3m9p2qa", .tag(userID: 12))
        let tagBody = try jsonBody(tag)
        #expect(tag.path == "tasks/k3m9p2qa/co-owner/tag" && tagBody == ["user_id": 12])
        let reroute = Endpoint.taskAction("k3m9p2qa", .reroute(repo: "example/web"))
        let rerouteBody = try jsonBody(reroute)
        #expect(reroute.path == "tasks/k3m9p2qa/reroute" && rerouteBody == ["repo": "example/web"])
        let retry = Endpoint.taskAction("k3m9p2qa", .retry)
        #expect(retry.path == "tasks/k3m9p2qa/retry" && retry.body == nil && retry.timeout == .seconds(15))
        let paths: [(TaskAction, String)] = [
            (.cancel, "cancel"), (.rerunReview, "rerun-review"), (.retryRender, "retry-render"),
            (.reject, "co-owner/reject"), (.withdraw, "co-owner/withdraw"), (.decline, "co-owner/decline"),
        ]
        for (action, suffix) in paths {
            #expect(Endpoint.taskAction("k3m9p2qa", action).path == "tasks/k3m9p2qa/\(suffix)")
        }
        #expect(Endpoint.taskAction("../x", .retry).path == "tasks/..%2Fx/retry")
    }

    @Test func feedbackIsCutAtTheLimit() throws {
        let long = String(repeating: "a", count: 6000)
        let body = try jsonBody(Endpoint.taskAction("k3m9p2qa", .requestChanges(feedback: long)))
        #expect((body["feedback"] as? String)?.count == 5000)
    }

    @Test func answersAlwaysCarryTheNote() throws {
        let endpoint = Endpoint.answerTaskQuestions(
            "q5w8e2rt", answers: ["scope": QuestionAnswer(choices: ["All exports"])], note: nil)
        #expect(endpoint.path == "tasks/q5w8e2rt/clarification-answers")
        let body = try jsonBody(endpoint)
        #expect(body["note"] is NSNull && body["answers"] != nil)
        #expect(Endpoint.coOwnerCandidates("k3m9p2qa").path == "tasks/k3m9p2qa/co-owner/candidates")
        #expect(Endpoint.task("k3m9p2qa").path == "tasks/k3m9p2qa")
    }

    private func failure(
        _ status: Int, _ code: String, _ message: String = "", _ details: [String: JSONValue] = [:]
    ) -> APIFailure {
        .server(APIError(status: status, code: code, message: message, details: details))
    }

    @Test func outcomesMapByCode() {
        let connect = TaskActionOutcome(
            failure(
                409, "not_linked", "Link GitHub first.",
                ["connectUrl": .string("https://yak.example.com/settings/github")]))
        #expect(
            connect
                == .refused(
                    code: "not_linked", message: "Link GitHub first.", fieldErrors: [:],
                    connectURL: "https://yak.example.com/settings/github"))
        #expect(
            TaskActionOutcome(failure(503, "github_failed", "GitHub did not answer."))
                == .refused(code: "github_failed", message: "GitHub did not answer.", fieldErrors: [:], connectURL: nil)
        )
        #expect(TaskActionOutcome(failure(503, "unavailable")) == .failed(.offline))
        #expect(TaskActionOutcome(.network(.timedOut)) == .failed(.offline))
        let invalid = TaskActionOutcome(
            failure(
                422, "validation_failed", "Invalid.", ["errors": .object(["feedback": .array([.string("Required.")])])])
        )
        guard case .refused(_, _, let fieldErrors, _) = invalid else {
            Issue.record("expected a refusal")
            return
        }
        #expect(fieldErrors["feedback"] == ["Required."])
        #expect(TaskActionOutcome(failure(500, "unknown")) == .failed(.server(code: "unknown", message: "")))
    }

    @Test func scenariosDecode() throws {
        let page = try decoded(TaskListPage.self, "scenario-tasks")
        #expect(page.tasks.map(\.id) == ["k3m9p2qa", "m4n8r2tw", "p6s2v8xa"])
        #expect(page.nextPage == 2 && page.counts.needsCoOwner == 1)
        #expect(page.tasks[1].followUps.map(\.id) == ["n5p9s3vx"] && page.tasks[0].askedFirstName == "Second")
        #expect(try decoded(TaskListPage.self, "scenario-tasks-empty").tasks.isEmpty)
        let task = try decoded(AppTask.self, "scenario-task")
        #expect(task.threadId == "d0e515ef" && task.canDrive && task.coOwner?.status == "pending")
        #expect(task.coOwner?.viewer.access == "write" && task.progress?.steps.count == 2)
        #expect(task.questionRound == nil)
        let question = try decoded(AppTask.self, "scenario-task-question")
        #expect(question.questionRound?.questions.count == 2 && question.threadId == nil)
        #expect(question.questionRound?.taskId == LenientID("q5w8e2rt"))
        let observations = try decoded(ObservationPage.self, "scenario-observations")
        #expect(observations.observations.map(\.taskId) == ["k7m2q9rt", nil])
        #expect(try decoded(ObservationPage.self, "scenario-observations-empty").observations.isEmpty)
        let candidates = try decoded(CoOwnerCandidates.self, "scenario-task-co-owner-candidates")
        #expect(candidates.candidates.map(\.id) == [12, 13] && candidates.isComplete)
    }

    @Test func candidatesDefaultToComplete() throws {
        let data = Data(#"{"candidates": []}"#.utf8)
        #expect(try JSONDecoder().decode(CoOwnerCandidates.self, from: data).isComplete)
    }

    @Test func nullableFieldsNeverFailAPage() throws {
        var data = try edited("scenario-tasks") { object in
            var tasks = object["tasks"] as? [[String: Any]] ?? []
            var pr = tasks[0]["pr"] as? [String: Any] ?? [:]
            pr["url"] = NSNull()
            tasks[0]["pr"] = pr
            tasks[1]["createdAt"] = NSNull()
            object["tasks"] = tasks
        }
        let page = try JSONDecoder().decode(TaskListPage.self, from: data)
        let original = try decoded(TaskListPage.self, "scenario-tasks")
        #expect(page.tasks[0].pr?.url == nil && page.tasks[0].pr?.number == 41)
        #expect(page.tasks[1].createdAt == nil && page.tasks[2] == original.tasks[2])

        data = try edited("scenario-observations") { object in
            var rows = object["observations"] as? [[String: Any]] ?? []
            rows[0]["repo"] = NSNull()
            object["observations"] = rows
        }
        let observations = try JSONDecoder().decode(ObservationPage.self, from: data)
        #expect(observations.observations[0].repo == nil)
        #expect(ObservationRowText.subtitle(observations.observations[0]).repo == nil)
        #expect(ObservationRowText.subtitle(observations.observations[1]).repo == "example/web")

        data = try edited("scenario-task") { object in
            var task = object["task"] as? [String: Any] ?? [:]
            var pr = task["pr"] as? [String: Any] ?? [:]
            pr["url"] = NSNull()
            task["pr"] = pr
            object["task"] = task
        }
        #expect(try JSONDecoder().decode(AppTask.self, from: data).task.pr?.url == nil)
    }

    @Test func theEarlyWarningDecodesItsNotice() throws {
        let shown = try edited("scenario-task") { object in
            object["coOwner"] = NSNull()
            object["earlyWarning"] = [
                "state": "shown", "canPick": true, "taggedName": NSNull(), "fallbackName": "Second person",
                "repoName": "example/api", "areas": [["name": "Billing", "risk": "high"]],
            ]
        }
        let notice = try #require(try JSONDecoder().decode(AppTask.self, from: shown).earlyWarning)
        #expect(notice.state == "shown" && notice.canPick && notice.fallbackName == "Second person")
        let tagged = try edited("scenario-task") { object in
            object["coOwner"] = NSNull()
            object["earlyWarning"] = [
                "state": "tagged", "canPick": false, "taggedName": "Second person", "fallbackName": NSNull(),
                "repoName": "example/api", "areas": [],
            ]
        }
        let taggedNotice = try #require(try JSONDecoder().decode(AppTask.self, from: tagged).earlyWarning)
        #expect(taggedNotice.taggedName == "Second person" && !taggedNotice.canPick)
    }
}
