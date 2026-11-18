import Foundation
import Testing

@testable import YakKit

private let threadID = "d0e515ef"

private func showData(latestSequence: Int) throws -> Data {
    try Scenarios.data("scenario-thread-show", setting: ["latestSequence"], toJSON: String(latestSequence))
}

private func showResult(latestSequence: Int) throws -> FakeTransport.Result {
    .json(200, String(decoding: try showData(latestSequence: latestSequence), as: UTF8.self))
}

private func assistantText(_ sequence: Int) -> ThreadEvent {
    ThreadEvent(
        sequence: sequence, type: "entry", createdAt: "2026-11-15T09:00:00Z",
        data: .object([
            "role": .string("assistant"), "messageId": .string("msg_\(sequence)"),
            "blocks": .array([.object(["type": .string("text"), "text": .string("Step \(sequence)")])]),
        ]))
}

private struct Rig {
    let engine: ThreadEngine
    let transport: ScriptedTransport
    let clock: ManualClock
    let cache: Cache
    let server: EventServer
}

private func makeRig(
    server: EventServer, show: FakeTransport.Result?, frameInterval: Duration = .zero, cache: Cache? = nil,
    clock: ManualClock = ManualClock(), openNow: Bool = true
) async throws -> Rig {
    let transport = ScriptedTransport()
    if let show { await transport.on("GET threads/\(threadID)", show) }
    await transport.on("POST threads/\(threadID)/seen", .json(200, "{}"))
    await server.install(on: transport)
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let cache = cache ?? Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let engine = ThreadEngine(
        threadID: threadID, client: client, cache: cache, clock: clock, frameInterval: frameInterval)
    if openNow { await engine.open() }
    return Rig(engine: engine, transport: transport, clock: clock, cache: cache, server: server)
}

private func openedEngine(server: EventServer, show: Data) async throws -> Rig {
    let rig = try await makeRig(
        server: server, show: .json(200, String(decoding: show, as: UTF8.self)))
    let recorder = StreamRecorder(await rig.engine.snapshots())
    _ = try await recorder.next { $0.load == .ready && !$0.isShowingCachedCopy }
    return rig
}

private func eventEntries(_ snapshot: ConversationSnapshot) -> [ConversationEntry] {
    snapshot.entries.filter { if case .event = $0 { true } else { false } }
}

private func settled(_ engine: ThreadEngine, at sequence: Int = 14) async throws -> ConversationSnapshot {
    let recorder = StreamRecorder(await engine.snapshots())
    return try await recorder.next { $0.lastSequence == sequence && $0.load == .ready }
}

@Test func aBrokenStreamEndsInTheSameConversation() async throws {
    let list = try EventList.load("events/simple-turn")
    func run(_ socket: [ThreadEvent]) async throws -> ConversationSnapshot {
        let server = EventServer(threadID: threadID, events: list.stored)
        await server.publish(through: 5)
        let rig = try await openedEngine(server: server, show: showData(latestSequence: 5))
        await server.publish(through: 14)
        for event in socket { await rig.engine.receive(.event(event)) }
        return try await settled(rig.engine)
    }
    let clean = try await run(list.events.filter { $0.sequence > 5 })
    var broken = list.events.filter { $0.sequence > 5 && $0.sequence != 6 && $0.sequence != 7 }
    let ten = try #require(broken.firstIndex { $0.sequence == 10 })
    let eleven = try #require(broken.firstIndex { $0.sequence == 11 })
    broken.swapAt(ten, eleven)
    let result = try await run(broken.flatMap { [$0, $0] })
    #expect(result.entries == clean.entries)
    #expect(result.entries.count == 4 && result.lastSequence == 14)
}

@Test func aLongCatchUpPagesUntilDone() async throws {
    let server = EventServer(threadID: threadID, events: (1...1301).map(assistantText))
    await server.publish(through: 100)
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 100))
    await server.publish(through: 1301)
    await rig.engine.receive(.event(assistantText(1301)))
    let snapshot = try await settled(rig.engine, at: 1301)
    #expect(await server.requestedAfters.suffix(3) == [100, 600, 1100])
    #expect(eventEntries(snapshot).count == 1301)
    #expect(Set(eventEntries(snapshot).map(\.id)).count == 1301)
}

