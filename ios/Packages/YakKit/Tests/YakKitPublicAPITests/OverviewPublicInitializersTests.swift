import Foundation
import Testing
import YakKit

// Plain `import YakKit`, never `@testable`: previews and test doubles outside the module build values with public initializers only.

@Test func overviewModelsAreBuildableOutsideTheModule() {
    let child = StackChild(
        id: "c1", name: "child", title: "Child", kind: .implement, model: "sonnet", state: .working, step: "Step",
        filesChanged: 2, canOverride: true)
    let limit = StackLimit(current: 2, max: 4, awake: 1, waiting: 0)
    let stack = ThreadStack(
        rootId: "r1", isRoot: true, parent: StackParent(id: "p1", name: "parent", title: "Parent"), children: [child],
        limit: limit, maxChildren: 12, models: [], parentAccountName: nil,
        usage: StackUsage(turnCount: 1, costUsd: 0.5, activeHours: 1, note: nil),
        messages: [
            StackMessage(
                id: LenientID("1"), fromName: "a", toName: "b", kind: .result, text: "Done",
                createdAt: "2026-11-15T11:00:00Z")
        ],
        canDrive: true, canStop: true, stoppedAt: nil, addChild: AddChildAvailability(allowed: true))
    #expect(stack.children == [child] && stack.limit == limit)
    #expect(SubThreadCreated(id: "n1", name: "name").id == "n1")
    #expect(AwakeLimitUpdated(status: "updated", limit: limit).limit == limit)

    let repository = EnvironmentRepository(
        id: LenientID("1"), repo: "example/api", role: .primary, path: "/workspace", setupStatus: .ready)
    let environment = ThreadEnvironment(
        sandbox: ThreadEnvironment.Sandbox(state: .running),
        pullRequest: ThreadEnvironment.PullRequest(number: 1, url: "https://example.com/1", state: "open"),
        deployment: ThreadEnvironment.Deployment(url: "https://example.com", status: "ready"),
        account: ThreadEnvironment.Account(name: "Account", kind: "setup_token", isYours: true),
        startedBy: ThreadEnvironment.StartedBy(name: "Test Person", at: "2026-11-15T11:00:00Z"),
        stoppedAfterMinutes: 30, repositories: [repository])
    #expect(environment.repositories == [repository] && environment.notUploadedDirectories.isEmpty)

    let share = ThreadShare(
        mode: .people, userIds: [LenientID("7")], people: [SharePerson(id: LenientID("7"), name: "Test Person")])
    #expect(share.mode == .people && ThreadShareUpdated(mode: .onlyMe, userIds: []).userIds.isEmpty)
    #expect(
        ThreadCapacity(
            turns: CapacityCounter(used: 1, limit: 2), sandboxes: CapacityCounter(used: 1, limit: 2),
            share: CapacityCounter(used: 1, limit: 2)
        ).share.used == 1)
}
