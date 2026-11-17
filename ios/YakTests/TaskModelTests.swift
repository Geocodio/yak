import Foundation
import Testing
import YakKit

@testable import Yak

private func json(_ object: [String: Any]) throws -> Data { try JSONSerialization.data(withJSONObject: object) }

/// The co-owner task with its viewer's access changed.
private func task(access: String = "write", canPick: Bool? = nil) -> [String: Any] {
    var body = ScenarioFile.object("scenario-task")
    var panel = body["coOwner"] as? [String: Any] ?? [:]
    var viewer = panel["viewer"] as? [String: Any] ?? [:]
    viewer["access"] = access
    panel["viewer"] = viewer
    body["coOwner"] = panel
    if let canPick {
        body["coOwner"] = NSNull()
        body["earlyWarning"] = [
            "state": "shown", "canPick": canPick, "taggedName": NSNull(), "fallbackName": NSNull(),
            "repoName": "example/api",
        ]
    }
    return body
}

private final class Access: @unchecked Sendable {
    private let lock = NSLock()
    private var value: String
    init(_ value: String) { self.value = value }
    func set(_ new: String) { lock.withLock { value = new } }
    var current: String { lock.withLock { value } }
}

@MainActor private func model(
    _ fixture: TasksFixture, id: String = "k3m9p2qa"
) -> (TaskModel, SceneModel) {
    let scene = SceneModel(state: SceneState())
    return (TaskModel(taskID: id, session: fixture.session, scene: scene), scene)
}

@MainActor @Test func readOnlyHidesEveryAction() async throws {
    let fixture = try await TasksFixture { route, _, _ in
        route == "GET tasks/r7t3w9yb" ? (200, ScenarioFile.data("scenario-task-read-only")) : nil
    }
    let (model, _) = model(fixture, id: "r7t3w9yb")
    await model.start()
    #expect(await fixture.eventually { model.task != nil })
    #expect(model.task?.canDrive == false)
    #expect(model.decision == .none)
    #expect(model.menu.isEmpty)
    #expect(model.earlyWarning == .none)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func accessCheckFailedRetriesByReloading() async throws {
    let access = Access("error")
    let fixture = try await TasksFixture { route, _, _ in
        route == "GET tasks/k3m9p2qa" ? (200, try json(task(access: access.current))) : nil
    }
    let (model, _) = model(fixture)
    await model.start()
    #expect(await fixture.eventually { model.decision == .accessCheckFailed })
    let reads = fixture.transport.count { $0 == "GET tasks/k3m9p2qa" }
    access.set("write")
    await model.retry()
    #expect(await fixture.eventually { model.decision == .decide })
    #expect(fixture.transport.count { $0 == "GET tasks/k3m9p2qa" } == reads + 1)
    #expect(fixture.transport.count { $0.hasPrefix("POST") } == 0)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func theEarlyWarningRowFollowsCanPick() async throws {
    for canPick in [false, true] {
        let fixture = try await TasksFixture { route, _, _ in
            route == "GET tasks/k3m9p2qa" ? (200, try json(task(canPick: canPick))) : nil
        }
        let (model, _) = model(fixture)
        await model.start()
        #expect(await fixture.eventually { model.task != nil })
        #expect((model.earlyWarning == .none) == !canPick)
        await model.stop()
        await fixture.session.stop()
    }
}

