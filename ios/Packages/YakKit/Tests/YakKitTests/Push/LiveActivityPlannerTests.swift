import Foundation
import Testing

@testable import YakKit

private let home = URL(string: "https://yak.example.com")!
private let other = URL(string: "https://other.example.com")!
private let gone = URL(string: "https://gone.example.com")!

private func running(_ id: String, _ thread: String, _ instance: URL, minutesAgo: Int) -> RunningActivity {
    RunningActivity(
        activityID: id, instance: instance, threadID: thread,
        startedAt: Date(timeIntervalSince1970: 1_800_000_000 - Double(minutesAgo) * 60))
}

private func showSetting(_ path: [String], toJSON json: String) throws -> ThreadShow {
    try JSONDecoder().decode(
        ThreadShow.self, from: Scenarios.data("scenario-thread-show", setting: path, toJSON: json))
}

@Test func aFourthActivityEndsTheOldestAndReportsTheSet() {
    let three = [
        running("a", "a1b2c3d4", home, minutesAgo: 30), running("b", "k3m9p2qa", other, minutesAgo: 20),
        running("c", "e5f6g7h8", home, minutesAgo: 10),
    ]
    let plan = LiveActivityPlanner.plan(starting: "d0e515ef", on: home, running: three, instances: [home, other])
    #expect(plan.starts && plan.endActivityIDs == ["a"])
    #expect(plan.reports == [home: ["d0e515ef", "e5f6g7h8"], other: ["k3m9p2qa"]])
}

@Test func withRoomNothingEnds() {
    let plan = LiveActivityPlanner.plan(
        starting: "d0e515ef", on: home, running: [running("a", "a1b2c3d4", home, minutesAgo: 5)],
        instances: [home, other])
    #expect(plan.starts && plan.endActivityIDs.isEmpty)
    #expect(plan.reports == [home: ["a1b2c3d4", "d0e515ef"], other: []])
}

@Test func neverADuplicate() {
    let existing = [running("a", "d0e515ef", home, minutesAgo: 5)]
    let same = LiveActivityPlanner.plan(starting: "d0e515ef", on: home, running: existing, instances: [home, other])
    #expect(!same.starts && same.endActivityIDs.isEmpty)
    #expect(same.reports == [home: ["d0e515ef"], other: []])
    let elsewhere = LiveActivityPlanner.plan(
        starting: "d0e515ef", on: other, running: existing, instances: [home, other])
    #expect(elsewhere.starts)
    #expect(elsewhere.reports == [home: ["d0e515ef"], other: ["d0e515ef"]])
}

@Test func anEmptiedInstanceIsReportedEmpty() {
    let three = [
        running("a", "a1b2c3d4", other, minutesAgo: 30), running("b", "k3m9p2qa", other, minutesAgo: 20),
        running("c", "e5f6g7h8", other, minutesAgo: 10),
    ]
    let plan = LiveActivityPlanner.plan(starting: "d0e515ef", on: home, running: three, instances: [home, other])
    #expect(plan.endActivityIDs == ["a"])
    #expect(plan.reports == [home: ["d0e515ef"], other: ["e5f6g7h8", "k3m9p2qa"]])

    let single = LiveActivityPlanner.plan(
        starting: "d0e515ef", on: other, running: [running("a", "a1b2c3d4", other, minutesAgo: 5)],
        instances: [home, other])
    #expect(single.reports[home] == [])
}

@Test func activitiesOfAnUnpairedInstanceEndFirst() {
    let plan = LiveActivityPlanner.plan(
        starting: "d0e515ef", on: home,
        running: [running("x", "a1b2c3d4", gone, minutesAgo: 1), running("y", "e5f6g7h8", home, minutesAgo: 50)],
        instances: [home, other])
    #expect(plan.starts && plan.endActivityIDs == ["x"])
    #expect(plan.reports[gone] == nil)
}

@Test func aPushStartedDuplicateIsEnded() {
    let set = [
        running("app", "d0e515ef", home, minutesAgo: 10), running("push", "d0e515ef", home, minutesAgo: 1),
        running("c", "e5f6g7h8", home, minutesAgo: 5),
    ]
    let plan = LiveActivityPlanner.reconcile(running: set, instances: [home, other])
    #expect(!plan.starts && plan.endActivityIDs == ["push"])
    #expect(plan.reports == [home: ["d0e515ef", "e5f6g7h8"], other: []])

    let twoInstances = [running("a", "d0e515ef", home, minutesAgo: 10), running("b", "d0e515ef", other, minutesAgo: 5)]
    #expect(LiveActivityPlanner.reconcile(running: twoInstances, instances: [home, other]).endActivityIDs.isEmpty)
}

