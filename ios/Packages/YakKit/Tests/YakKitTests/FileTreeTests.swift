import Foundation
import Testing

@testable import YakKit

private let threadID = "d0e515ef"
private let route = "GET threads/\(threadID)/files"

/// Scripted answers per folder path; the last answer of a path repeats.
private actor FolderAnswers {
    private var queues: [String: [FakeTransport.Result]] = [:]
    func set(_ path: String, _ results: FakeTransport.Result...) { queues[path] = results }
    func next(_ path: String) -> FakeTransport.Result {
        guard var queue = queues[path], !queue.isEmpty else {
            return .json(404, #"{"error":"not_found","message":"Not found."}"#)
        }
        let result = queue.count > 1 ? queue.removeFirst() : queue[0]
        queues[path] = queue
        return result
    }
}

private func scenario(_ status: Int, _ name: String) -> FakeTransport.Result {
    do { return try .scenario(status, name) } catch {
        Issue.record("Missing scenario \(name)")
        return .json(500, "{}")
    }
}

private func edited(_ status: Int, _ name: String, _ path: [String], _ json: String) -> FakeTransport.Result {
    do {
        return .json(status, String(decoding: try Scenarios.data(name, setting: path, toJSON: json), as: UTF8.self))
    } catch {
        Issue.record("Missing scenario \(name)")
        return .json(500, "{}")
    }
}

private let root = scenario(200, "scenario-file-tree-root")
private let src = scenario(200, "scenario-file-tree-src")
private let waking = scenario(409, "scenario-files-waking")
private let wakingForRestore = edited(409, "scenario-files-waking", ["details", "restoring"], "true")
private let unavailableSentence = "The sandbox did not answer. Try again in a moment."
private let unavailable503 = FakeTransport.Result.json(
    503, #"{"error":"unavailable","message":"\#(unavailableSentence)"}"#)

private struct Rig {
    let tree: FileTree
    let transport: ScriptedTransport
    let answers: FolderAnswers
    let clock: ManualClock
    let snapshots: StreamRecorder<FileTreeSnapshot>

    /// Waits for the next snapshot that matches.
    @discardableResult
    func wait(_ predicate: @escaping @Sendable (FileTreeSnapshot) -> Bool) async throws -> FileTreeSnapshot {
        try await snapshots.next(where: predicate)
    }

    func requestCount() async -> Int { await transport.requests(on: route).count }

    func requestedPaths() async -> [String] {
        await transport.requests(on: route).map { request in
            URLComponents(url: request.url!, resolvingAgainstBaseURL: false)?.queryItems?
                .first { $0.name == "path" }?.value ?? ""
        }
    }

    /// Wakes the poll sleeper that ends `duration` from now, after it has registered.
    func step(_ duration: Duration) async {
        await clock.waitForSleeper(endingAt: clock.offset + duration)
        await clock.advance(by: duration)
    }

    func current() async -> FileTreeSnapshot {
        for await snapshot in await tree.snapshots() { return snapshot }
        return FileTreeSnapshot()
    }
}

private func makeRig(
    timings: FileTimings = .standard, _ setup: (FolderAnswers) async -> Void = { _ in }
) async -> Rig {
    let clock = ManualClock()
    let transport = ScriptedTransport()
    let answers = FolderAnswers()
    await setup(answers)
    await transport.on(prefix: route) { request in
        let path =
            URLComponents(url: request.url!, resolvingAgainstBaseURL: false)?.queryItems?
            .first { $0.name == "path" }?.value ?? ""
        return await answers.next(path)
    }
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let tree = FileTree(threadID: threadID, scope: .repo, client: client, clock: clock, timings: timings)
    return Rig(
        tree: tree, transport: transport, answers: answers, clock: clock,
        snapshots: StreamRecorder(await tree.snapshots()))
}

private func rowsLoaded(_ snapshot: FileTreeSnapshot) -> Bool {
    if case .loaded = snapshot.root { return true }
    return false
}

private func row(_ path: String, in snapshot: FileTreeSnapshot) -> FileTreeRow? {
    snapshot.rows.first { $0.id == path }
}

@Suite struct FileTreeTests {
    @Test func loadsTheRootWithMarks() async throws {
        let rig = await makeRig { await $0.set("", root) }
        await rig.tree.start()
        let snapshot = try await rig.wait(rowsLoaded)
        #expect(snapshot.rows.map(\.name) == ["empty", "src", "tests", "README.md"])
        #expect(snapshot.rows.allSatisfy { $0.depth == 0 })
        #expect(row("src", in: snapshot)?.mark == .containsChanges)
        #expect(row("tests", in: snapshot)?.mark == .containsChanges)
        #expect(row("empty", in: snapshot)?.mark == nil)
        #expect(row("README.md", in: snapshot)?.mark == nil)
        #expect(snapshot.changedCount == 2)
        #expect(snapshot.availability == nil)
        #expect(snapshot.root == .loaded(truncated: false))
    }

    @Test func aRepeatedStartChangesNothing() async {
        let rig = await makeRig { await $0.set("", root) }
        await rig.tree.start()
        await rig.tree.start()
        #expect(await rig.requestCount() == 1)
    }

    @Test func expandingLoadsOnceAndCollapsingKeeps() async throws {
        let rig = await makeRig {
            await $0.set("", root)
            await $0.set("src", src)
        }
        await rig.tree.start()
        await rig.tree.toggle("src")
        var snapshot = await rig.current()
        #expect(
            snapshot.rows.map(\.id) == [
                "empty", "src", "src/Importer.php", "src/Retry.php", "src/logo.png", "src/sample.csv", "tests",
                "README.md",
            ])
        #expect(row("src/Importer.php", in: snapshot)?.depth == 1)
        #expect(row("src/Importer.php", in: snapshot)?.mark == .modified)
        await rig.tree.toggle("src")
        snapshot = await rig.current()
        #expect(snapshot.rows.count == 4)
        await rig.tree.toggle("src")
        snapshot = await rig.current()
        #expect(snapshot.rows.count == 8)
        #expect(await rig.requestedPaths() == ["", "src"])
    }

    @Test func anEmptyFolderSaysSo() async {
        let rig = await makeRig {
            await $0.set("", root)
            await $0.set("empty", edited(200, "scenario-file-tree-src", ["entries"], "[]"))
        }
        await rig.tree.start()
        await rig.tree.toggle("empty")
        let snapshot = await rig.current()
        #expect(row("empty", in: snapshot)?.isEmptyFolder == true)
        #expect(row("src", in: snapshot)?.isEmptyFolder == false)
    }

    @Test func truncatedIsCarried() async {
        let rig = await makeRig {
            await $0.set("", root)
            await $0.set("src", src)
        }
        await rig.tree.start()
        await rig.tree.toggle("src")
        let snapshot = await rig.current()
        #expect(row("src", in: snapshot)?.folderState == .loaded(truncated: true))
    }

    @Test func wakingPollsThenShows() async throws {
        let rig = await makeRig { await $0.set("", waking, waking, root) }
        let starting = Task { await rig.tree.start() }
        await rig.transport.waitForRequests(1, on: route)
        let first = try await rig.wait { $0.availability != nil }
        guard case .waking(let waitingForSlot, let restoring, _)? = first.availability else {
            Issue.record("expected waking, got \(String(describing: first.availability))")
            return
        }
        #expect(!waitingForSlot && !restoring)
        #expect(await rig.requestCount() == 1)
        await rig.step(.seconds(2))
        await rig.transport.waitForRequests(2, on: route)
        #expect(await rig.requestCount() == 2)
        await rig.step(.seconds(2))
        await starting.value
        #expect(await rig.requestCount() == 3)
        let snapshot = try await rig.wait { $0.availability == nil && rowsLoaded($0) }
        #expect(snapshot.rows.count == 4)
    }

    @Test func wakingStopsAfterTwoMinutes() async throws {
        let rig = await makeRig { await $0.set("", waking) }
        let starting = Task { await rig.tree.start() }
        for _ in 0..<59 { await rig.step(.seconds(2)) }
        try await rig.wait { $0.availability != nil && $0.availability != .stillWaking }
        #expect(await rig.current().availability != .stillWaking)
        await rig.step(.seconds(2))
        await starting.value
        let stopped = await rig.current()
        #expect(stopped.availability == .stillWaking)
        guard case .failed = stopped.root else {
            Issue.record("expected a failed root")
            return
        }
        let before = await rig.requestCount()
        await rig.answers.set("", root)
        await rig.tree.retry()
        #expect(await rig.requestCount() == before + 1)
        let ready = await rig.current()
        #expect(ready.availability == nil)
        #expect(ready.rows.count == 4)
    }

    @Test func aRestoreThenPollsToReady() async throws {
        let rig = await makeRig { await $0.set("", scenario(409, "scenario-files-destroyed")) }
        await rig.tree.start()
        let removed = await rig.current()
        #expect(removed.availability == .removed(canRestore: true, message: "The sandbox was removed."))

        let performs = Counter()
        let restore = SandboxRestore(perform: {
            await performs.increment()
            return .done
        })
        #expect(await restore.run() == .started)
        await rig.answers.set("", wakingForRestore, wakingForRestore, root)
        let refreshing = Task { await rig.tree.refreshQuietly() }
        let first = try await rig.wait {
            if case .waking(_, true, _)? = $0.availability { return true }
            return false
        }
        #expect(first.rows.isEmpty)
        await rig.step(.seconds(2))
        await rig.step(.seconds(2))
        await refreshing.value
        let ready = await rig.current()
        #expect(ready.availability == nil)
        #expect(ready.rows.count == 4)
        #expect(await rig.requestCount() == 4)
        #expect(await performs.value == 1)
    }

    @Test func restoringOutlastsTheWakeCap() async throws {
        let rig = await makeRig { await $0.set("", wakingForRestore) }
        let starting = Task { await rig.tree.start() }
        for _ in 0..<65 { await rig.step(.seconds(2)) }
        guard case .waking(_, true, _)? = await rig.current().availability else {
            Issue.record("expected the restore to still be waited for after the wake cap")
            return
        }
        for _ in 0..<385 { await rig.step(.seconds(2)) }
        await starting.value
        #expect(await rig.current().availability == .stillRestoring)
    }

    @Test func aFailedSandboxCommandIsNotRetried() async throws {
        let rig = await makeRig { await $0.set("", unavailable503) }
        await rig.tree.start()
        let snapshot = await rig.current()
        #expect(snapshot.root == .failed(.server(code: "unavailable", message: unavailableSentence)))
        #expect(await rig.requestCount() == 1)
    }

    @Test(arguments: [
        ("destroyed", "scenario-files-destroyed"), ("asleep", "scenario-files-asleep"),
        ("closed_destroyed", "scenario-files-destroyed"), ("on_device", "scenario-files-asleep"),
        ("brand_new_state", "scenario-files-asleep"),
    ])
    func everyUnavailableStateMaps(state: String, name: String) async {
        let rig = await makeRig { await $0.set("", edited(409, name, ["details", "state"], "\"\(state)\"")) }
        await rig.tree.start()
        let message =
            (try? JSONDecoder().decode(
                [String: JSONValue].self, from: Scenarios.data(name)))?["message"]?.stringValue ?? ""
        let expected: WorkspaceAvailability =
            switch state {
            case "destroyed": .removed(canRestore: true, message: message)
            case "asleep": .asleep(message: message)
            case "closed_destroyed": .closedRemoved(message: message)
            case "on_device": .onComputer(message: message)
            default: .other(message: message)
            }
        #expect(await rig.current().availability == expected)
        #expect(await rig.requestCount() == 1)
    }

    @Test func offlineKeepsTheRows() async throws {
        let rig = await makeRig {
            await $0.set("", root)
            await $0.set("src", .failure(.notConnectedToInternet))
        }
        await rig.tree.start()
        await rig.tree.toggle("src")
        var snapshot = await rig.current()
        #expect(snapshot.isOffline)
        #expect(snapshot.rows.count == 4)
        #expect(row("src", in: snapshot)?.folderState == .failed(.offline))
        await rig.answers.set("src", src)
        await rig.tree.networkChanged(isOnline: true)
        snapshot = await rig.current()
        #expect(!snapshot.isOffline)
        #expect(row("src", in: snapshot)?.folderState == .loaded(truncated: true))
        #expect(await rig.requestedPaths() == ["", "src", "src"])
    }

    @Test func aFolderThatIsGoneIsNotFound() async {
        let rig = await makeRig {
            await $0.set("", root)
            await $0.set("src", .json(404, #"{"error":"not_found","message":"That folder is gone."}"#))
        }
        await rig.tree.start()
        await rig.tree.toggle("src")
        let snapshot = await rig.current()
        #expect(row("src", in: snapshot)?.folderState == .failed(.notFound))
        #expect(snapshot.rows.count == 4)
    }

    @Test func aBadPathGivesTheValidationMessage() async {
        let body =
            #"{"error":"validation_failed","message":"Invalid.","details":{"errors":{"path":["The path is not allowed."]}}}"#
        let rig = await makeRig { await $0.set("", .json(422, body)) }
        await rig.tree.start()
        #expect(
            await rig.current().root == .failed(.server(code: "validation_failed", message: "The path is not allowed."))
        )
    }

    @Test func quietRefreshKeepsExpansion() async throws {
        let rig = await makeRig {
            await $0.set("", root)
            await $0.set("src", src)
        }
        await rig.tree.start()
        await rig.tree.toggle("src")
        let changed = #"["src/Importer.php","src/Retry.php","tests/ImporterTest.php"]"#
        await rig.answers.set("", edited(200, "scenario-file-tree-root", ["changedPaths"], changed))
        await rig.answers.set("src", edited(200, "scenario-file-tree-src", ["changedPaths"], changed))
        let before = await rig.snapshots.values.count
        await rig.tree.refreshQuietly()
        let snapshot = await rig.current()
        #expect(row("src/Retry.php", in: snapshot)?.mark == .modified)
        #expect(row("src", in: snapshot)?.isExpanded == true)
        #expect(snapshot.changedCount == 3)
        _ = try await rig.wait { row("src/Retry.php", in: $0)?.mark == .modified }
        let during = await rig.snapshots.values.dropFirst(before)
        #expect(!during.isEmpty)
        for seen in during {
            #expect(seen.root != .loading)
            #expect(seen.rows.allSatisfy { $0.folderState != .loading })
        }
    }

    @Test func aCollapsedFolderIsAskedAgainAfterARefresh() async {
        let rig = await makeRig {
            await $0.set("", root)
            await $0.set("src", src)
        }
        await rig.tree.start()
        await rig.tree.toggle("src")
        await rig.tree.toggle("src")
        await rig.tree.refreshQuietly()
        #expect(await rig.requestedPaths() == ["", "src", ""])
        await rig.tree.toggle("src")
        #expect(await rig.requestedPaths() == ["", "src", "", "src"])
    }

    @Test func slowAfterEightSeconds() async throws {
        let rig = await makeRig { await $0.set("", root) }
        await rig.transport.hold(route)
        let starting = Task { await rig.tree.start() }
        await rig.transport.waitForRequests(1, on: route)
        await rig.clock.waitForSleeper(endingAt: .seconds(8))
        #expect(await !rig.current().isSlow)
        await rig.clock.advance(by: .seconds(8))
        try await rig.wait { $0.isSlow }
        await rig.transport.release(route)
        await starting.value
        let snapshot = await rig.current()
        #expect(!snapshot.isSlow)
        #expect(snapshot.rows.count == 4)
    }

    @Test func stopCancels() async {
        let rig = await makeRig { await $0.set("", root) }
        await rig.transport.hold(route)
        let starting = Task { await rig.tree.start() }
        await rig.transport.waitForRequests(1, on: route)
        await rig.tree.stop()
        await starting.value
        let deadline = ContinuousClock.now + .seconds(10)
        while await rig.transport.cancelledRequests < 1, ContinuousClock.now < deadline { await Task.yield() }
        #expect(await rig.transport.cancelledRequests == 1)
        await rig.tree.start()
        #expect(await rig.requestCount() == 1)
        #expect(await rig.current().rows.isEmpty)
    }
}

private actor Counter {
    private(set) var value = 0
    func increment() { value += 1 }
}