@Test func catchUpsRunOneAtATime() async throws {
    let server = EventServer(threadID: threadID, events: (1...10).map(assistantText))
    await server.publish(through: 2)
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 2))
    let route = "GET threads/\(threadID)/events"
    let before = await rig.transport.requests(on: route).count
    await rig.transport.hold(route)
    await server.publish(through: 10)
    for sequence in [5, 6, 7] { await rig.engine.receive(.event(assistantText(sequence))) }
    await rig.transport.waitForRequests(before + 1, on: route)
    #expect(await rig.transport.requests(on: route).count == before + 1)
    await rig.transport.release(route)
    await rig.transport.waitForRequests(before + 2, on: route)
    _ = try await settled(rig.engine, at: 10)
    for _ in 0..<50 { await Task.yield() }
    #expect(await rig.transport.requests(on: route).count == before + 2)
}

@Test func fetchesAPointerBeforeApplyingIt() async throws {
    let list = try EventList.load("events/pointer")
    let server = EventServer(threadID: threadID, events: list.stored)
    let last = try #require(list.events.map(\.sequence).max())
    await server.publish(through: 0)
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 0))
    await server.publish(through: last)
    for item in list.broadcasts {
        switch item {
        case .event(let event): await rig.engine.receive(.event(event))
        case .pointer(let sequence): await rig.engine.receive(.pointer(sequence: sequence))
        }
    }
    let snapshot = try await settled(rig.engine, at: last)
    let outputs = snapshot.entries.compactMap { entry -> Int? in
        guard case .event(let item) = entry else { return nil }
        return item.steps.compactMap(\.output).map(\.count).max()
    }
    #expect(outputs.contains { $0 >= 9000 })
}

@Test func aFailedPointerFetchCatchesUp() async throws {
    let server = EventServer(threadID: threadID, events: (1...3).map(assistantText))
    await server.publish(through: 2)
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 2))
    await server.publish(through: 3)
    await rig.transport.on("GET threads/\(threadID)/events/3", .json(500, "{}"))
    await rig.engine.receive(.pointer(sequence: 3))
    _ = try await settled(rig.engine, at: 3)
    #expect(await server.requestedAfters == [0, 2])
}

@Test func showsTheCachedConversationAtOnce() async throws {
    let list = try EventList.load("events/simple-turn")
    let clock = ManualClock()
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await cache.saveThread(
        threadID, CachedThread(show: show, events: list.stored, lastSequence: 14, firstLoadedAfter: 0))
    let server = EventServer(threadID: threadID, events: list.stored)
    await server.publish(through: 14)
    let rig = try await makeRig(
        server: server, show: try showResult(latestSequence: 14), cache: cache, clock: clock, openNow: false)
    let route = "GET threads/\(threadID)"
    await rig.transport.hold(route)
    await rig.engine.open()
    let recorder = StreamRecorder(await rig.engine.snapshots())
    let first = try await recorder.next { $0.load == .ready }
    #expect(first.isShowingCachedCopy && first.entries.count == 4)
    await rig.transport.release(route)
    let confirmed = try await recorder.next { !$0.isShowingCachedCopy }
    #expect(confirmed.entries.count == 4)
    #expect(await server.requestedAfters == [14])
}

@Test func withoutACacheLoadsTheNewest500() async throws {
    let server = EventServer(threadID: threadID, events: (1...1800).map(assistantText))
    await server.publish(through: 1800)
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 1800))
    #expect(await server.requestedAfters.first == 1300)
    let snapshot = try await settled(rig.engine, at: 1800)
    #expect(snapshot.hasOlderHistory)
    let request = try #require(await rig.transport.requests(on: "GET threads/\(threadID)/events").first)
    #expect(request.url?.query?.contains("limit=500") == true)
}