@MainActor @Test func approveShowsProgressAndReloadsOnTimeout() async throws {
    let fixture = try await TasksFixture { route, _, _ in
        switch route {
        case "GET tasks/k3m9p2qa": return (200, try json(task()))
        case "POST tasks/k3m9p2qa/co-owner/approve": throw URLError(.timedOut)
        default: return nil
        }
    }
    let (model, _) = model(fixture)
    await model.start()
    #expect(await fixture.eventually { model.decision == .decide })
    let reads = fixture.transport.count { $0 == "GET tasks/k3m9p2qa" }
    let accepted = await model.perform(.approve)
    #expect(!accepted)
    #expect(model.message != nil)
    #expect(model.sending == nil)
    #expect(fixture.transport.count { $0 == "POST tasks/k3m9p2qa/co-owner/approve" } == 1)
    #expect(fixture.transport.count { $0 == "GET tasks/k3m9p2qa" } == reads + 1)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aRefusedFeedbackKeepsTheText() async throws {
    let fixture = try await TasksFixture { route, _, _ in
        switch route {
        case "GET tasks/k3m9p2qa": return (200, try json(task()))
        case "POST tasks/k3m9p2qa/co-owner/request-changes":
            return (
                422,
                errorBody(
                    "validation_failed", "Not valid.", details: ["errors": ["feedback": ["Write what to change."]]])
            )
        default: return nil
        }
    }
    let (model, _) = model(fixture)
    await model.start()
    #expect(await fixture.eventually { model.decision == .decide })
    model.feedback = "Keep the old client."
    let accepted = await model.perform(.requestChanges(feedback: model.feedback))
    #expect(!accepted)
    #expect(model.feedback == "Keep the old client.")
    #expect(model.feedbackError == "Write what to change.")
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func alreadyResolvedByMeIsNotAnError() async throws {
    let fixture = try await TasksFixture { route, _, _ in
        switch route {
        case "GET tasks/k3m9p2qa": return (200, try json(task()))
        case "POST tasks/k3m9p2qa/co-owner/approve": return (409, errorBody("already_resolved", "Already decided."))
        default: return nil
        }
    }
    let (model, _) = model(fixture)
    await model.start()
    #expect(await fixture.eventually { model.decision == .decide })
    #expect(await model.perform(.approve))
    #expect(model.message == nil)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func openThreadUsesTheThreadId() async throws {
    let fixture = try await TasksFixture { route, _, _ in
        route == "GET tasks/k3m9p2qa" ? (200, try json(task())) : nil
    }
    let (model, scene) = model(fixture)
    await model.start()
    #expect(await fixture.eventually { model.task != nil })
    model.openThread()
    #expect(scene.state.threadID == "d0e515ef")
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aChildTaskReplacesItsId() async throws {
    let fixture = try await TasksFixture { route, _, _ in
        switch route {
        case "GET tasks/n5p9s3vx": return (200, try json(task()))
        case "POST tasks/k3m9p2qa/co-owner/approve": return (200, Data(#"{"status":"approved"}"#.utf8))
        default: return nil
        }
    }
    let (model, _) = model(fixture, id: "n5p9s3vx")
    await model.start()
    #expect(await fixture.eventually { model.decision == .decide })
    #expect(await model.perform(.approve))
    #expect(fixture.transport.count { $0 == "POST tasks/k3m9p2qa/co-owner/approve" } == 1)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aGitHubFailureOffersARetryThatReloads() async throws {
    let fixture = try await TasksFixture { route, _, _ in
        switch route {
        case "GET tasks/k3m9p2qa": return (200, try json(task()))
        case "POST tasks/k3m9p2qa/co-owner/approve": return (502, errorBody("github_failed", "GitHub did not answer."))
        default: return nil
        }
    }
    let (model, _) = model(fixture)
    await model.start()
    #expect(await fixture.eventually { model.decision == .decide })
    #expect(!(await model.perform(.approve)))
    #expect(model.message == "GitHub did not answer.")
    #expect(model.canRetryAfterMessage)
    let reads = fixture.transport.count { $0 == "GET tasks/k3m9p2qa" }
    await model.retry()
    #expect(model.message == nil)
    #expect(!model.canRetryAfterMessage)
    #expect(fixture.transport.count { $0 == "GET tasks/k3m9p2qa" } == reads + 1)
    #expect(fixture.transport.count { $0.hasPrefix("POST") } == 1)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func answeringTheQuestionsSendsOnceAndReloads() async throws {
    let fixture = try await TasksFixture { route, _, _ in
        switch route {
        case "GET tasks/q5w8e2rt": return (200, ScenarioFile.data("scenario-task-question"))
        case "POST tasks/q5w8e2rt/clarification-answers": return (200, try json(["status": "sent"]))
        default: return nil
        }
    }
    let (model, _) = model(fixture, id: "q5w8e2rt")
    await model.start()
    #expect(await fixture.eventually { model.questionFlow != nil })
    let count = model.questionFlow?.questions.count ?? 0
    for _ in 0..<count {
        let label = model.questionFlow.flatMap { flow -> String? in
            guard case .question(let index) = flow.step else { return nil }
            return flow.questions[index].options.first?.label
        }
        model.changeQuestions { $0.pick(label ?? "") }
        model.changeQuestions { $0.next() }
    }
    #expect(model.questionFlow?.canSend == true)
    let reads = fixture.transport.count { $0 == "GET tasks/q5w8e2rt" }
    await model.sendAnswers()
    await model.sendAnswers()
    #expect(fixture.transport.count { $0 == "POST tasks/q5w8e2rt/clarification-answers" } == 1)
    #expect(fixture.transport.count { $0 == "GET tasks/q5w8e2rt" } > reads)
    await model.stop()
    await fixture.session.stop()
}

@Test func aKnownStatusUsesTheCatalogAndAnUnknownOneTheServerLabel() {
    #expect(
        TaskRowView.statusTitle(.awaitingCoOwner, serverLabel: "awaiting co owner") == "Waiting for the co-owner")
    #expect(TaskRowView.statusTitle(TaskStatus(rawValue: "paused"), serverLabel: "on hold") == "on hold")
}

@Test(arguments: [
    "http://example.test/", "javascript:alert(1)", "https://user@example.test/", "file:///etc/passwd",
])
func aTappedConversationLinkOpensOnlyWhenItIsSafe(_ text: String) {
    #expect(TaskBodyView.linkToOpen(URL(string: text)) == nil)
}

@Test func anHTTPSConversationLinkOpens() {
    let url = URL(string: "https://example.test/pull/1")
    #expect(TaskBodyView.linkToOpen(url) == url)
    #expect(TaskBodyView.linkToOpen(nil) == nil)
}

@MainActor @Test func anUnsafeConnectURLOffersNoLinkButton() async throws {
    let fixture = try await TasksFixture { route, _, _ in
        switch route {
        case "GET tasks/k3m9p2qa": return (200, try json(task()))
        case "POST tasks/k3m9p2qa/co-owner/approve":
            return (
                409, errorBody("not_linked", "Link GitHub first.", details: ["connectUrl": "javascript:alert(1)"])
            )
        default: return nil
        }
    }
    let (model, _) = model(fixture)
    await model.start()
    #expect(await fixture.eventually { model.decision == .decide })
    _ = await model.perform(.approve)
    #expect(model.decision == .linkGitHub(connectURL: "javascript:alert(1)"))
    #expect(model.linkGitHubURL == nil)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aSafeConnectURLOffersTheLinkButton() async throws {
    let fixture = try await TasksFixture { route, _, _ in
        switch route {
        case "GET tasks/k3m9p2qa": return (200, try json(task()))
        case "POST tasks/k3m9p2qa/co-owner/approve":
            return (
                409, errorBody("not_linked", "Link GitHub first.", details: ["connectUrl": "https://example.test/link"])
            )
        default: return nil
        }
    }
    let (model, _) = model(fixture)
    await model.start()
    #expect(await fixture.eventually { model.decision == .decide })
    _ = await model.perform(.approve)
    #expect(model.linkGitHubURL?.absoluteString == "https://example.test/link")
    await model.stop()
    await fixture.session.stop()
}
