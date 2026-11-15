import Foundation
import Testing

@testable import YakKit

private let threadID = "d0e515ef"
private let diffRoute = "GET threads/\(threadID)/diff"
private func fileRoute(_ id: Int) -> String { "\(diffRoute)/files/\(id)" }
private let uploadKey = DiffFileKey(repo: "example/api", path: "app/Uploads/RetryUpload.php")
private let logoKey = DiffFileKey(repo: "example/api", path: "resources/logo.png")
private let seedKey = DiffFileKey(repo: "example/api", path: "database/seed.sql")

private struct Rig {
    let changes: ThreadChanges
    let transport: ScriptedTransport
    let clock: ManualClock
    let cache: Cache
}

private func makeRig(cache existing: Cache? = nil, clock: ManualClock = ManualClock()) async throws -> Rig {
    let transport = ScriptedTransport()
    await transport.on(diffRoute, try .scenario(200, "scenario-thread-diff"))
    await transport.on(prefix: diffRoute + "/files/") { _ in
        (try? .scenario(200, "scenario-diff-file")) ?? .json(500, "{}")
    }
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let cache = existing ?? Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let changes = ThreadChanges(threadID: threadID, client: client, cache: cache, clock: clock)
    return Rig(changes: changes, transport: transport, clock: clock, cache: cache)
}

private func recorder(_ rig: Rig) async -> StreamRecorder<ChangesSnapshot> {
    StreamRecorder(await rig.changes.snapshots())
}

private func scenarioWithIDs(shiftedBy offset: Int) throws -> FakeTransport.Result {
    var text = String(decoding: try Scenarios.data("scenario-thread-diff"), as: UTF8.self)
    for id in 501...504 { text = text.replacingOccurrences(of: "\"id\": \(id)", with: "\"id\": \(id + offset)") }
    return .json(200, text)
}

private func routes(_ rig: Rig) async -> [String] {
    await rig.transport.requests.map {
        "\($0.httpMethod ?? "GET") \($0.url?.path.dropFirst("/api/app/v1/".count) ?? "")"
    }
}

@Test func aCachedListShowsFirstThenTheAnswer() async throws {
    let rig = try await makeRig()
    let diff = try decoded(ThreadDiffResponse.self, "scenario-thread-diff").diff
    await rig.cache.saveDiff(diff, thread: threadID)
    await rig.transport.hold(diffRoute)
    let snapshots = await recorder(rig)
    let opening = Task { await rig.changes.open(restoring: nil) }
    let cached = try await snapshots.next { $0.isShowingCachedCopy }
    #expect(cached.load == .ready)
    #expect(cached.pager.current == uploadKey)
    await rig.transport.release(diffRoute)
    let answered = try await snapshots.next { $0.load == .ready && !$0.isShowingCachedCopy }
    #expect(answered.pager.current == uploadKey)
    await opening.value
}

@Test func noDiffIsTheEmptyState() async throws {
    let rig = try await makeRig()
    await rig.transport.on(diffRoute, try .scenario(200, "scenario-thread-diff-none"))
    await rig.changes.open(restoring: nil)
    let snapshot = try await recorder(rig).next { $0.load == .ready }
    #expect(snapshot.diff == nil)
    #expect(snapshot.pager.position == nil)
}

@Test func binaryAndTooLargeNeedNoRequest() async throws {
    let rig = try await makeRig()
    await rig.changes.open(restoring: logoKey)
    let snapshot = try await recorder(rig).next { $0.pages[logoKey] == .binary }
    #expect(await rig.transport.requests(on: fileRoute(502)).isEmpty)
    await rig.changes.show(seedKey)
    let seed = try await recorder(rig).next { $0.pages[seedKey] == .tooLarge }
    #expect(seed.pager.current == seedKey)
    #expect(snapshot.pager.current == logoKey)
    #expect(await rig.transport.requests(on: fileRoute(504)).isEmpty)
}

