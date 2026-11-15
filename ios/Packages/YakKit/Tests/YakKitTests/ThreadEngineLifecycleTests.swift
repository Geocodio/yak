import Foundation
import Testing

@testable import YakKit

private let threadID = "d0e515ef"
private let showRoute = "GET threads/\(threadID)"
private let eventsRoute = "GET threads/\(threadID)/events"
private let seenRoute = "POST threads/\(threadID)/seen"

private func showResult(latestSequence: Int) throws -> FakeTransport.Result {
    let data = try Scenarios.data("scenario-thread-show", setting: ["latestSequence"], toJSON: String(latestSequence))
    return .json(200, String(decoding: data, as: UTF8.self))
}

private func assistantText(_ sequence: Int) -> ThreadEvent {
    ThreadEvent(
        sequence: sequence, type: "entry", createdAt: "2026-11-15T09:00:00Z",
        data: .object([
            "role": .string("assistant"), "messageId": .string("msg_\(sequence)"),
            "blocks": .array([.object(["type": .string("text"), "text": .string("Step \(sequence)")])]),
        ]))
}

private func turnEvent(_ sequence: Int) -> ThreadEvent {
    ThreadEvent(
        sequence: sequence, type: "turn", createdAt: "2026-11-15T09:00:00Z",
        data: .object(["turnId": .number(43), "state": .string("running")]))
}

private struct Rig {
    let engine: ThreadEngine
    let transport: ScriptedTransport
    let clock: ManualClock
    let server: EventServer
}

private func makeRig(
    server: EventServer, latestSequence: Int = 0, show: FakeTransport.Result? = nil, openNow: Bool = true
) async throws -> Rig {
    let clock = ManualClock()
    let transport = ScriptedTransport()
    await transport.on(showRoute, try show ?? showResult(latestSequence: latestSequence))
    await transport.on(seenRoute, .json(200, "{}"))
    await server.install(on: transport)
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let engine = ThreadEngine(threadID: threadID, client: client, cache: cache, clock: clock, frameInterval: .zero)
    if openNow {
        await engine.open()
        let recorder = StreamRecorder(await engine.snapshots())
        _ = await recorder.next { $0.load == .ready && !$0.isShowingCachedCopy }
    }
    return Rig(engine: engine, transport: transport, clock: clock, server: server)
}

private func eventEntries(_ snapshot: ConversationSnapshot) -> [ConversationEntry] {
    snapshot.entries.filter { if case .event = $0 { true } else { false } }
}

private func emptyServer() -> EventServer { EventServer(threadID: threadID, events: []) }

@Test func pollsEveryThreeSecondsWhileVisibleAndDown() async throws {
    let rig = try await makeRig(server: emptyServer())
    let start = await rig.transport.requests(on: eventsRoute).count
    await rig.engine.receive(.connection(.reconnecting))
    await rig.engine.setVisible(true)
    await rig.transport.waitForRequests(start + 1, on: eventsRoute)
    for step in 1...2 {
        await rig.clock.waitForSleeper(endingAt: .seconds(3 * step))
        await rig.clock.advance(by: .seconds(3))
        await rig.transport.waitForRequests(start + 1 + step, on: eventsRoute)
    }
    await rig.clock.waitForSleeper(endingAt: .seconds(9))
    await rig.engine.receive(.connection(.live))
    await rig.engine.receive(.subscribed)
    await rig.transport.waitForRequests(start + 4, on: eventsRoute)
    #expect(!rig.clock.sleeperDeadlines.contains(.seconds(9)))
    await rig.clock.advance(by: .seconds(9))
    for _ in 0..<50 { await Task.yield() }
    #expect(await rig.transport.requests(on: eventsRoute).count == start + 4)
}

@Test func doesNotPollWhenHidden() async throws {
    let rig = try await makeRig(server: emptyServer())
    await rig.engine.setVisible(true)
    await rig.clock.waitForSleeper(endingAt: .seconds(3))
    await rig.engine.setVisible(false)
    #expect(!rig.clock.sleeperDeadlines.contains(.seconds(3)))
}

@Test func aFailedSubscriptionStartsPollingAgain() async throws {
    let rig = try await makeRig(server: emptyServer())
    await rig.engine.receive(.connection(.live))
    await rig.engine.receive(.subscribed)
    await rig.engine.setVisible(true)
    #expect(!rig.clock.sleeperDeadlines.contains(.seconds(3)))
    await rig.engine.receive(.subscriptionFailed(nil))
    await rig.clock.waitForSleeper(endingAt: .seconds(3))
}

