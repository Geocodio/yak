import Foundation
import Testing
import YakKit

@testable import Yak

private let channelJSON = #"{"channel":"c2NlbmFyaW8tY2hhbm5lbA"}"#

/// A show decoded from the scenario data, with the parts a test changes.
private func show(
    threadID: String = "d0e515ef", canDrive: Bool = true, channel: String? = "c2NlbmFyaW8tY2hhbm5lbA",
    isPrivate: Bool = false
) throws -> ThreadShow {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    let file = directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios/scenario-thread-show.json")
    var object = try #require(try JSONSerialization.jsonObject(with: Data(contentsOf: file)) as? [String: Any])
    var thread = object["thread"] as? [String: Any] ?? [:]
    thread["id"] = threadID
    thread["isPrivate"] = isPrivate
    object["thread"] = thread
    object["canDrive"] = canDrive
    if let channel { object["liveActivityChannel"] = channel }
    return try JSONDecoder().decode(ThreadShow.self, from: JSONSerialization.data(withJSONObject: object))
}

private func snapshot(_ show: ThreadShow?, running: Bool) -> ConversationSnapshot {
    ConversationSnapshot(
        show: show, turn: TurnChange(turnId: LenientID("t1"), state: running ? .running : .completed))
}

private struct ActivityRig {
    let rig: PushRig
    let controller: LiveActivityController
    var host: FakeActivityHost { rig.activities }
    var transport: RecordingTransport { rig.transport }
}

@MainActor
private func makeActivityRig() async throws -> ActivityRig {
    let rig = try await makePushRig()
    await rig.transport.on("POST threads/d0e515ef/live-activity-channel", status: 200, body: channelJSON)
    await rig.transport.on("PUT live-activities")
    await rig.transport.on("PUT push-tokens/live_activity_start")
    await rig.registrar.start()
    return ActivityRig(rig: rig, controller: rig.controller.liveActivities)
}

@MainActor @Suite struct LiveActivityControllerTests {
    @Test func startsWhenATurnStartsWhileTheThreadIsOpen() async throws {
        let rig = try await makeActivityRig()
        let thread = try show()
        await rig.controller.observe(threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(thread, running: false))
        #expect(rig.host.started.isEmpty)
        await rig.controller.observe(threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(thread, running: true))
        #expect(rig.host.started.count == 1)
        #expect(rig.host.started.first?.channel == "c2NlbmFyaW8tY2hhbm5lbA")
        #expect(!(await rig.transport.routes().contains("POST threads/d0e515ef/live-activity-channel")))
        #expect(await eventually { await rig.transport.bodies(of: "PUT live-activities").contains { $0.contains("d0e515ef") } })
    }

    @Test func asksForTheChannelWhenTheShowHasNone() async throws {
        let rig = try await makeActivityRig()
        let thread = try show(channel: nil)
        await rig.controller.observe(threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(thread, running: true))
        let posts = await rig.transport.routes().filter { $0 == "POST threads/d0e515ef/live-activity-channel" }
        #expect(posts.count == 1)
        #expect(rig.host.started.first?.channel == "c2NlbmFyaW8tY2hhbm5lbA")
    }

    @Test func aRunningTurnOnOpenStartsOne() async throws {
        let rig = try await makeActivityRig()
        await rig.controller.observe(
            threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(try show(), running: true))
        #expect(rig.host.started.count == 1)
    }

    @Test func noSecondStartForTheSameThread() async throws {
        let rig = try await makeActivityRig()
        let thread = try show()
        await rig.controller.observe(threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(thread, running: true))
        await rig.controller.observe(threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(thread, running: true))
        #expect(rig.host.started.count == 1)
        await rig.controller.observe(threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(thread, running: false))
        await rig.controller.observe(threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(thread, running: true))
        #expect(rig.host.started.count == 1)
    }

    @Test func theFourthEndsTheOldest() async throws {
        let rig = try await makeActivityRig()
        let now = Date()
        let oldest = rig.host.add(instance: "https://yak.example.com", thread: "a0000001", startedAt: now.addingTimeInterval(-300))
        rig.host.add(instance: "https://yak.example.com", thread: "a0000002", startedAt: now.addingTimeInterval(-200))
        rig.host.add(instance: "https://other.example.com", thread: "a0000003", startedAt: now.addingTimeInterval(-100))
        await rig.controller.observe(
            threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(try show(), running: true))
        #expect(rig.host.ended == [oldest])
        #expect(rig.host.started.count == 1)
        #expect(rig.host.current.count == 3)
    }

    @Test func aShareRecipientGetsNone() async throws {
        let rig = try await makeActivityRig()
        await rig.controller.observe(
            threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(try show(canDrive: false), running: true))
        #expect(rig.host.started.isEmpty)
        #expect(await rig.transport.routes().filter { $0.hasPrefix("POST") }.isEmpty)
    }

