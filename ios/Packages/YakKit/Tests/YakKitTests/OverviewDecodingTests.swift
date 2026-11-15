import Foundation
import Testing

@testable import YakKit

@Test func decodesABusyStack() throws {
    let stack = try decoded(ThreadStack.self, "scenario-overview-busy-stack")
    #expect(stack.rootId == "s7ackr00" && stack.isRoot && stack.parent == nil)
    #expect(stack.children.map(\.id) == ["b4ckf111", "q7adapt0", "r3search"])
    #expect(stack.children.map(\.state) == [.working, .failed, .done] && stack.children[1].canOverride)
    #expect(stack.limit == StackLimit(current: 2, max: 4, awake: 1, waiting: 0) && stack.usage.costUsd == 1.25)
    #expect(stack.addChild == AddChildAvailability(allowed: true, error: nil, message: nil) && stack.stoppedAt == nil)
    #expect(stack.messages.map(\.kind) == [.failure, .result] && stack.messages[0].id == LenientID("51"))
}

@Test func aChildSeesItsParentAndARefusedAdd() throws {
    let stack = try decoded(ThreadStack.self, "scenario-overview-child-view")
    #expect(!stack.isRoot && stack.parent?.id == "s7ackr00")
    #expect(stack.addChild.allowed == false && stack.addChild.error == "sub_thread_cannot_spawn")
    #expect(stack.addChild.message?.isEmpty == false)
}

@Test func stoppedFullAndRecipientStacksCarryTheirRefusals() throws {
    let stopped = try decoded(ThreadStack.self, "scenario-overview-stopped-stack")
    #expect(stopped.stoppedAt != nil && !stopped.canStop && stopped.addChild.error == "stack_stopped")
    let full = try decoded(ThreadStack.self, "scenario-overview-full-stack")
    #expect(full.children.count == 12 && full.addChild.error == "stack_full")
    let recipient = try decoded(ThreadStack.self, "scenario-overview-recipient-stack")
    #expect(!recipient.canDrive && !recipient.canStop && recipient.children.allSatisfy { !$0.canOverride })
    #expect(recipient.addChild.message == "You do not have access to this.")
    let empty = try decoded(ThreadStack.self, "scenario-overview-no-sub-threads")
    #expect(empty.children.isEmpty && empty.messages.isEmpty && empty.usage.turnCount == 0)
}

@Test func unknownStatesKindsAndRolesSurvive() throws {
    var stackObject = try #require(
        try JSONSerialization.jsonObject(with: Scenarios.data("scenario-overview-busy-stack")) as? [String: Any])
    var children = try #require(stackObject["children"] as? [[String: Any]])
    children[0]["state"] = "hibernating"
    children[0]["kind"] = "review"
    var messages = try #require(stackObject["messages"] as? [[String: Any]])
    messages[0]["kind"] = "note"
    stackObject["children"] = children
    stackObject["messages"] = messages
    let stack = try JSONDecoder().decode(
        ThreadStack.self, from: JSONSerialization.data(withJSONObject: stackObject))
    #expect(stack.children[0].state.rawValue == "hibernating" && stack.children[0].kind.rawValue == "review")
    #expect(stack.messages[0].kind.rawValue == "note")

    var environmentObject = try #require(
        try JSONSerialization.jsonObject(with: Scenarios.data("scenario-overview-environment")) as? [String: Any])
    var repositories = try #require(environmentObject["repositories"] as? [[String: Any]])
    repositories[0]["role"] = "mirror"
    repositories[0]["setupStatus"] = "verifying"
    environmentObject["repositories"] = repositories
    let environment = try JSONDecoder().decode(
        ThreadEnvironment.self, from: JSONSerialization.data(withJSONObject: environmentObject))
    #expect(environment.repositories[0].role.rawValue == "mirror")
    #expect(environment.repositories[0].setupStatus.rawValue == "verifying")
    #expect(!environment.repositories[0].setupStatus.isInProgress)
}

@Test func setupStatusKnowsWhatIsInProgress() {
    let progressing: [RepositorySetupStatus] = [.pending, .cloning, .setup]
    let settled: [RepositorySetupStatus] = [.ready, .failed]
    #expect(progressing.map { $0.isInProgress } == [true, true, true])
    #expect(settled.map { $0.isInProgress } == [false, false])
}

@Test func decodesTheEnvironment() throws {
    let environment = try decoded(ThreadEnvironment.self, "scenario-overview-environment")
    #expect(environment.sandbox.state == .running && environment.branch == "yak/rebuild-search-index")
    #expect(environment.pullRequest?.number == 41 && environment.deployment == nil)
    #expect(environment.account?.isYours == false && environment.stoppedAfterMinutes == 30)
    #expect(environment.repositories.map(\.id.value) == ["21", "22"])
    #expect(environment.repositories.map(\.role) == [.primary, .changes])
    let failed = try decoded(ThreadEnvironment.self, "scenario-overview-environment-setup-failed")
    #expect(failed.repositories[1].setupStatus == .failed && failed.repositories[1].setupError != nil)
    let settingUp = try decoded(ThreadEnvironment.self, "scenario-overview-environment-setting-up")
    #expect(settingUp.repositories[1].setupStatus.isInProgress)
    let new = try decoded(ThreadEnvironment.self, "scenario-overview-environment-new")
    #expect(new.branch == nil && new.pullRequest == nil && new.repositories.count == 1)
}

@Test func decodesTheShare() throws {
    let share = try decoded(ThreadShare.self, "scenario-overview-share")
    #expect(share.mode == .people && share.userIds == [LenientID("7")] && share.people.count == 3)
    #expect(share.people[0].role == "Owner" && share.secretFiles == [".env"] && share.memoryFolder == nil)
    let created = try decoded(SubThreadCreated.self, "scenario-overview-sub-thread-created")
    #expect(created == SubThreadCreated(id: "n3wch11d", name: "check-the-cache"))
}

@Test func olderShowsWithoutTheNewFieldsStillDecode() throws {
    let older = try decoded(ThreadShow.self, "scenario-thread-show")
    #expect(older.thread.sharedWithCount == nil && older.thread.startedOnDevice == nil)
    #expect(older.stackParent == nil && older.capacity == nil)

    let show = try decoded(ThreadShow.self, "scenario-overview-thread-show")
    #expect(show.capacity?.share == CapacityCounter(used: 2, limit: 4))
    #expect(show.thread.ownerSide == "yak" && show.thread.sharedWithCount == 0 && show.stackParent == nil)

    let child = try decoded(ThreadShow.self, "scenario-overview-child-show")
    #expect(child.stackParent?.id == "s7ackr00" && child.thread.rootThreadId == "s7ackr00")

    let list = try decoded(ThreadList.self, "scenario-overview-thread-list")
    let root = try #require(list.threads.first { $0.id == "s7ackr00" })
    #expect(root.children.first { $0.id == "b4ckf111" }?.step == "Writing the backfill script")
    #expect(list.threads.first { $0.id == "a1b2c3d4" }?.step == nil)
}
