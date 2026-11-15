import Foundation
import Testing

@testable import YakKit

private func inputs(
    show: String = "scenario-overview-thread-show", stack: String? = nil,
    environment: String? = "scenario-overview-environment", viewerName: String? = nil,
    previewHost: String? = nil
) throws -> OverviewInputs {
    OverviewInputs(
        show: try decoded(ThreadShow.self, show),
        stack: try stack.map { try decoded(ThreadStack.self, $0) },
        environment: try environment.map { try decoded(ThreadEnvironment.self, $0) },
        viewerName: viewerName, previewHost: previewHost)
}

@Test func groupsComeInTheSpecOrder() throws {
    let content = try #require(OverviewContent.make(inputs(stack: "scenario-overview-busy-stack")))
    #expect(content.groupOrder == [.subThreads, .status, .links, .access, .people])
    let none = try #require(OverviewContent.make(inputs(stack: "scenario-overview-no-sub-threads")))
    #expect(none.groupOrder == [.status, .links, .access, .people])
    let child = try #require(
        OverviewContent.make(
            inputs(show: "scenario-overview-child-show", stack: "scenario-overview-child-view")))
    #expect(child.subThreads?.parent?.title == "Rebuild the search index" && child.subThreads?.awake == nil)
    #expect(try #require(OverviewContent.make(inputs())).groupOrder == [.status, .links, .access, .people])
}

@Test func theSubThreadsGroupShowsOnlyWhatTheServerAllows() throws {
    let busy = try #require(OverviewContent.make(inputs(stack: "scenario-overview-busy-stack"))?.subThreads)
    #expect(busy.total == 3 && busy.working == 1 && busy.add == .allowed && busy.canStop)
    #expect(busy.awake == AwakeStepperValue(value: 2, range: 1...4, awake: 1, waiting: 0))
    #expect(busy.messages.count == 2)
    let stopped = try #require(
        OverviewContent.make(inputs(stack: "scenario-overview-stopped-stack"))?.subThreads)
    #expect(!stopped.canStop && stopped.stoppedAt != nil)
    #expect(
        stopped.add
            == .refused(
                code: "stack_stopped", message: "This stack was stopped, so it cannot start sub-threads."))
    let recipient = try #require(
        OverviewContent.make(inputs(stack: "scenario-overview-recipient-stack"))?.subThreads)
    #expect(recipient.awake == nil && !recipient.canStop)
    #expect(
        recipient.add == .refused(code: "forbidden", message: "You do not have access to this."))
    #expect(recipient.rows.allSatisfy { $0.actions == [.open] })
    let full = try #require(OverviewContent.make(inputs(stack: "scenario-overview-full-stack"))?.subThreads)
    #expect(full.total == 12)
    if case .refused(let code, _) = full.add { #expect(code == "stack_full") } else { Issue.record("not refused") }
}

@Test func theStepperStaysInRange() {
    let stepper = AwakeStepperValue(value: 2, range: 1...4, awake: 1, waiting: 0)
    #expect(stepper.clamped(0) == 1 && stepper.clamped(9) == 4 && stepper.clamped(3) == 3)
}

@Test func aZeroMaximumGivesARangeOfOne() throws {
    let stack = try JSONDecoder().decode(
        ThreadStack.self,
        from: Scenarios.data("scenario-overview-busy-stack", setting: ["limit", "max"], toJSON: "0"))
    let group = try #require(
        OverviewContent.make(
            OverviewInputs(show: decoded(ThreadShow.self, "scenario-overview-thread-show"), stack: stack))?
            .subThreads)
    #expect(group.awake?.range == 1...1 && group.awake?.value == 1)
}

@Test func retryOnlyOnAFailedExtraRepository() throws {
    var failed = try inputs(environment: "scenario-overview-environment-setup-failed")
    let rows = try #require(OverviewContent.make(failed)).links.repositories
    #expect(rows.map(\.canRetry) == [false, true])
    #expect(rows[1].error == "npm install exited with 1." && rows[1].output == "npm ERR! missing script: build")
    failed.liveThread = ThreadStateChange(status: .closed, sandboxState: "running", runnerState: "idle")
    #expect(try #require(OverviewContent.make(failed)).links.repositories.allSatisfy { !$0.canRetry })
    let recipientShow = try JSONDecoder().decode(
        ThreadShow.self,
        from: Scenarios.data("scenario-overview-thread-show", setting: ["canDrive"], toJSON: "false"))
    let recipient = OverviewInputs(
        show: recipientShow,
        environment: try decoded(ThreadEnvironment.self, "scenario-overview-environment-setup-failed"))
    #expect(try #require(OverviewContent.make(recipient)).links.repositories.allSatisfy { !$0.canRetry })
}

@Test func statusPrefersTheLiveState() throws {
    var live = try inputs()
    live.show = try JSONDecoder().decode(
        ThreadShow.self,
        from: Scenarios.data(
            "scenario-overview-thread-show", setting: ["thread", "sandboxState"], toJSON: "\"paused\""))
    live.liveThread = ThreadStateChange(status: .open, sandboxState: "starting", runnerState: "idle")
    let starting = try #require(OverviewContent.make(live)).status
    #expect(starting.sandbox == .starting && starting.tone == .attention && starting.sleepsAfterMinutes == nil)
    live.liveThread = nil
    let running = try #require(OverviewContent.make(live)).status
    #expect(running.sandbox == .running && running.tone == .good)
    #expect(running.slots == CapacityCounter(used: 2, limit: 4) && running.sleepsAfterMinutes == 30)
    live.environment = nil
    let saved = try #require(OverviewContent.make(live)).status
    #expect(saved.sandbox == SandboxState(rawValue: "paused") && saved.tone == .attention)
    live.liveThread = ThreadStateChange(status: .open, sandboxState: "teleporting", runnerState: "idle")
    #expect(try #require(OverviewContent.make(live)).status.tone == .neutral)
}