@Test func aReplacedFileIsFoundByKeyAfterOneReload() async throws {
    let rig = try await makeRig()
    await rig.transport.on(fileRoute(501), .json(404, #"{"error":"not_found","message":"Not found."}"#))
    await rig.transport.on(diffRoute, try .scenario(200, "scenario-thread-diff"), try scenarioWithIDs(shiftedBy: 100))
    await rig.changes.open(restoring: uploadKey)
    let snapshot = try await recorder(rig).next {
        if case .ready? = $0.pages[uploadKey] { return true } else { return false }
    }
    #expect(snapshot.pager.current == uploadKey)
    let interesting = await routes(rig).filter { [fileRoute(501), diffRoute, fileRoute(601)].contains($0) }
    #expect(interesting == [diffRoute, fileRoute(501), diffRoute, fileRoute(601)])
    #expect(await rig.transport.requests(on: fileRoute(501)).count == 1)
    #expect(await rig.transport.requests(on: fileRoute(601)).count == 1)
}

@Test func aSecondNotFoundGivesGoneWithoutAThirdRequest() async throws {
    let rig = try await makeRig()
    await rig.transport.on(fileRoute(501), .json(404, #"{"error":"not_found","message":"Not found."}"#))
    await rig.changes.open(restoring: uploadKey)
    let snapshot = try await recorder(rig).next { $0.pages[uploadKey] == .gone }
    #expect(snapshot.pages[uploadKey] == .gone)
    #expect(await rig.transport.requests(on: fileRoute(501)).count == 2)
    #expect(await rig.transport.requests(on: diffRoute).count == 2)
}

private func changed(sequence: Int, turn: TurnChange? = nil) -> ConversationSnapshot {
    ConversationSnapshot(turn: turn, lastDiffUpdateSequence: sequence)
}

private func settle(_ rig: Rig) async {
    await rig.clock.waitForSleeper(endingAt: rig.clock.offset + ThreadEngine.refreshSettle)
    await rig.clock.advance(by: ThreadEngine.refreshSettle)
    for _ in 0..<200 { await Task.yield() }
}

@Test func diffUpdatedReloadsOnce() async throws {
    let rig = try await makeRig()
    await rig.changes.open(restoring: nil)
    #expect(await rig.transport.requests(on: diffRoute).count == 1)
    await rig.changes.threadChanged(changed(sequence: 7))
    await rig.changes.threadChanged(changed(sequence: 8))
    await rig.changes.threadChanged(changed(sequence: 8))
    await settle(rig)
    #expect(await rig.transport.requests(on: diffRoute).count == 2)
    await rig.changes.threadChanged(changed(sequence: 8))
    await rig.clock.advance(by: ThreadEngine.refreshSettle)
    for _ in 0..<200 { await Task.yield() }
    #expect(await rig.transport.requests(on: diffRoute).count == 2)
}

@Test func twoTriggersInOneSettleWindowReloadOnce() async throws {
    let rig = try await makeRig()
    await rig.changes.open(restoring: nil)
    await rig.changes.threadChanged(changed(sequence: 7))
    await rig.changes.threadChanged(changed(sequence: 8))
    await rig.changes.threadChanged(changed(sequence: 9))
    await settle(rig)
    #expect(await rig.transport.requests(on: diffRoute).count == 2)
}

@Test func reloadsDuringARunningReloadAskForExactlyOneMoreRun() async throws {
    let rig = try await makeRig()
    await rig.transport.hold(diffRoute)
    let opening = Task { await rig.changes.open(restoring: nil) }
    await rig.transport.waitForRequests(1, on: diffRoute)
    let first = Task { await rig.changes.reload() }
    let second = Task { await rig.changes.reload() }
    for _ in 0..<200 { await Task.yield() }
    await rig.transport.release(diffRoute)
    await opening.value
    await first.value
    await second.value
    #expect(await rig.transport.requests(on: diffRoute).count == 2)
}

@Test func anUpdateWhileTheFirstAnswerIsInFlightIsNotLost() async throws {
    let rig = try await makeRig()
    await rig.changes.threadChanged(changed(sequence: 7))
    await rig.transport.hold(diffRoute)
    let opening = Task { await rig.changes.open(restoring: nil) }
    await rig.transport.waitForRequests(1, on: diffRoute)
    await rig.changes.threadChanged(changed(sequence: 8))
    await settle(rig)
    await rig.transport.release(diffRoute)
    await opening.value
    #expect(await rig.transport.requests(on: diffRoute).count == 2)
}

@Test func retryFromTheErrorShowsLoadingThenSlow() async throws {
    let rig = try await makeRig()
    await rig.transport.on(diffRoute, .json(500, "{}"), try .scenario(200, "scenario-thread-diff"))
    let snapshots = await recorder(rig)
    await rig.changes.open(restoring: nil)
    _ = try await snapshots.next {
        if case .failed = $0.load { return true } else { return false }
    }
    await rig.transport.hold(diffRoute)
    let retrying = Task { await rig.changes.reload() }
    _ = try await snapshots.next { $0.load == .loading }
    await rig.clock.waitForSleeper(endingAt: rig.clock.offset + ThreadEngine.slowAfter)
    await rig.clock.advance(by: ThreadEngine.slowAfter)
    _ = try await snapshots.next { $0.load == .slow }
    await rig.transport.release(diffRoute)
    _ = try await snapshots.next { $0.load == .ready }
    await retrying.value
}

@Test func aRecapturedFileThatAnswers404AgainIsGone() async throws {
    let rig = try await makeRig()
    let notFound = FakeTransport.Result.json(404, #"{"error":"not_found","message":"Not found."}"#)
    await rig.transport.on(fileRoute(501), notFound)
    await rig.transport.on(fileRoute(601), notFound)
    await rig.transport.on(diffRoute, try .scenario(200, "scenario-thread-diff"), try scenarioWithIDs(shiftedBy: 100))
    await rig.changes.open(restoring: uploadKey)
    let snapshot = try await recorder(rig).next { $0.pages[uploadKey] == .gone }
    #expect(snapshot.pages[uploadKey] == .gone)
    #expect(await rig.transport.requests(on: fileRoute(501)).count == 1)
    #expect(await rig.transport.requests(on: fileRoute(601)).count == 1)
    #expect(await rig.transport.requests(on: diffRoute).count == 2)
}

@Test func navigatingAwayClearsTheMovedBecauseGoneLine() async throws {
    let rig = try await makeRig()
    await rig.changes.open(restoring: nil)
    let initial = try await recorder(rig).next { $0.load == .ready && !$0.isShowingCachedCopy }
    let full = try #require(initial.diff)
    let current = try #require(initial.pager.current)
    let remaining = full.files.filter { $0.key != current }
    let encoded = String(decoding: try JSONEncoder().encode(remaining), as: UTF8.self)
    let reduced = try Scenarios.data("scenario-thread-diff", setting: ["diff", "files"], toJSON: encoded)
    await rig.transport.on(diffRoute, .json(200, String(decoding: reduced, as: UTF8.self)))
    await rig.changes.reload()
    let moved = try await recorder(rig).next { $0.lastMove != .kept }
    #expect(moved.lastMove == .movedBecauseGone(previous: current))
    await rig.changes.next()
    let after = try await recorder(rig).next { $0.lastMove == .kept }
    #expect(after.lastMove == .kept)
}

@Test func theEndOfARunningTurnReloads() async throws {
    let rig = try await makeRig()
    let running = try Scenarios.data("scenario-thread-diff", setting: ["diff", "isTurnRunning"], toJSON: "true")
    await rig.transport.on(diffRoute, .json(200, String(decoding: running, as: UTF8.self)))
    await rig.changes.open(restoring: nil)
    let ended = TurnChange(turnId: LenientID("t1"), state: .completed)
    await rig.changes.threadChanged(ConversationSnapshot(turn: ended))
    await settle(rig)
    #expect(await rig.transport.requests(on: diffRoute).count == 2)
}

@Test func offlineKeepsTheListAndMarksUnloadedFiles() async throws {
    let rig = try await makeRig()
    await rig.transport.on(fileRoute(501), .failure(.notConnectedToInternet))
    await rig.changes.open(restoring: uploadKey)
    let snapshot = try await recorder(rig).next { $0.pages[uploadKey] == .notSaved }
    #expect(snapshot.isOffline)
    #expect(snapshot.diff?.files.count == 4)
    #expect(snapshot.load == .ready)
}

@Test func slowAfterEightSeconds() async throws {
    let rig = try await makeRig()
    await rig.transport.hold(diffRoute)
    let snapshots = await recorder(rig)
    let opening = Task { await rig.changes.open(restoring: nil) }
    await rig.clock.waitForSleeper(endingAt: ThreadEngine.slowAfter)
    await rig.clock.advance(by: ThreadEngine.slowAfter)
    _ = try await snapshots.next { $0.load == .slow }
    await rig.transport.release(diffRoute)
    _ = try await snapshots.next { $0.load == .ready }
    await opening.value
}

@Test func viewedSurvivesAReopen() async throws {
    let rig = try await makeRig()
    await rig.changes.open(restoring: nil)
    await rig.changes.setViewed(true)
    await rig.changes.close()
    let second = try await makeRig(cache: rig.cache)
    await second.changes.open(restoring: nil)
    let snapshot = try await recorder(second).next { $0.load == .ready }
    let file = try #require(snapshot.pager.files.first { $0.key == uploadKey })
    #expect(snapshot.marks.isViewed(file))
}

@Test func onlyTwelvePatchesStayInMemory() async throws {
    let rig = try await makeRig()
    var files: [String] = []
    for index in 1...14 {
        files.append(
            #"{"id": \#(700 + index), "repo": "example/api", "path": "src/file\#(index).php", "oldPath": null, "status": "modified", "additions": 1, "deletions": 0, "isBinary": false, "isTooLarge": false}"#
        )
    }
    let body = try Scenarios.data(
        "scenario-thread-diff", setting: ["diff", "files"], toJSON: "[\(files.joined(separator: ","))]")
    await rig.transport.on(diffRoute, .json(200, String(decoding: body, as: UTF8.self)))
    await rig.changes.open(restoring: nil)
    for _ in 0..<13 { await rig.changes.next() }
    let snapshot = try await recorder(rig).next { $0.pager.index == 13 }
    #expect(snapshot.pages.count <= 3)
    #expect(await rig.changes.keptPatchCount == ThreadChanges.keptPatches)
    let firstKey = DiffFileKey(repo: "example/api", path: "src/file1.php")
    #expect(await rig.transport.requests(on: fileRoute(701)).count == 1)
    await rig.changes.show(firstKey)
    #expect(await rig.transport.requests(on: fileRoute(701)).count == 2)
}