@Test func pollsWithoutRealtime() async throws {
    let rig = try await makeRig(server: emptyServer())
    let start = await rig.transport.requests(on: eventsRoute).count
    await rig.engine.setVisible(true)
    for step in 1...3 {
        await rig.clock.waitForSleeper(endingAt: .seconds(3 * step))
        await rig.clock.advance(by: .seconds(3))
        await rig.transport.waitForRequests(start + 1 + step, on: eventsRoute)
    }
}

@Test func aTurnEventRefreshesTheShowOnce() async throws {
    let rig = try await makeRig(server: emptyServer())
    let thread = ThreadEvent(
        sequence: 2, type: "thread", createdAt: "", data: .object(["status": .string("running")]))
    let answer = try Scenarios.data(
        "scenario-thread-question", setting: ["composer", "model"], toJSON: #""answer-model""#)
    await rig.transport.on(showRoute, .json(200, String(decoding: answer, as: UTF8.self)))
    let recorder = StreamRecorder(await rig.engine.snapshots())
    await rig.engine.receive(.event(turnEvent(1)))
    await rig.clock.waitForSleeper(endingAt: .milliseconds(300))
    await rig.clock.advance(by: .milliseconds(100))
    await rig.engine.receive(.event(thread))
    await rig.clock.advance(by: .milliseconds(200))
    let snapshot = await recorder.next { $0.show?.question != nil }
    #expect(snapshot.lastSequence == 2)
    #expect(snapshot.show?.composer.model == "answer-model")
    for _ in 0..<50 { await Task.yield() }
    #expect(await rig.transport.requests(on: showRoute).count == 2)
}

@Test func aFailedRefreshRetries() async throws {
    let unavailable = FakeTransport.Result.json(503, #"{"error":"unavailable","message":"Try later."}"#)
    let rig = try await makeRig(server: emptyServer())
    await rig.transport.on(
        showRoute, unavailable, unavailable, unavailable, unavailable, try showResult(latestSequence: 5))
    let recorder = StreamRecorder(await rig.engine.snapshots())
    await rig.engine.receive(.event(turnEvent(1)))
    await rig.clock.waitForSleeper(endingAt: .milliseconds(300))
    await rig.clock.advance(by: .milliseconds(300))
    // The client itself tries a 503 four times before it gives up.
    await rig.transport.waitForRequests(5, on: showRoute)
    _ = await recorder.next { $0.isOffline }
    await rig.clock.waitForSleeper(endingAt: .milliseconds(3300))
    await rig.clock.advance(by: .seconds(3))
    await rig.transport.waitForRequests(6, on: showRoute)
    let snapshot = await recorder.next { $0.show?.latestSequence == 5 }
    #expect(snapshot.problem == nil && !snapshot.isOffline)
    await rig.clock.advance(by: .seconds(10))
    for _ in 0..<50 { await Task.yield() }
    #expect(await rig.transport.requests(on: showRoute).count == 6)
}

@Test func aNonRetryableRefreshFailureStopsTheRetries() async throws {
    let rig = try await makeRig(server: emptyServer())
    await rig.transport.on(showRoute, .json(404, #"{"error":"not_found","message":"Gone."}"#))
    let recorder = StreamRecorder(await rig.engine.snapshots())
    await rig.engine.receive(.event(turnEvent(1)))
    await rig.clock.waitForSleeper(endingAt: .milliseconds(300))
    await rig.clock.advance(by: .milliseconds(300))
    _ = await recorder.next { $0.problem == .notFound }
    await rig.clock.advance(by: .seconds(30))
    for _ in 0..<50 { await Task.yield() }
    #expect(await rig.transport.requests(on: showRoute).count == 2)
}

@Test func aReplayedClosedQuestionKeepsTheOpenOne() async throws {
    let closed = ThreadEvent(sequence: 3, type: "question", createdAt: "", data: .object(["open": .bool(false)]))
    let list = try EventList.load("events/question")
    let asked = try #require(list.stored.first { $0.sequence == 6 })
    let reopened = ThreadEvent(sequence: 7, type: asked.type, createdAt: asked.createdAt, data: asked.data)
    let server = EventServer(threadID: threadID, events: [closed, reopened])
    await server.publish(through: 8)
    let data = try Scenarios.data("scenario-thread-question", setting: ["latestSequence"], toJSON: "8")
    let rig = try await makeRig(
        server: server, show: .json(200, String(decoding: data, as: UTF8.self)))
    let snapshot = await StreamRecorder(rig.engine.snapshots()).next { $0.lastSequence == 8 }
    #expect(snapshot.show?.question != nil)
}

@Test func theQuestionEventLoadsTheQuestion() async throws {
    let list = try EventList.load("events/question")
    let server = EventServer(threadID: threadID, events: list.stored)
    let rig = try await makeRig(server: server)
    await rig.transport.on(showRoute, try .scenario(200, "scenario-thread-question"))
    let recorder = StreamRecorder(await rig.engine.snapshots())
    for event in list.events where event.sequence <= 6 { await rig.engine.receive(.event(event)) }
    await rig.clock.waitForSleeper(endingAt: .milliseconds(300))
    await rig.clock.advance(by: .milliseconds(300))
    let loaded = await recorder.next { $0.show?.question != nil }
    #expect(loaded.show?.question?.roundKey == "k3m9p2qa:on_failure,notify")
    let before = await rig.transport.requests(on: showRoute).count
    let closed = ThreadEvent(sequence: 7, type: "question", createdAt: "", data: .object(["open": .bool(false)]))
    await rig.engine.receive(.event(closed))
    let cleared = await recorder.next { $0.lastSequence == 7 }
    #expect(cleared.show?.question == nil)
    #expect(await rig.transport.requests(on: showRoute).count == before)
}

@Test func turnsSlowAfterEightSeconds() async throws {
    let rig = try await makeRig(server: emptyServer(), openNow: false)
    await rig.transport.hold(showRoute)
    await rig.engine.open()
    let recorder = StreamRecorder(await rig.engine.snapshots())
    _ = await recorder.next { $0.load == .loading }
    await rig.clock.waitForSleeper(endingAt: .seconds(8))
    await rig.clock.advance(by: .seconds(8))
    _ = await recorder.next { $0.load == .slow }
    await rig.transport.release(showRoute)
    _ = await recorder.next { $0.load == .ready }
    #expect(!rig.clock.sleeperDeadlines.contains(.seconds(8)))
}

@Test func aDroppedCacheLoadsSlowToo() async throws {
    let clock = ManualClock()
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await cache.saveThread(
        threadID,
        CachedThread(
            show: show, events: (1...100).map(assistantText), lastSequence: 100, firstLoadedAfter: 0))
    let server = EventServer(threadID: threadID, events: (1...2500).map(assistantText))
    await server.publish(through: 2500)
    await server.install(on: ScriptedTransport())
    let transport = ScriptedTransport()
    await transport.on(showRoute, try showResult(latestSequence: 2500))
    await transport.on(seenRoute, .json(200, "{}"))
    await server.install(on: transport)
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let engine = ThreadEngine(threadID: threadID, client: client, cache: cache, clock: clock, frameInterval: .zero)
    await transport.hold(eventsRoute)
    await engine.open()
    let recorder = StreamRecorder(await engine.snapshots())
    _ = await recorder.next { $0.load == .loading }
    await clock.waitForSleeper(endingAt: .seconds(8))
    await clock.advance(by: .seconds(8))
    _ = await recorder.next { $0.load == .slow }
    await transport.release(eventsRoute)
    _ = await recorder.next { $0.load == .ready }
}

@Test func aFailedLoadWithoutARetryStopsPolling() async throws {
    let rig = try await makeRig(
        server: emptyServer(), show: .json(404, #"{"error":"not_found","message":"Gone."}"#), openNow: false)
    await rig.engine.open()
    let recorder = StreamRecorder(await rig.engine.snapshots())
    _ = await recorder.next { $0.load == .failed(.notFound) }
    await rig.engine.setVisible(true)
    for _ in 0..<50 { await Task.yield() }
    #expect(!rig.clock.sleeperDeadlines.contains(.seconds(3)))
}

@Test func anOlderPageForADroppedWindowIsDiscarded() async throws {
    let clock = ManualClock()
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await cache.saveThread(
        threadID,
        CachedThread(
            show: show, events: (1001...1100).map(assistantText), lastSequence: 1100, firstLoadedAfter: 1000))
    let server = EventServer(threadID: threadID, events: (1...3500).map(assistantText))
    await server.publish(through: 3500)
    let transport = ScriptedTransport()
    await transport.on(showRoute, try showResult(latestSequence: 3500))
    await transport.on(seenRoute, .json(200, "{}"))
    await server.install(on: transport)
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let engine = ThreadEngine(threadID: threadID, client: client, cache: cache, clock: clock, frameInterval: .zero)
    await transport.hold(showRoute)
    await engine.open()
    let recorder = StreamRecorder(await engine.snapshots())
    _ = await recorder.next { $0.load == .ready && $0.isShowingCachedCopy }
    await transport.hold(eventsRoute)
    let older = Task { await engine.loadOlder() }
    await transport.waitForRequests(1, on: eventsRoute)
    await transport.release(showRoute)
    _ = await recorder.next { $0.load == .loading }
    await transport.waitForRequests(2, on: eventsRoute)
    await transport.release(eventsRoute)
    await older.value
    _ = await recorder.next { $0.lastSequence == 3500 }
    for _ in 0..<50 { await Task.yield() }
    let last = try #require(await recorder.values.last)
    #expect(eventEntries(last).count == 500 && !last.isLoadingOlder)
}

@Test func loadsOlderHistory() async throws {
    let server = EventServer(threadID: threadID, events: (1...1800).map(assistantText))
    await server.publish(through: 1800)
    let rig = try await makeRig(server: server, latestSequence: 1800)
    let recorder = StreamRecorder(await rig.engine.snapshots())
    let current = await recorder.next { $0.lastSequence == 1800 }
    #expect(current.hasOlderHistory && !current.isLoadingOlder)
    let before = await rig.transport.requests(on: eventsRoute).count
    await rig.transport.hold(eventsRoute)
    let first = Task { await rig.engine.loadOlder() }
    await rig.transport.waitForRequests(before + 1, on: eventsRoute)
    await rig.engine.loadOlder()
    #expect(await rig.transport.requests(on: eventsRoute).count == before + 1)
    await rig.transport.release(eventsRoute)
    await first.value
    let loaded = await recorder.next { !$0.isLoadingOlder && eventEntries($0).count == 1000 }
    #expect(await server.requestedAfters.last == 800)
    #expect(loaded.lastSequence == 1800 && loaded.hasOlderHistory)
    let sequences = eventEntries(loaded).compactMap { entry -> Int? in
        if case .event(let item) = entry { item.sequence } else { nil }
    }
    #expect(sequences == Array(801...1800))

    let limit = try #require(await rig.transport.requests(on: eventsRoute).last?.url?.query)
    #expect(limit.contains("after=800") && limit.contains("limit=500"))
    await rig.engine.loadOlder()
    #expect(await server.requestedAfters.last == 300)
}

@Test func aFailedHistoryLoadKeepsTheConversation() async throws {
    let server = EventServer(threadID: threadID, events: (1...1800).map(assistantText))
    await server.publish(through: 1800)
    let rig = try await makeRig(server: server, latestSequence: 1800)
    let recorder = StreamRecorder(await rig.engine.snapshots())
    _ = await recorder.next { $0.lastSequence == 1800 }
    await rig.transport.on(eventsRoute, .failure(.notConnectedToInternet))
    await rig.engine.loadOlder()
    let failed = await recorder.next { $0.isOffline && !$0.isLoadingOlder }
    #expect(eventEntries(failed).count == 500 && failed.hasOlderHistory)
}

@Test func noOlderHistoryAtTheStart() async throws {
    let rig = try await makeRig(server: emptyServer())
    let before = await rig.transport.requests(on: eventsRoute).count
    await rig.engine.loadOlder()
    #expect(await rig.transport.requests(on: eventsRoute).count == before)
    let snapshot = await StreamRecorder(rig.engine.snapshots()).next { $0.load == .ready }
    #expect(!snapshot.hasOlderHistory)
}

@Test func setVisibleMarksSeen() async throws {
    let rig = try await makeRig(server: emptyServer())
    await rig.transport.waitForRequests(1, on: seenRoute)
    await rig.engine.setVisible(true)
    await rig.transport.waitForRequests(2, on: seenRoute)
    await rig.engine.setVisible(true)
    for _ in 0..<50 { await Task.yield() }
    #expect(await rig.transport.requests(on: seenRoute).count == 2)
}
