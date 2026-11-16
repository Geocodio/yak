import Foundation
import Testing

@testable import YakKit

private func scenarioTask(_ name: String = "scenario-task", edit: (inout [String: Any]) -> Void = { _ in }) throws
    -> AppTask
{
    var object = try #require(try JSONSerialization.jsonObject(with: Scenarios.data(name)) as? [String: Any])
    edit(&object)
    return try JSONDecoder().decode(AppTask.self, from: JSONSerialization.data(withJSONObject: object))
}

private func withViewer(_ changes: [String: Any]) -> (inout [String: Any]) -> Void {
    { object in
        var panel = object["coOwner"] as? [String: Any] ?? [:]
        var viewer = panel["viewer"] as? [String: Any] ?? [:]
        for (key, value) in changes { viewer[key] = value }
        panel["viewer"] = viewer
        object["coOwner"] = panel
    }
}

private func notice() -> [String: Any] {
    [
        "state": "shown", "canPick": true, "taggedName": NSNull(), "fallbackName": "Second person",
        "repoName": "example/api", "areas": [["name": "Billing", "risk": "high"]],
    ]
}

private func withNotice(_ changes: [String: Any]) -> (inout [String: Any]) -> Void {
    { object in
        object["coOwner"] = NSNull()
        object["earlyWarning"] = notice().merging(changes) { _, new in new }
    }
}

@Suite struct TaskDecisionStateTests {
    @Test func decisionsFollowTheViewer() throws {
        #expect(TaskDecisionState.make(try scenarioTask()) == .decide)
        #expect(
            TaskDecisionState.make(try scenarioTask(edit: withViewer(["isRequester": true])))
                == .waitingForOthers(asked: ["Second person"]))
        #expect(
            TaskDecisionState.make(try scenarioTask(edit: withViewer(["isLinked": false])))
                == .linkGitHub(connectURL: "https://yak.example.com/settings/github"))
        #expect(TaskDecisionState.make(try scenarioTask(edit: withViewer(["access": "read"]))) == .noWriteAccess)
        #expect(TaskDecisionState.make(try scenarioTask(edit: withViewer(["access": "error"]))) == .accessCheckFailed)
        #expect(
            TaskDecisionState.make(try scenarioTask(edit: withViewer(["access": "teleported"])))
                == .accessCheckFailed)
        #expect(TaskDecisionState.make(try scenarioTask("scenario-task-read-only")) == .none)
        #expect(TaskDecisionState.make(try scenarioTask("scenario-task-question")) == .none)
    }

    @Test func aDecidedRequestShowsWhoDecided() throws {
        let task = try scenarioTask { object in
            var panel = object["coOwner"] as? [String: Any] ?? [:]
            panel["status"] = "approved"
            panel["decidedBy"] = "Second person"
            object["coOwner"] = panel
        }
        #expect(TaskDecisionState.make(task) == .decided(status: "approved", by: "Second person"))
    }

    @Test func aReadOnlyViewerGetsNoDecisionWhateverTheRequestSays() throws {
        let task = try scenarioTask { object in
            object["canDrive"] = false
            var panel = object["coOwner"] as? [String: Any] ?? [:]
            panel["status"] = "approved"
            object["coOwner"] = panel
        }
        #expect(TaskDecisionState.make(task) == .none)
    }

    @Test func earlyWarningFollowsCanPick() throws {
        #expect(EarlyWarningRow.make(try scenarioTask()) == .none)
        #expect(
            EarlyWarningRow.make(try scenarioTask(edit: withNotice([:]))) == .pick(fallbackName: "Second person"))
        #expect(EarlyWarningRow.make(try scenarioTask(edit: withNotice(["canPick": false]))) == .none)
        #expect(
            EarlyWarningRow.make(
                try scenarioTask(
                    edit: withNotice(["state": "tagged", "taggedName": "Second person", "canPick": false])))
                == .asked(name: "Second person"))
        #expect(
            EarlyWarningRow.make(try scenarioTask(edit: withNotice(["state": "declined", "canPick": false]))) == .none)
        #expect(
            EarlyWarningRow.make(
                try scenarioTask(edit: { object in
                    withNotice([:])(&object)
                    object["canDrive"] = false
                })) == .none)
    }
}
