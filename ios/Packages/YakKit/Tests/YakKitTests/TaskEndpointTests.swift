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
    @Test func observationQueriesFollowTheContract() {
        var query = ObservationQuery()
        query.repo = "example/web"
        query.outcome = .declined
        let endpoint = Endpoint.observations(query, page: 3)
        #expect(endpoint.path == "observations" && endpoint.query.map(\.name) == ["repo", "outcome", "page"])
        #expect(Endpoint.observations(ObservationQuery(), page: 1).query.map(\.name) == ["page"])
    }

    @Test func scenariosDecode() throws {
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
        var data = try edited("scenario-observations") { object in
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