@Test func remoteStartsFromTwoInstancesNeverExceedThree() {
    let four = [
        running("a", "t1", home, minutesAgo: 30), running("b", "t2", home, minutesAgo: 20),
        running("c", "t3", other, minutesAgo: 25), running("d", "t4", other, minutesAgo: 5),
    ]
    let plan = LiveActivityPlanner.reconcile(running: four, instances: [home, other])
    #expect(plan.endActivityIDs == ["a"])
    #expect(plan.reports == [home: ["t2"], other: ["t3", "t4"]])

    let five = four + [running("e", "t5", home, minutesAgo: 1)]
    #expect(LiveActivityPlanner.reconcile(running: five, instances: [home, other]).endActivityIDs == ["a", "c"])
}

@Test func reconcileEndsUnpairedThenDuplicatesThenTheExcess() {
    let set = [
        running("x", "t0", gone, minutesAgo: 40), running("a", "t1", home, minutesAgo: 30),
        running("dup", "t1", home, minutesAgo: 2), running("b", "t2", home, minutesAgo: 20),
        running("c", "t3", other, minutesAgo: 10), running("d", "t4", other, minutesAgo: 5),
    ]
    let plan = LiveActivityPlanner.reconcile(running: set, instances: [home, other])
    #expect(plan.endActivityIDs == ["x", "dup", "a"])
}

@Test func reconcileWithNothingToEndOnlyReports() {
    let plan = LiveActivityPlanner.reconcile(
        running: [running("a", "t1", home, minutesAgo: 3), running("b", "t2", home, minutesAgo: 2)],
        instances: [home, other])
    #expect(plan.endActivityIDs.isEmpty)
    #expect(plan.reports == [home: ["t1", "t2"], other: []])
}

@Test func onlyADriverOfAnOpenThreadGetsOne() throws {
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    func should(
        _ show: ThreadShow?, active: Bool = true, availability: PushAvailability? = .available, enabled: Bool = true
    ) -> Bool {
        LiveActivityPlanner.shouldStart(
            show: show, turnIsActive: active, availability: availability, activitiesEnabled: enabled)
    }
    #expect(should(show))
    #expect(!should(try showSetting(["canDrive"], toJSON: "false")))
    #expect(!should(show, active: false))
    #expect(!should(show, availability: .otherBuild))
    #expect(!should(show, availability: nil))
    #expect(!should(show, enabled: false))
    #expect(!should(try showSetting(["thread", "status"], toJSON: "\"closed\"")))
    #expect(!should(nil))
}

@Test func aPrivateThreadStartsWithTheGuardedTitle() throws {
    let now = Date(timeIntervalSince1970: 1_794_830_400)  // 2026-11-16 12:00 UTC
    let privateShow = try showSetting(["thread", "visibility"], toJSON: "\"private\"")
    let values = LiveActivityPlanner.startValues(show: privateShow, instance: home, now: now)
    #expect(values.title == "Private thread" && values.content.isPrivate && values.content.step == "")

    let team = LiveActivityPlanner.startValues(
        show: try decoded(ThreadShow.self, "scenario-thread-show"), instance: home, now: now)
    #expect(team.title == "Retry failed rows when the import times out")
    #expect(team.threadID == "d0e515ef" && team.instance == "https://yak.example.com")
    #expect(team.content.state == .working && !team.content.isPrivate)
    #expect(team.content.turnStartedAt == "2026-11-16T12:00:00+00:00")
    #expect(team.staleDate == now.addingTimeInterval(1800))
}

@Test func contentStateDecodesTheServerShape() throws {
    let content = try decoded(ThreadActivityContentState.self, "scenario-push-activity-working")
    #expect(content.state == .working && content.step == "Running a command")
    #expect(content.turnStartedAt == "2026-11-16T12:00:00+00:00")
    #expect(
        try decoded(ThreadActivityContentState.self, "scenario-push-activity-unknown").state.rawValue
            == "paused_by_admin")
}

@Test func theStartPushHasTheAgreedShape() throws {
    let object = try #require(
        try JSONSerialization.jsonObject(with: Scenarios.data("scenario-push-activity-start")) as? [String: Any])
    let aps = try #require(object["aps"] as? [String: Any])
    #expect(aps["attributes-type"] as? String == "ThreadActivityAttributes")
    #expect(Set((aps["attributes"] as? [String: Any] ?? [:]).keys) == ["instance", "threadId", "title"])
    let state = try JSONDecoder().decode(
        ThreadActivityContentState.self,
        from: JSONSerialization.data(withJSONObject: try #require(aps["content-state"])))
    #expect(state.state == .working && state.step == "Starting")
}