@Test func aCacheTooFarBehindIsDropped() async throws {
    let clock = ManualClock()
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await cache.saveThread(
        threadID,
        CachedThread(show: show, events: (1...100).map(assistantText), lastSequence: 100, firstLoadedAfter: 0))
    let server = EventServer(threadID: threadID, events: (1...2500).map(assistantText))
    await server.publish(through: 2500)
    let rig = try await makeRig(server: server, show: try showResult(latestSequence: 2500), cache: cache, clock: clock)
    let snapshot = try await settled(rig.engine, at: 2500)
    #expect(await server.requestedAfters.first == 2000)
    #expect(eventEntries(snapshot).count == 500)
}

@Test func snapshotsAtMostOncePerFrame() async throws {
    let server = EventServer(threadID: threadID, events: [])
    let clock = ManualClock()
    let rig = try await makeRig(
        server: server, show: try showResult(latestSequence: 0), frameInterval: .milliseconds(16), clock: clock)
    let recorder = StreamRecorder(await rig.engine.snapshots())
    var steps = 0
    while !(await recorder.values.contains { $0.load == .ready }) {
        steps += 1
        #expect(steps < 5000, "the engine never became ready")
        if steps >= 5000 { return }
        await clock.advance(by: .milliseconds(16))
        await Task.yield()
    }
    let settledCount = await recorder.values.count
    let existingSleepers = clock.sleeperDeadlines.count
    for sequence in 1...50 {
        let delta = ThreadEvent(
            sequence: sequence, type: "delta", createdAt: "",
            data: .object(["blockKey": .string("msg_1:0"), "kind": .string("text"), "text": .string("a")]))
        await rig.engine.receive(.event(delta))
    }
    #expect(await recorder.values.count == settledCount)
    await clock.waitForSleepers(existingSleepers + 1)
    await clock.advance(by: .milliseconds(16))
    let snapshot = try await recorder.next { $0.lastSequence == 50 }
    #expect(await recorder.values.count == settledCount + 1)
    let streamed = snapshot.entries.compactMap { entry -> String? in
        guard case .event(let item) = entry, item.isStreaming else { return nil }
        return item.text
    }
    #expect(streamed == [String(repeating: "a", count: 50)])
}

@Test func aFailedOpenWithoutCacheIsAnError() async throws {
    let server = EventServer(threadID: threadID, events: [])
    let missing = try await makeRig(server: server, show: .json(404, #"{"error":"not_found","message":"Gone."}"#))
    let notFound = try await StreamRecorder(missing.engine.snapshots()).next { $0.load != .loading }
    #expect(notFound.load == .failed(.notFound))
    let offline = try await makeRig(server: server, show: .failure(.notConnectedToInternet))
    let snapshot = try await StreamRecorder(offline.engine.snapshots()).next { $0.load != .loading }
    #expect(snapshot.load == .failed(.offline) && snapshot.isOffline)
}

@Test func offlineWithACacheKeepsTheCopy() async throws {
    let list = try EventList.load("events/simple-turn")
    let clock = ManualClock()
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await cache.saveThread(
        threadID, CachedThread(show: show, events: list.stored, lastSequence: 14, firstLoadedAfter: 0))
    let server = EventServer(threadID: threadID, events: list.stored)
    let rig = try await makeRig(server: server, show: .failure(.notConnectedToInternet), cache: cache, clock: clock)
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next { $0.isOffline }
    #expect(snapshot.load == .ready && snapshot.isShowingCachedCopy && snapshot.entries.count == 4)
}

@Test func savesStoredEventsToTheCache() async throws {
    let list = try EventList.load("events/simple-turn")
    let server = EventServer(threadID: threadID, events: list.stored)
    await server.publish(through: 14)
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 14))
    await rig.engine.flush()
    let saved = try #require(await rig.cache.thread(threadID))
    #expect(saved.events.count == 11 && saved.lastSequence == 14)
}

@Test func clearNamesTheReplacementThread() async throws {
    let server = EventServer(threadID: threadID, events: [])
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 0))
    let delivery = OutboxDelivery(
        sendKey: UUID(), target: .thread(threadID), status: .sent, messageUUID: nil, threadID: "n3w7hr3d",
        text: "/clear", sentAt: rig.clock.now())
    await rig.engine.delivered(delivery)
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next { $0.replacementThreadID != nil }
    #expect(snapshot.replacementThreadID == "n3w7hr3d")
}

