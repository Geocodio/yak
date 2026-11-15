import Foundation
import Testing

@testable import YakKit

private let tasksRoute = "GET tasks"

private struct Rig {
    let feed: PagedFeed<TaskListQuery, TaskRow>
    let transport: ScriptedTransport
    let cache: Cache
    let clock: ManualClock
}

private func pageTwo() throws -> FakeTransport.Result {
    var object = try #require(try JSONSerialization.jsonObject(with: Scenarios.data("scenario-tasks")) as? [String: Any])
    var rows = try #require(object["tasks"] as? [[String: Any]])
    var fresh = rows[2]
    fresh["id"] = "r7t3w9bc"
    rows = [rows[2], fresh]
    object["tasks"] = rows
    object["nextPage"] = NSNull()
    return .json(200, String(decoding: try JSONSerialization.data(withJSONObject: object), as: UTF8.self))
}

private func makeRig(cache existing: Cache? = nil, clock: ManualClock = ManualClock()) async throws -> Rig {
    let transport = ScriptedTransport()
    await transport.on(tasksRoute, try .scenario(200, "scenario-tasks"))
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let cache = existing ?? Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let feed = PagedFeed<TaskListQuery, TaskRow>(
        query: TaskListQuery(), cacheKey: .tasks,
        fetch: { (query, page) async throws(APIFailure) -> (rows: [TaskRow], nextPage: Int?) in
            let answer = try await client.send(.tasks(query, page: page))
            return (answer.tasks, answer.nextPage)
        }, cache: cache, clock: clock)
    return Rig(feed: feed, transport: transport, cache: cache, clock: clock)
}

@Suite struct PagedFeedTests {
    @Test func theFirstPageIsCachedAndShownFirst() async throws {
        let first = try await makeRig()
        await first.feed.reload()
        let saved: FirstPage<TaskRow>? = await first.cache.firstPage(.tasks)
        #expect(saved?.rows.count == 3)

        let second = try await makeRig(cache: first.cache)
        await second.transport.hold(tasksRoute)
        let snapshots = StreamRecorder(await second.feed.snapshots())
        let loading = Task { await second.feed.reload() }
        let cached = try await snapshots.next { $0.isShowingCachedCopy }
        #expect(cached.rows.count == 3 && cached.load == .ready)
        await second.transport.release(tasksRoute)
        let fresh = try await snapshots.next { !$0.isShowingCachedCopy && $0.load == .ready }
        #expect(fresh.nextPage == 2)
        await loading.value
    }

    @Test func loadMoreAppendsOnceWithoutDuplicates() async throws {
        let rig = try await makeRig()
        await rig.feed.reload()
        await rig.transport.on(tasksRoute, try pageTwo())
        await rig.transport.hold(tasksRoute)
        async let first: Void = rig.feed.loadMore()
        await rig.transport.waitForRequests(2, on: tasksRoute)
        async let second: Void = rig.feed.loadMore()
        await rig.transport.release(tasksRoute)
        _ = await (first, second)
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let latest = try await snapshots.next { _ in true }
        #expect(latest.rows.map(\.id) == ["k3m9p2qa", "m4n8r2tw", "p6s2v8xa", "r7t3w9bc"])
        #expect(latest.nextPage == nil && !latest.isLoadingMore)
        #expect(await rig.transport.requests(on: tasksRoute).count == 2)
        await rig.feed.loadMore()
        #expect(await rig.transport.requests(on: tasksRoute).count == 2)
    }

    @Test func aFilteredQueryIsNotCached() async throws {
        let rig = try await makeRig()
        var query = TaskListQuery()
        query.status = .failed
        await rig.feed.setQuery(query)
        let saved: FirstPage<TaskRow>? = await rig.cache.firstPage(.tasks)
        #expect(saved == nil)
        let sent = try #require(await rig.transport.requests(on: tasksRoute).first)
        #expect(sent.url?.query?.contains("status=failed") == true)
    }

    @Test func aFailedFirstLoadIsAnErrorAndRetryRecovers() async throws {
        let rig = try await makeRig()
        await rig.transport.on(tasksRoute, .failure(.notConnectedToInternet))
        await rig.feed.reload()
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        #expect(try await snapshots.next { _ in true }.load == .failed(.offline))
        await rig.transport.on(tasksRoute, try .scenario(200, "scenario-tasks"))
        await rig.feed.reload()
        let recovered = try await snapshots.next { $0.load == .ready }
        #expect(recovered.rows.count == 3)
    }

    @Test func aFailedReloadKeepsTheRowsAndSaysOffline() async throws {
        let rig = try await makeRig()
        await rig.feed.reload()
        await rig.transport.on(tasksRoute, .failure(.notConnectedToInternet))
        await rig.feed.reload()
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let latest = try await snapshots.next { _ in true }
        #expect(latest.rows.count == 3 && latest.isOffline && latest.load == .ready)
    }

    @Test func aFailedLoadMoreKeepsTheRowsAndSetsTheProblem() async throws {
        let rig = try await makeRig()
        await rig.feed.reload()
        await rig.transport.on(tasksRoute, .json(500, #"{"error":"server_error","message":"Broke."}"#))
        await rig.feed.loadMore()
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let latest = try await snapshots.next { _ in true }
        #expect(latest.rows.count == 3 && latest.nextPage == 2 && !latest.isLoadingMore)
        #expect(latest.problem == .server(code: "server_error", message: "Broke."))
    }

    @Test func aSlowFirstLoadSaysSo() async throws {
        let rig = try await makeRig()
        await rig.transport.hold(tasksRoute)
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let loading = Task { await rig.feed.reload() }
        await rig.clock.waitForSleepers(1)
        await rig.clock.advance(by: .seconds(8))
        _ = try await snapshots.next { $0.load == .slow }
        await rig.transport.release(tasksRoute)
        _ = try await snapshots.next { $0.load == .ready }
        await loading.value
    }
}
