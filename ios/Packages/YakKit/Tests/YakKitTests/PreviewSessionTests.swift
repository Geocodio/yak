import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let threadID = "d0e515ef"
private let statusRoute = "GET threads/\(threadID)/preview/status"
private let warmRoute = "POST threads/\(threadID)/preview/warm"
private let heartbeatRoute = "POST threads/\(threadID)/preview/heartbeat"
private let restoreRoute = "POST threads/\(threadID)/preview/restore"

private struct Rig {
    let session: PreviewSession
    let transport: ScriptedTransport
    let clock: ManualClock
    let recorder: StreamRecorder<PreviewSnapshot>

    init(status: String) async throws {
        clock = ManualClock()
        transport = ScriptedTransport()
        await transport.on(statusRoute, try .scenario(200, status))
        await transport.on(warmRoute, .json(204, ""))
        await transport.on(heartbeatRoute, .json(204, ""))
        let client = APIClient(
            baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
            appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
        session = PreviewSession(threadID: threadID, client: client, clock: clock, allowsLoopbackHTTP: false)
        recorder = StreamRecorder(await session.snapshots())
    }

    /// Waits for the first status answer.
    @discardableResult
    func loaded() async throws -> PreviewSnapshot {
        try await recorder.next { $0.load == .ready }
    }

    func count(_ route: String) async -> Int { await transport.requests(on: route).count }

    /// Lets every task that is ready run.
    func settle() async { for _ in 0..<200 { await Task.yield() } }

    func answer(_ scenario: String) async throws {
        await transport.on(statusRoute, try .scenario(200, scenario))
    }
}

private func thread(
    blockedReason: String? = nil, sandboxState: String = "running", connection: ConnectionState = .live,
    turn: TurnChange? = nil
) -> ConversationSnapshot {
    ConversationSnapshot(
        turn: turn,
        thread: ThreadStateChange(
            status: .open, sandboxState: sandboxState, runnerState: "idle", blockedReason: blockedReason),
        connection: connection)
}

@Test func aTransitionalStatePollsAndWarms() async throws {
    let rig = try await Rig(status: "scenario-preview-starting")
    await rig.session.setVisible(true)
    try await rig.loaded()
    await rig.transport.waitForRequests(1, on: warmRoute)
    await rig.clock.waitForSleepers(2)
    await rig.clock.advance(by: .seconds(10))
    #expect(await rig.count(statusRoute) == 6)
    #expect(await rig.count(warmRoute) == 2)
    #expect(await rig.count(heartbeatRoute) == 0)
}

@Test func readyHeartbeatsAndDoesNotPoll() async throws {
    let rig = try await Rig(status: "scenario-preview-ready")
    await rig.session.threadChanged(thread())
    await rig.session.setVisible(true)
    try await rig.loaded()
    await rig.transport.waitForRequests(1, on: heartbeatRoute)
    await rig.clock.waitForSleepers(1)
    await rig.clock.advance(by: .seconds(60))
    #expect(await rig.count(statusRoute) == 1)
    #expect(await rig.count(heartbeatRoute) == 3)
    #expect(await rig.count(warmRoute) == 0)
}

@Test func aDownSocketPollsEvery15Seconds() async throws {
    let rig = try await Rig(status: "scenario-preview-ready")
    await rig.session.threadChanged(thread(connection: .reconnecting))
    await rig.session.setVisible(true)
    try await rig.loaded()
    await rig.clock.waitForSleepers(2)
    await rig.clock.advance(by: .seconds(30))
    #expect(await rig.count(statusRoute) == 3)
}

@Test func aHiddenPaneSendsNothing() async throws {
    let rig = try await Rig(status: "scenario-preview-starting")
    await rig.session.setVisible(true)
    try await rig.loaded()
    await rig.transport.waitForRequests(1, on: warmRoute)
    await rig.session.setVisible(false)
    await rig.settle()
    let statuses = await rig.count(statusRoute)
    let warms = await rig.count(warmRoute)
    await rig.clock.advance(by: .seconds(60))
    #expect(await rig.count(statusRoute) == statuses)
    #expect(await rig.count(warmRoute) == warms)
    await rig.session.setVisible(true)
    await rig.transport.waitForRequests(statuses + 1, on: statusRoute)
}

@Test func otherStatesWaitForEvents() async throws {
    let rig = try await Rig(status: "scenario-preview-failing")
    await rig.session.threadChanged(thread())
    await rig.session.setVisible(true)
    try await rig.loaded()
    await rig.settle()
    await rig.clock.advance(by: .seconds(60))
    #expect(await rig.count(statusRoute) == 1)
    #expect(await rig.count(warmRoute) == 0)
    #expect(await rig.count(heartbeatRoute) == 0)
    await rig.session.threadChanged(thread(sandboxState: "paused"))
    await rig.transport.waitForRequests(2, on: statusRoute)
    await rig.session.threadChanged(thread(sandboxState: "paused"))
    await rig.settle()
    #expect(await rig.count(statusRoute) == 2)
}

@Test func aTurnRefetchesOnlyWhileASetupRuns() async throws {
    let turn = TurnChange(turnId: LenientID("1"), state: .running)
    let fixing = try await Rig(status: "scenario-preview-fixing")
    await fixing.session.threadChanged(thread())
    await fixing.session.setVisible(true)
    try await fixing.loaded()
    await fixing.session.threadChanged(thread(turn: turn))
    await fixing.transport.waitForRequests(2, on: statusRoute)

    let failing = try await Rig(status: "scenario-preview-failing")
    await failing.session.threadChanged(thread())
    await failing.session.setVisible(true)
    try await failing.loaded()
    await failing.session.threadChanged(thread(turn: turn))
    await failing.settle()
    #expect(await failing.count(statusRoute) == 1)
}

@Test func loadStates() async throws {
    let rig = try await Rig(status: "scenario-preview-ready")
    await rig.transport.hold(statusRoute)
    await rig.session.setVisible(true)
    await rig.clock.waitForSleepers(1)
    let load = await rig.recorder.values.last?.load
    #expect(load == .loading)
    await rig.clock.advance(by: .seconds(8))
    _ = try await rig.recorder.next { $0.load == .slow }
    await rig.transport.release(statusRoute)
    try await rig.loaded()
}

@Test func aMissingThreadFailsTheLoad() async throws {
    let rig = try await Rig(status: "scenario-preview-ready")
    await rig.transport.on(statusRoute, .json(404, #"{"error":"not_found","message":"Not found."}"#))
    await rig.session.setVisible(true)
    _ = try await rig.recorder.next { $0.load == .failed(.notFound) }
}

@Test func aNetworkErrorFailsTheLoadAndRetriesAfterFiveSeconds() async throws {
    let rig = try await Rig(status: "scenario-preview-ready")
    await rig.transport.on(statusRoute, .failure(.notConnectedToInternet))
    await rig.session.setVisible(true)
    _ = try await rig.recorder.next { $0.load == .failed(.offline) }
    await rig.clock.waitForSleepers(1)
    await rig.clock.advance(by: .seconds(5))
    #expect(await rig.count(statusRoute) == 2)
}

@Test func aFailureKeepsTheLastStatus() async throws {
    let rig = try await Rig(status: "scenario-preview-ready")
    await rig.session.setVisible(true)
    try await rig.loaded()
    await rig.transport.on(statusRoute, .failure(.notConnectedToInternet))
    await rig.session.refresh()
    var snapshot = try await rig.recorder.next { $0.isOffline }
    #expect(snapshot.status?.state == .ready && snapshot.load == .ready)
    await rig.transport.on(statusRoute, .json(500, #"{"error":"server_error","message":"m"}"#))
    await rig.session.refresh()
    snapshot = try await rig.recorder.next { $0.problem != nil }
    #expect(snapshot.problem == .server(code: "server_error", message: "m") && !snapshot.isOffline)
    #expect(snapshot.status?.state == .ready)
    try await rig.answer("scenario-preview-ready")
    await rig.session.refresh()
    snapshot = try await rig.recorder.next { $0.problem == nil && !$0.isOffline }
    #expect(snapshot.load == .ready)
}

@Test func tryAgainShowsStartingAndWarms() async throws {
    let rig = try await Rig(status: "scenario-preview-failing")
    await rig.session.setVisible(true)
    try await rig.loaded()
    await rig.session.tryAgain()
    _ = try await rig.recorder.next { $0.status?.state == .startingDevServer }
    await rig.transport.waitForRequests(1, on: warmRoute)
    await rig.clock.waitForSleepers(2)
    await rig.clock.advance(by: .seconds(2))
    #expect(await rig.count(warmRoute) == 1)
    #expect(await rig.count(statusRoute) == 2)
}

@Test func restoreTracksUntilTheStateMoves() async throws {
    let rig = try await Rig(status: "scenario-preview-destroyed")
    await rig.transport.on(restoreRoute, try .scenario(202, "scenario-preview-restore"))
    await rig.session.setVisible(true)
    try await rig.loaded()
    #expect(await rig.session.restore() == .done)
    _ = try await rig.recorder.next { $0.isRestoring }
    await rig.clock.waitForSleepers(2)
    await rig.clock.advance(by: .seconds(4))
    #expect(await rig.count(statusRoute) == 3)
    try await rig.answer("scenario-preview-waking")
    await rig.clock.advance(by: .seconds(2))
    _ = try await rig.recorder.next { !$0.isRestoring && $0.status?.state == .wakingSandbox }
}

@Test func restoreEndsWhenTheBlockedReasonChanges() async throws {
    let rig = try await Rig(status: "scenario-preview-destroyed")
    await rig.transport.on(restoreRoute, try .scenario(202, "scenario-preview-restore"))
    await rig.session.threadChanged(thread())
    await rig.session.setVisible(true)
    try await rig.loaded()
    _ = await rig.session.restore()
    _ = try await rig.recorder.next { $0.isRestoring }
    await rig.session.threadChanged(thread(blockedReason: "restore_failed"))
    _ = try await rig.recorder.next { !$0.isRestoring }
}

@Test func restoreGivesUpAfterTenMinutes() async throws {
    let rig = try await Rig(status: "scenario-preview-destroyed")
    await rig.transport.on(restoreRoute, try .scenario(202, "scenario-preview-restore"))
    await rig.session.setVisible(true)
    try await rig.loaded()
    _ = await rig.session.restore()
    await rig.clock.waitForSleepers(2)
    await rig.clock.advance(by: .seconds(600))
    let snapshot = try await rig.recorder.next { !$0.isRestoring && $0.restoreTookTooLong }
    #expect(snapshot.status?.state == .destroyed)
    _ = await rig.session.restore()
    _ = try await rig.recorder.next { $0.isRestoring && !$0.restoreTookTooLong }
}

@Test func aFinishedRestoreAnswersDone() async throws {
    let rig = try await Rig(status: "scenario-preview-destroyed")
    await rig.transport.on(restoreRoute, .json(409, #"{"error":"nothing_to_restore","message":"Nothing to restore."}"#))
    await rig.session.setVisible(true)
    try await rig.loaded()
    #expect(await rig.session.restore() == .done)
    #expect(await rig.count(statusRoute) == 2)
    let isRestoring = await rig.recorder.values.last?.isRestoring
    #expect(isRestoring == false)
}

@Test func refusedRestoresRefreshTheStatus() async throws {
    let rig = try await Rig(status: "scenario-preview-destroyed")
    await rig.transport.on(
        restoreRoute, .json(409, #"{"error":"thread_closed","message":"This thread is closed."}"#))
    await rig.session.setVisible(true)
    try await rig.loaded()
    #expect(
        await rig.session.restore()
            == .refused(code: "thread_closed", message: "This thread is closed.", fieldErrors: [:]))
    #expect(await rig.count(statusRoute) == 2)
    await rig.transport.on(restoreRoute, .json(403, #"{"error":"forbidden","message":"No."}"#))
    guard case .refused(let code, _, _) = await rig.session.restore() else {
        Issue.record("expected a refusal")
        return
    }
    let isRestoring = await rig.recorder.values.last?.isRestoring
    #expect(code == "forbidden" && isRestoring == false)
}

@Test func aFailedRestoreNeitherWarmsNorPolls() async throws {
    let rig = try await Rig(status: "scenario-preview-starting")
    await rig.session.threadChanged(thread(blockedReason: "restore_failed"))
    await rig.session.setVisible(true)
    try await rig.loaded()
    await rig.settle()
    await rig.clock.advance(by: .seconds(60))
    #expect(await rig.count(statusRoute) == 1)
    #expect(await rig.count(warmRoute) == 0)
    await rig.session.threadChanged(thread(blockedReason: nil))
    await rig.transport.waitForRequests(1, on: warmRoute)
    await rig.clock.waitForSleepers(2)
    let statuses = await rig.count(statusRoute)
    await rig.clock.advance(by: .seconds(2))
    #expect(await rig.count(statusRoute) == statuses + 1)
}

@Test func theLastPreviewURLIsKept() async throws {
    let rig = try await Rig(status: "scenario-preview-ready")
    await rig.session.setVisible(true)
    try await rig.loaded()
    await rig.transport.on(
        statusRoute,
        .json(
            200,
            String(
                decoding: try Scenarios.data(
                    "scenario-preview-ready", setting: ["state"], toJSON: "\"hibernating\""
                )
                .withURLNull(), as: UTF8.self)))
    await rig.session.refresh()
    let snapshot = try await rig.recorder.next { $0.status?.state.rawValue == "hibernating" }
    #expect(snapshot.lastPreviewURL == "https://t-p7m4q2hx9z.yak.example.test/")
    #expect(snapshot.status?.url == nil)

    let starting = try await Rig(status: "scenario-preview-starting")
    await starting.session.setVisible(true)
    let seen = try await starting.loaded()
    #expect(seen.lastPreviewURL == nil)
}

@Test func aRevokedTokenStopsTheSession() async throws {
    let rig = try await Rig(status: "scenario-preview-ready")
    await rig.transport.on(statusRoute, .json(401, #"{"error":"device_revoked","message":"Revoked."}"#))
    await rig.session.setVisible(true)
    _ = try await rig.recorder.next { $0.hasEnded }
    await rig.settle()
    let requests = await rig.transport.requests.count
    await rig.clock.advance(by: .seconds(60))
    #expect(await rig.transport.requests.count == requests)
}

@Test func closeStopsEverything() async throws {
    let rig = try await Rig(status: "scenario-preview-starting")
    await rig.session.setVisible(true)
    try await rig.loaded()
    await rig.transport.waitForRequests(1, on: warmRoute)
    await rig.session.close()
    await rig.settle()
    let requests = await rig.transport.requests.count
    await rig.clock.advance(by: .seconds(60))
    await rig.session.setVisible(true)
    await rig.settle()
    #expect(await rig.transport.requests.count == requests)
}

extension Data {
    /// The same JSON object with `url` set to null.
    fileprivate func withURLNull() throws -> Data {
        var object = try #require(try JSONSerialization.jsonObject(with: self) as? [String: Any])
        object["url"] = NSNull()
        return try JSONSerialization.data(withJSONObject: object)
    }
}

@Test func theThreadDetailOfTheShowWinsOverTheStreamedState() async throws {
    let rig = try await Rig(status: "scenario-preview-failing")
    let base = try decoded(ThreadShow.self, "scenario-thread-show")
    let detail = ThreadDetail(
        id: base.thread.id, name: base.thread.name, title: base.thread.title, repo: base.thread.repo,
        kind: base.thread.kind, status: base.thread.status, visibility: base.thread.visibility,
        sandboxState: "destroyed", runnerState: base.thread.runnerState, blockedReason: "restore_failed",
        pullRequestCount: 0, isOwner: true, isStarred: false)
    let show = ThreadShow(
        thread: detail, canDrive: true, messages: [], composer: base.composer, panes: base.panes, latestSequence: 1)
    await rig.session.setVisible(true)
    await rig.session.threadChanged(ConversationSnapshot(show: show, thread: thread().thread))
    let seen = try await rig.recorder.next { $0.blockedReason == "restore_failed" }
    #expect(seen.sandboxState == "destroyed")
}