@Test func unknownEventTypesAdvanceTheSequence() async throws {
    let server = EventServer(threadID: threadID, events: [])
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 0))
    let before = await rig.transport.requests(on: "GET threads/\(threadID)/events").count
    let mystery = ThreadEvent(sequence: 1, type: "mystery", createdAt: "", data: .object([:]))
    await rig.engine.receive(.event(mystery))
    let snapshot = try await settled(rig.engine, at: 1)
    #expect(eventEntries(snapshot).isEmpty)
    #expect(await rig.transport.requests(on: "GET threads/\(threadID)/events").count == before)
}

@Test func aMalformedEventInAFullPageDoesNotSkipTheRest() async throws {
    let server = EventServer(threadID: threadID, events: (1...1301).map(assistantText))
    await server.publish(through: 100)
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 100))
    await server.sendMalformed([300])
    await server.publish(through: 1301)
    await rig.engine.receive(.event(assistantText(1301)))
    let snapshot = try await settled(rig.engine, at: 1301)
    #expect(await server.requestedAfters.suffix(3) == [100, 600, 1100])
    #expect(eventEntries(snapshot).count == 1300)
}

@Test func aCatchUpFailureKeepsTheConversationOnScreen() async throws {
    let server = EventServer(threadID: threadID, events: (1...3).map(assistantText))
    await server.publish(through: 2)
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 2))
    let route = "GET threads/\(threadID)/events"
    let before = await rig.transport.requests(on: route).count
    await rig.transport.on(route, .json(500, #"{"error":"server_error","message":"Secret detail."}"#))
    await rig.engine.receive(.subscribed)
    await rig.transport.waitForRequests(before + 1, on: route)
    let failed = try await StreamRecorder(rig.engine.snapshots()).next { $0.problem != nil }
    #expect(failed.load == .ready && failed.lastSequence == 2 && !failed.entries.isEmpty)
    #expect(failed.problem == .server(code: "server_error", message: "Secret detail."))
    #expect(!failed.isOffline)
}

@Test func aFailedShowKeepsTheCachedCopy() async throws {
    let list = try EventList.load("events/simple-turn")
    let clock = ManualClock()
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await cache.saveThread(
        threadID, CachedThread(show: show, events: list.stored, lastSequence: 14, firstLoadedAfter: 0))
    let server = EventServer(threadID: threadID, events: list.stored)
    let rig = try await makeRig(
        server: server, show: .json(500, #"{"error":"server_error","message":"Down."}"#), cache: cache,
        clock: clock)
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next { $0.problem != nil }
    #expect(snapshot.load == .ready && snapshot.isShowingCachedCopy && snapshot.entries.count == 4)
}

/// Blocks the first read after `gate()`, so a test can park `open()` inside the cache read.
private final class GatedFileStore: FileStore, @unchecked Sendable {
    let inner = InMemoryFileStore()
    let started = DispatchSemaphore(value: 0)
    let proceed = DispatchSemaphore(value: 0)
    private let lock = NSLock()
    private var gated = false

    func gate() { lock.withLock { gated = true } }

    func read(_ path: String) throws(FileStoreError) -> Data? {
        let isBlocking = lock.withLock { () -> Bool in
            defer { gated = false }
            return gated
        }
        if isBlocking {
            started.signal()
            proceed.wait()
        }
        return try inner.read(path)
    }
    func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        try inner.write(data, to: path, protection: protection)
    }
    func remove(_ path: String) throws(FileStoreError) { try inner.remove(path) }
    func list(_ folder: String) throws(FileStoreError) -> [String] { try inner.list(folder) }
}

@Test func inputDuringTheCacheReadWaitsForTheRestore() async throws {
    let list = try EventList.load("events/simple-turn")
    let clock = ManualClock()
    let files = GatedFileStore()
    let cache = Cache(instance: UUID(), files: files, clock: clock)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await cache.saveThread(
        threadID, CachedThread(show: show, events: list.stored, lastSequence: 14, firstLoadedAfter: 0))
    let server = EventServer(threadID: threadID, events: list.stored)
    await server.publish(through: 14)
    let rig = try await makeRig(
        server: server, show: try showResult(latestSequence: 14), cache: cache, clock: clock, openNow: false)
    files.gate()
    let opening = Task { await rig.engine.open() }
    await withCheckedContinuation { continuation in
        DispatchQueue.global().async {
            files.started.wait()
            continuation.resume()
        }
    }
    await rig.engine.receive(.subscribed)
    await rig.engine.requestCatchUp()
    files.proceed.signal()
    await opening.value
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next {
        $0.load == .ready && !$0.isShowingCachedCopy
    }
    #expect(snapshot.entries.count == 4 && !snapshot.isShowingCachedCopy)
    #expect(await server.requestedAfters == [14])
    #expect(await rig.transport.requests(on: "GET threads/\(threadID)").count == 1)
}

// MARK: Realtime pins for the work cards

private func threadEvent(_ sequence: Int, showKey: String?) -> ThreadEvent {
    var data: [String: JSONValue] = [
        "status": .string("open"), "sandboxState": .string("ready"), "runnerState": .string("idle"),
    ]
    if let showKey { data["showKey"] = .string(showKey) }
    return ThreadEvent(sequence: sequence, type: "thread", createdAt: "2026-11-15T09:00:00Z", data: .object(data))
}

private func turnEvent(_ sequence: Int) -> ThreadEvent {
    ThreadEvent(
        sequence: sequence, type: "turn", createdAt: "2026-11-15T09:00:00Z",
        data: .object(["turnId": .number(7), "state": .string("completed")]))
}

/// An engine open on a show at sequence 14 whose next show carries `card`.
private func cardRig() async throws -> (rig: Rig, recorder: StreamRecorder<ConversationSnapshot>) {
    let server = EventServer(threadID: threadID, events: [])
    await server.publish(through: 14)
    var object = try #require(
        JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-show")) as? [String: Any])
    object["latestSequence"] = 16
    object["card"] = "stopped"
    let withCard = String(decoding: try JSONSerialization.data(withJSONObject: object), as: UTF8.self)
    let rig = try await makeRig(server: server, show: nil)
    await rig.transport.on(
        "GET threads/\(threadID)", try showResult(latestSequence: 14), .json(200, withCard))
    let recorder = StreamRecorder(await rig.engine.snapshots())
    _ = try await recorder.next { $0.load == .ready && !$0.isShowingCachedCopy && $0.lastSequence == 14 }
    return (rig, recorder)
}

/// Waits for the refresh to be waiting out its settle time, then lets that time pass.
private func advancePastTheRefreshWait(_ rig: Rig) async {
    let deadline = rig.clock.offset + ThreadEngine.refreshSettle
    await waitUntil { rig.clock.sleeperDeadlines.contains(deadline) }
    await rig.clock.advance(by: ThreadEngine.refreshSettle)
}

@Test func aThreadEventWithAShowKeyRefreshesTheShow() async throws {
    let (rig, recorder) = try await cardRig()
    let before = await rig.transport.requests(on: "GET threads/\(threadID)").count
    await rig.engine.receive(.event(threadEvent(15, showKey: "abcdef123456")))
    await advancePastTheRefreshWait(rig)
    let snapshot = try await recorder.next { $0.show?.card == .stopped }
    #expect(snapshot.show?.latestSequence == 16)
    #expect(await rig.transport.requests(on: "GET threads/\(threadID)").count == before + 1)
}

@Test func aThreadEventWithoutAShowKeyRefreshesTheShowToo() async throws {
    let (rig, recorder) = try await cardRig()
    await rig.engine.receive(.event(threadEvent(15, showKey: nil)))
    await advancePastTheRefreshWait(rig)
    _ = try await recorder.next { $0.show?.card == .stopped }
}

@Test(arguments: [true, false])
func theTurnAndThreadEventsInEitherOrderRefreshOnce(turnFirst: Bool) async throws {
    let (rig, recorder) = try await cardRig()
    let before = await rig.transport.requests(on: "GET threads/\(threadID)").count
    let events =
        turnFirst
        ? [turnEvent(15), threadEvent(16, showKey: "abcdef123456")]
        : [threadEvent(15, showKey: "abcdef123456"), turnEvent(16)]
    for event in events { await rig.engine.receive(.event(event)) }
    await advancePastTheRefreshWait(rig)
    _ = try await recorder.next { $0.show?.card == .stopped }
    // Nothing else is scheduled: a second wait would have no sleeper to wake.
    await rig.clock.advance(by: ThreadEngine.refreshSettle)
    #expect(await rig.transport.requests(on: "GET threads/\(threadID)").count == before + 1)
}

@Test func theSnapshotCarriesTheShowsMilestonesInPlace() async throws {
    let server = EventServer(threadID: threadID, events: [])
    await server.publish(through: 14)
    var object = try #require(
        JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-show")) as? [String: Any])
    object["milestones"] = [
        ["key": "pushed", "label": "Pushed", "tone": "ok", "url": NSNull(), "runId": "9", "afterSequence": 14]
    ]
    let rig = try await openedEngine(server: server, show: JSONSerialization.data(withJSONObject: object))
    let snapshot = try await settled(rig.engine)
    #expect(snapshot.entries.contains { $0.id == "milestone:9:pushed:14" })
}

// MARK: Send answers with a notice

private func hasDeliveredRow(_ snapshot: ConversationSnapshot) -> Bool {
    snapshot.entries.contains { if case .delivered = $0 { true } else { false } }
}

@Test func aStoppedReplyShowsTheNoticeAndDropsTheDelivery() async throws {
    let server = EventServer(threadID: threadID, events: [])
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 0))
    let sendKey = UUID()
    await rig.engine.delivered(
        OutboxDelivery(
            sendKey: sendKey, target: .thread(threadID), status: .sent, messageUUID: nil, threadID: threadID,
            text: "Try the other branch", sentAt: rig.clock.now(), notice: "Trying again with your note."))
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next { $0.lastSendNotice != nil }
    #expect(snapshot.lastSendNotice == SendNotice(id: sendKey, text: "Trying again with your note."))
    #expect(!hasDeliveredRow(snapshot) && snapshot.replacementThreadID == nil)
}