@Test(arguments: [
    ("team", true, 0, VisibilityLine.sharedWithTeam), ("private", true, 0, .privateToYou),
    ("private", true, 2, .sharedWithPeople(2)), ("private", false, 0, .sharedWithYou),
])
func visibilityLines(visibility: String, isOwner: Bool, sharedWith: Int, expected: VisibilityLine) throws {
    var data = try Scenarios.data(
        "scenario-overview-thread-show", setting: ["thread", "visibility"], toJSON: "\"\(visibility)\"")
    var object = try #require(JSONSerialization.jsonObject(with: data) as? [String: Any])
    var thread = try #require(object["thread"] as? [String: Any])
    thread["isOwner"] = isOwner
    thread["sharedWithCount"] = sharedWith
    object["thread"] = thread
    data = try JSONSerialization.data(withJSONObject: object)
    let people = try #require(
        OverviewContent.make(OverviewInputs(show: JSONDecoder().decode(ThreadShow.self, from: data)))
    ).people
    #expect(people.visibility == expected && people.canChangeSharing == isOwner)
}

@Test func startedByYouOrSomeoneElse() throws {
    let at = ISO8601DateFormatter().date(from: "2026-11-15T11:00:00Z")
    let you = try #require(OverviewContent.make(inputs(viewerName: "Test Person"))).people
    #expect(you.startedBy == .you(at: at))
    let other = try #require(OverviewContent.make(inputs(viewerName: "Second Person"))).people
    #expect(other.startedBy == .person(name: "Test Person", at: at))
    #expect(other.account?.name == "Shared account")
    #expect(try #require(OverviewContent.make(inputs(environment: nil))).people.startedBy == nil)
}

@Test func accessAndLinks() throws {
    let content = try #require(OverviewContent.make(inputs(previewHost: "preview.example.test")))
    #expect(content.access == AccessGroup(sshCommand: "yak ssh s7ackr00", runsOn: .yakSandbox))
    #expect(content.links.previewHost == "preview.example.test" && !content.links.isEmpty)
    #expect(content.links.branch == "yak/rebuild-search-index" && content.links.pullRequest?.number == 41)
    let device = try JSONDecoder().decode(
        ThreadShow.self,
        from: Scenarios.data("scenario-overview-thread-show", setting: ["thread", "ownerSide"], toJSON: "\"device\""))
    #expect(try #require(OverviewContent.make(OverviewInputs(show: device))).access.runsOn == .computer)
    let new = try #require(OverviewContent.make(inputs(environment: "scenario-overview-environment-new")))
    #expect(new.links.isEmpty == false)  // the show's branch still counts
    let bare = try JSONDecoder().decode(
        ThreadShow.self,
        from: Scenarios.data("scenario-overview-thread-show", setting: ["thread", "branch"], toJSON: "null"))
    let empty = try #require(
        OverviewContent.make(
            OverviewInputs(
                show: bare, environment: decoded(ThreadEnvironment.self, "scenario-overview-environment-new"))))
    #expect(empty.links.isEmpty)
}

@Test(arguments: [
    (nil, "idle", "idle", nil, "closed", OverviewActivity.closed),
    ("running", "disabled_blocked", "busy", nil, "open", .blocked),
    ("running", "idle", "idle", nil, "open", .working),
    (nil, "working", "idle", nil, "open", .working),
    (nil, "idle", "busy", nil, "open", .working),
    ("waiting_for_sandbox", "idle", "starting", nil, "open", .waiting),
    ("waiting_for_account", "idle", "idle", nil, "open", .waiting),
    (nil, "idle", "idle", "waiting_for_account", "open", .waiting),
    ("completed", "idle", "idle", nil, "open", .idle),
])
func activityFollowsTheThread(
    turn: String?, composer: String, runner: String, notice: String?, status: String, expected: OverviewActivity
) throws {
    var object = try #require(
        JSONSerialization.jsonObject(with: Scenarios.data("scenario-overview-thread-show")) as? [String: Any])
    var composerObject = try #require(object["composer"] as? [String: Any])
    composerObject["state"] = composer
    object["composer"] = composerObject
    var thread = try #require(object["thread"] as? [String: Any])
    thread["runnerState"] = runner
    thread["status"] = status
    object["thread"] = thread
    if let notice {
        object["notice"] = ["kind": notice, "title": "Waiting", "body": "Waiting.", "canContinue": false]
    }
    let show = try JSONDecoder().decode(ThreadShow.self, from: JSONSerialization.data(withJSONObject: object))
    let liveTurn = turn.map { TurnChange(turnId: LenientID("41"), state: TurnState(rawValue: $0)) }
    #expect(try #require(OverviewContent.make(OverviewInputs(show: show, liveTurn: liveTurn))).activity == expected)
}

@Test func aBlockedReasonBlocksTheThread() throws {
    let show = try JSONDecoder().decode(
        ThreadShow.self,
        from: Scenarios.data(
            "scenario-overview-thread-show", setting: ["thread", "blockedReason"], toJSON: "\"Needs a token.\""))
    #expect(try #require(OverviewContent.make(OverviewInputs(show: show))).activity == .blocked)
}

@Test func activityOfAnIdleThread() throws {
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    let content = try #require(OverviewContent.make(OverviewInputs(show: show)))
    #expect(content.activity == .idle && content.title == show.thread.title)
}

@Test func nothingBeforeTheShowLoads() {
    #expect(OverviewContent.make(OverviewInputs(show: nil)) == nil)
}