    @Test func aBuildWithoutPushGetsNone() async throws {
        let rig = try await makeActivityRig()
        var home = rig.rig.home
        home.push = .otherBuild
        await rig.controller.observe(threadID: "d0e515ef", instance: home, snapshot: snapshot(try show(), running: true))
        #expect(rig.host.started.isEmpty)
    }

    @Test func liveActivitiesOffGetsNone() async throws {
        let rig = try await makeActivityRig()
        rig.host.isEnabled = false
        await rig.controller.observe(
            threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(try show(), running: true))
        #expect(rig.host.started.isEmpty)
    }

    @Test func aClosedThreadOrRefusalDoesNotStart() async throws {
        let rig = try await makeActivityRig()
        await rig.transport.on(
            "POST threads/d0e515ef/live-activity-channel", status: 409,
            body: #"{"error":"thread_closed","message":"This thread is closed."}"#)
        await rig.controller.observe(
            threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(try show(channel: nil), running: true))
        #expect(rig.host.started.isEmpty)
    }

    @Test func pushUnavailableStopsTrying() async throws {
        let rig = try await makeActivityRig()
        await rig.transport.on(
            "POST threads/d0e515ef/live-activity-channel", status: 409,
            body: #"{"error":"push_unavailable","message":"This Yak cannot send notifications."}"#)
        let thread = try show(channel: nil)
        await rig.controller.observe(threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(thread, running: true))
        await rig.controller.observe(threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(thread, running: false))
        await rig.controller.observe(threadID: "d0e515ef", instance: rig.rig.home, snapshot: snapshot(thread, running: true))
        let posts = await rig.transport.routes().filter { $0 == "POST threads/d0e515ef/live-activity-channel" }
        #expect(posts.count == 1)
    }

    @Test func aPushStartedActivityIsReported() async throws {
        let rig = try await makeActivityRig()
        await rig.controller.start()
        rig.host.add(instance: "https://yak.example.com", thread: "d0e515ef")
        rig.host.emitChange()
        #expect(await eventually { await rig.transport.bodies(of: "PUT live-activities").contains { $0.contains("d0e515ef") } })
    }

    @Test func aDuplicateFromAPushIsEndedAndReportedOnce() async throws {
        let rig = try await makeActivityRig()
        let alias = try JSONDecoder().decode(
            Me.self,
            from: Data(
                meJSON.replacingOccurrences(
                    of: "\"url\":\"https://yak.example.com\"", with: "\"url\":\"https://alias.example.com\"").utf8))
        try await rig.rig.store.update(rig.rig.home.id, from: alias)
        let now = Date()
        rig.host.add(instance: "https://yak.example.com", thread: "d0e515ef", startedAt: now.addingTimeInterval(-60))
        let newer = rig.host.add(instance: "https://alias.example.com", thread: "d0e515ef", startedAt: now)
        await rig.controller.start()
        #expect(await eventually { rig.host.ended == [newer] })
        rig.host.emitChange()
        try await Task.sleep(for: .milliseconds(100))
        #expect(rig.host.ended == [newer])
        let reports = await rig.transport.bodies(of: "PUT live-activities").filter { $0.contains("d0e515ef") }
        #expect(reports.count == 1)
    }

    @Test func fourRemoteStartsAreCutToThree() async throws {
        let rig = try await makeActivityRig()
        let now = Date()
        let oldest = rig.host.add(instance: "https://yak.example.com", thread: "a0000001", startedAt: now.addingTimeInterval(-400))
        rig.host.add(instance: "https://yak.example.com", thread: "a0000002", startedAt: now.addingTimeInterval(-300))
        rig.host.add(instance: "https://other.example.com", thread: "a0000003", startedAt: now.addingTimeInterval(-200))
        rig.host.add(instance: "https://other.example.com", thread: "a0000004", startedAt: now.addingTimeInterval(-100))
        await rig.controller.start()
        #expect(await eventually { rig.host.ended == [oldest] })
        #expect(rig.host.current.count == 3)
        #expect(await eventually { await rig.transport.bodies(of: "PUT live-activities").count >= 2 })
    }

    @Test func pushToStartTokensAreRegistered() async throws {
        let rig = try await makeActivityRig()
        await rig.controller.start()
        rig.host.emitToken(Data(repeating: 0xab, count: 32))
        #expect(await eventually { await rig.transport.routes().contains("PUT push-tokens/live_activity_start") })
    }

    @Test func unpairEndsOnlyThatInstance() async throws {
        let rig = try await makeActivityRig()
        let home = rig.host.add(instance: "https://yak.example.com", thread: "a0000001")
        rig.host.add(instance: "https://other.example.com", thread: "a0000002")
        await rig.controller.endAll(instance: rig.rig.home)
        #expect(rig.host.ended == [home])
        #expect(rig.host.current.count == 1)
    }
}
