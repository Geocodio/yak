import Foundation
import Testing

@testable import YakKit

@Suite struct TaskMenuTests {
    @Test func aReadOnlyTaskHasNoMenu() throws {
        let task = try decoded(AppTask.self, "scenario-task-read-only")
        #expect(task.actions.canRetry && !task.canDrive)
        #expect(TaskMenu.items(task).isEmpty)
    }

    @Test func theMenuListsOnlyTrueActionsCancelLast() throws {
        let data = try Scenarios.data("scenario-task")
        var object = try #require(try JSONSerialization.jsonObject(with: data) as? [String: Any])
        object["actions"] = [
            "canRetry": true, "canCancel": true, "canRerunReview": false, "canRequestReview": true,
            "canRetryRender": false, "canReroute": true, "rerouteTargets": ["example/web"],
        ]
        let task = try JSONDecoder().decode(AppTask.self, from: JSONSerialization.data(withJSONObject: object))
        let items = TaskMenu.items(task)
        #expect(items.map(\.id) == ["retry", "reroute:example/web", "cancel"])
        #expect(items.map(\.isDestructive) == [false, false, true])
        #expect(items[1].action == .reroute(repo: "example/web"))
    }

    @Test func aTaskWithNoTrueActionHasNoMenu() throws {
        #expect(TaskMenu.items(try decoded(AppTask.self, "scenario-task")).isEmpty)
    }
}
