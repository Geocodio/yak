import Foundation
import Testing

@testable import YakKit

private let threadID = "d0e515ef"
private let showRoute = "GET threads/\(threadID)"

private func showResult(latestSequence: Int) throws -> FakeTransport.Result {
    let data = try Scenarios.data(
        "scenario-thread-with-changes", setting: ["latestSequence"], toJSON: String(latestSequence))
    return .json(200, String(decoding: data, as: UTF8.self))
}

private struct Rig {
    let engine: ThreadEngine
    let transport: ScriptedTransport
    let clock: ManualClock
    let server: EventServer
}

/// Opens an engine whose show answers with `showSequence` and whose server holds `published` events.
private func openedEngine(showSequence: Int, published: Int) async throws -> Rig {
    let list = try EventList.load("events/scenario-diff-updated")
    let server = EventServer(threadID: threadID, events: list.stored)
    await server.publish(through: published)
    let transport = ScriptedTransport()
    await transport.on(showRoute, try showResult(latestSequence: showSequence))
    await transport.on("POST threads/\(threadID)/seen", .json(200, "{}"))
    await server.install(on: transport)
    let clock = ManualClock()
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let engine = ThreadEngine(
        threadID: threadID, client: client, cache: cache, clock: clock, frameInterval: .zero)
    await engine.open()
    _ = try await StreamRecorder(await engine.snapshots()).next {
        $0.load == .ready && !$0.isShowingCachedCopy && $0.lastSequence == published
    }
    return Rig(engine: engine, transport: transport, clock: clock, server: server)
}

private func settleRefresh(_ rig: Rig) async {
    await rig.clock.waitForSleeper(endingAt: rig.clock.offset + ThreadEngine.refreshSettle)
    await rig.clock.advance(by: ThreadEngine.refreshSettle)
    for _ in 0..<200 { await Task.yield() }
}

@Test func aLiveDiffUpdateRefreshesTheShowOnce() async throws {
    let rig = try await openedEngine(showSequence: 7, published: 7)
    let list = try EventList.load("events/scenario-diff-updated")
    let event = try #require(list.events.first { $0.sequence == 8 })
    await rig.engine.receive(.event(event))
    await settleRefresh(rig)
    #expect(await rig.transport.requests(on: showRoute).count == 2)
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next { $0.lastDiffUpdateSequence == 8 }
    #expect(snapshot.lastDiffUpdateSequence == 8)
}

@Test func aDiffUpdateInsideACatchUpPageCountsTheSame() async throws {
    // The page holds a show at 7; the catch-up page brings 8 with the turn and entry events beside it.
    let rig = try await openedEngine(showSequence: 7, published: 8)
    await settleRefresh(rig)
    #expect(await rig.transport.requests(on: showRoute).count == 2)
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next { $0.lastDiffUpdateSequence == 8 }
    #expect(snapshot.lastDiffUpdateSequence == 8)
}

@Test func anOlderOrRepeatedDiffUpdateChangesNothing() async throws {
    let rig = try await openedEngine(showSequence: 9, published: 7)
    let list = try EventList.load("events/scenario-diff-updated")
    let event = try #require(list.events.first { $0.sequence == 8 })
    await rig.engine.receive(.event(event))
    await rig.engine.receive(.event(event))
    await rig.clock.advance(by: ThreadEngine.refreshSettle)
    for _ in 0..<200 { await Task.yield() }
    #expect(await rig.transport.requests(on: showRoute).count == 1)
    let snapshot = try await StreamRecorder(rig.engine.snapshots()).next { $0.lastDiffUpdateSequence == 8 }
    #expect(snapshot.lastDiffUpdateSequence == 8)
}
