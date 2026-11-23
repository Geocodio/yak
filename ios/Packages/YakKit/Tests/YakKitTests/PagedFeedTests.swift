import Foundation
import Testing

@testable import YakKit

private let listRoute = "GET observations"

private struct Rig {
    let feed: PagedFeed<ObservationQuery, ObservationRow>
    let transport: ScriptedTransport
    let cache: Cache
    let clock: ManualClock
}

/// A page of the Observations list built from the scenario's first row, one row per id.
private func page(ids: [Int], nextPage: Int?) throws -> FakeTransport.Result {
    var object = try #require(
        try JSONSerialization.jsonObject(with: Scenarios.data("scenario-observations")) as? [String: Any])
    let template = try #require((object["observations"] as? [[String: Any]])?.first)
    object["observations"] = ids.map { id -> [String: Any] in
        var row = template
        row["id"] = id
        return row
    }
    object["nextPage"] = nextPage ?? NSNull()
    return .json(200, String(decoding: try JSONSerialization.data(withJSONObject: object), as: UTF8.self))
}

private func firstPage() throws -> FakeTransport.Result { try page(ids: [301, 302, 303], nextPage: 2) }

private func pageTwo() throws -> FakeTransport.Result { try page(ids: [303, 304], nextPage: nil) }

private func makeRig(cache existing: Cache? = nil, clock: ManualClock = ManualClock()) async throws -> Rig {
    let transport = ScriptedTransport()
    await transport.on(listRoute, try firstPage())
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let cache = existing ?? Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let feed = PagedFeed<ObservationQuery, ObservationRow>(
        query: ObservationQuery(), cacheKey: .observations,
        fetch: { (query, page) async throws(APIFailure) -> (rows: [ObservationRow], nextPage: Int?) in
            let answer = try await client.send(.observations(query, page: page))
            return (answer.observations, answer.nextPage)
        }, cache: cache, clock: clock)
    return Rig(feed: feed, transport: transport, cache: cache, clock: clock)
}

@Suite struct PagedFeedTests {
    @Test func theFirstPageIsCachedAndShownFirst() async throws {
        let first = try await makeRig()
        await first.feed.reload()
        let saved: FirstPage<ObservationRow>? = await first.cache.firstPage(.observations)
        #expect(saved?.rows.count == 3)

        let second = try await makeRig(cache: first.cache)
        await second.transport.hold(listRoute)
        let snapshots = StreamRecorder(await second.feed.snapshots())
        let loading = Task { await second.feed.reload() }
        let cached = try await snapshots.next { $0.isShowingCachedCopy }
        #expect(cached.rows.count == 3 && cached.load == .ready)
        await second.transport.release(listRoute)
        let fresh = try await snapshots.next { !$0.isShowingCachedCopy && $0.load == .ready }
        #expect(fresh.nextPage == 2)
        await loading.value
    }

    @Test func loadMoreAppendsOnceWithoutDuplicates() async throws {
        let rig = try await makeRig()
        await rig.feed.reload()
        await rig.transport.on(listRoute, try pageTwo())
        await rig.transport.hold(listRoute)
        async let first: Void = rig.feed.loadMore()
        await rig.transport.waitForRequests(2, on: listRoute)
        async let second: Void = rig.feed.loadMore()
        await rig.transport.release(listRoute)
        _ = await (first, second)
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let latest = try await snapshots.next { _ in true }
        #expect(latest.rows.map(\.id) == [301, 302, 303, 304])
        #expect(latest.nextPage == nil && !latest.isLoadingMore)
        #expect(await rig.transport.requests(on: listRoute).count == 2)
        await rig.feed.loadMore()
        #expect(await rig.transport.requests(on: listRoute).count == 2)
    }

    @Test func aFilteredQueryIsNotCached() async throws {
        let rig = try await makeRig()
        var query = ObservationQuery()
        query.outcome = .declined
        await rig.feed.setQuery(query)
        let saved: FirstPage<ObservationRow>? = await rig.cache.firstPage(.observations)
        #expect(saved == nil)
        let sent = try #require(await rig.transport.requests(on: listRoute).first)
        #expect(sent.url?.query?.contains("outcome=declined") == true)
    }

    @Test func aFailedFirstLoadIsAnErrorAndRetryRecovers() async throws {
        let rig = try await makeRig()
        await rig.transport.on(listRoute, .failure(.notConnectedToInternet))
        await rig.feed.reload()
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        #expect(try await snapshots.next { _ in true }.load == .failed(.offline))
        await rig.transport.on(listRoute, try firstPage())
        await rig.feed.reload()
        let recovered = try await snapshots.next { $0.load == .ready }
        #expect(recovered.rows.count == 3)
    }

    @Test func aFailedReloadKeepsTheRowsAndSaysOffline() async throws {
        let rig = try await makeRig()
        await rig.feed.reload()
        await rig.transport.on(listRoute, .failure(.notConnectedToInternet))
        await rig.feed.reload()
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let latest = try await snapshots.next { _ in true }
        #expect(latest.rows.count == 3 && latest.isOffline && latest.load == .ready)
    }

    @Test func aFailedLoadMoreKeepsTheRowsAndSetsTheProblem() async throws {
        let rig = try await makeRig()
        await rig.feed.reload()
        await rig.transport.on(listRoute, .json(500, #"{"error":"server_error","message":"Broke."}"#))
        await rig.feed.loadMore()
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let latest = try await snapshots.next { _ in true }
        #expect(latest.rows.count == 3 && latest.nextPage == 2 && !latest.isLoadingMore)
        #expect(latest.problem == .server(code: "server_error", message: "Broke."))
    }

    @Test func aSlowFirstLoadSaysSo() async throws {
        let rig = try await makeRig()
        await rig.transport.hold(listRoute)
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let loading = Task { await rig.feed.reload() }
        await rig.clock.waitForSleepers(1)
        await rig.clock.advance(by: .seconds(8))
        _ = try await snapshots.next { $0.load == .slow }
        await rig.transport.release(listRoute)
        _ = try await snapshots.next { $0.load == .ready }
        await loading.value
    }
}