@Test func aSecondNoticeReplacesTheFirstAndARepeatOfOneChangesNothing() async throws {
    let server = EventServer(threadID: threadID, events: [])
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 0))
    func delivery(_ key: UUID, _ notice: String) -> OutboxDelivery {
        OutboxDelivery(
            sendKey: key, target: .thread(threadID), status: .sent, messageUUID: nil, threadID: threadID, text: "x",
            sentAt: rig.clock.now(), notice: notice)
    }
    let (first, second) = (UUID(), UUID())
    await rig.engine.delivered(delivery(first, "One."))
    await rig.engine.delivered(delivery(first, "One again."))
    await rig.engine.delivered(delivery(second, "Two."))
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next { $0.lastSendNotice?.id == second }
    #expect(snapshot.lastSendNotice?.text == "Two.")
}

@Test func aSendWithARowAndANoticeKeepsItsDelivery() async throws {
    let server = EventServer(threadID: threadID, events: [])
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 0))
    await rig.engine.delivered(
        OutboxDelivery(
            sendKey: UUID(), target: .thread(threadID), status: .queued, messageUUID: "u-1", threadID: threadID,
            text: "x", sentAt: rig.clock.now(), notice: "Yak is already on it. Your note is included."))
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next { $0.lastSendNotice != nil }
    #expect(hasDeliveredRow(snapshot))
}

@Test func aLegacySendOpensTheContinuingThread() async throws {
    let server = EventServer(threadID: threadID, events: [])
    let rig = try await openedEngine(server: server, show: showData(latestSequence: 0))
    await rig.engine.delivered(
        OutboxDelivery(
            sendKey: UUID(), target: .thread(threadID), status: .sent, messageUUID: nil, threadID: "c0n71nue",
            text: "Carry on", sentAt: rig.clock.now()))
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next { $0.replacementThreadID != nil }
    #expect(snapshot.replacementThreadID == "c0n71nue" && snapshot.lastSendNotice == nil)
}
