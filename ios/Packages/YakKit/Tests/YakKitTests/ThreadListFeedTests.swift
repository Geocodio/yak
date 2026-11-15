import Foundation
import Testing

@testable import YakKit

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct Rig {
    let feed: ThreadListFeed
    let transport: ScriptedTransport
    let clock: ManualClock
    let cache: Cache
    let instances: InstanceStore
    let instance: Instance
    let files: InMemoryFileStore
}

private func makeRig(cachedMine: ThreadList? = nil) async throws -> Rig {
    let clock = ManualClock()
    let files = InMemoryFileStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: InMemoryTokenStore(), now: { clock.now() })
    let instance = try await instances.add(
        url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person", token: "t")
    let cache = Cache(instance: instance.id, files: files, clock: clock)
    if let cachedMine { await cache.saveThreadList(cachedMine, filter: .mine) }
    let transport = ScriptedTransport()
    let client = APIClient(
        baseURL: instance.url, token: "t", transport: transport, appVersion: "1.0",
        systemDescription: "Test", sleep: { _ in })
    let feed = ThreadListFeed(instance: instance, client: client, cache: cache, instances: instances, clock: clock)
    return Rig(
        feed: feed, transport: transport, clock: clock, cache: cache, instances: instances,
        instance: instance, files: files)
}

private let emptyList =
    #"{"starred":[],"needsYou":[],"threads":[],"needsYouCount":0,"hasAnyThreads":false}"#

extension ThreadList {
    /// The list with the first needs-you thread private and retitled.
    func withNeedsYouThreadMadePrivate(title: String) throws -> ThreadList {
        var object = try #require(
            try JSONSerialization.jsonObject(with: JSONEncoder().encode(self)) as? [String: Any])
        var items = try #require(object["needsYou"] as? [[String: Any]])
        items[0]["isPrivate"] = true
        items[0]["title"] = title
        object["needsYou"] = items
        return try JSONDecoder().decode(ThreadList.self, from: JSONSerialization.data(withJSONObject: object))
    }
}

struct ThreadListFeedTests {
    @Test func aPrivateTitleNeverReachesTheWidget() throws {
        var list = try decoded(ThreadList.self, "scenario-thread-list")
        list = try list.withNeedsYouThreadMadePrivate(title: "Secret merger plan")
        let snapshot = WidgetSnapshot.make(from: list, instanceName: "Example Yak", now: Date(timeIntervalSince1970: 0))
        let bytes = String(decoding: try JSONEncoder().encode(snapshot), as: UTF8.self)
        #expect(!bytes.contains("Secret merger plan") && !bytes.contains("Private notes on billing"))
        #expect(snapshot.needsYouCount == 2 && snapshot.needsYou.count == 2 && snapshot.running.count == 1)
        #expect(snapshot.needsYou[0].title == nil && snapshot.needsYou[0].isPrivate)
    }

    @Test func showsTheCachedListFirstThenLoads() async throws {
        let cached = try decoded(ThreadList.self, "scenario-thread-list")
        let rig = try await makeRig(cachedMine: cached)
        await rig.transport.hold("GET threads")
        await rig.transport.on("GET threads", .json(200, emptyList))
        let recorder = StreamRecorder(await rig.feed.states())
        await rig.feed.setVisible(true)
        let first = await recorder.next { $0.isShowingCachedCopy }
        #expect(first.list == cached && first.load == .ready)
        await rig.transport.release("GET threads")
        let second = await recorder.next { !$0.isShowingCachedCopy && $0.list?.needsYouCount == 0 }
        #expect(second.isEmpty)
        await rig.feed.setVisible(false)
    }

    @Test func pollsEveryFiveSecondsWhileVisible() async throws {
        let rig = try await makeRig()
        await rig.transport.on("GET threads", .json(200, emptyList))
        await rig.feed.setVisible(true)
        await rig.transport.waitForRequests(1, on: "GET threads")
        await rig.clock.waitForSleeper(endingAt: .seconds(5))
        await rig.clock.advance(by: .seconds(5))
        await rig.transport.waitForRequests(2, on: "GET threads")
        await rig.clock.waitForSleeper(endingAt: .seconds(10))
        await rig.clock.advance(by: .seconds(5))
        await rig.transport.waitForRequests(3, on: "GET threads")
        await rig.feed.setVisible(false)
        await rig.clock.advance(by: .seconds(30))
        #expect(await rig.transport.requests(on: "GET threads").count == 3)
        #expect(rig.clock.sleeperDeadlines.isEmpty)
    }

    @Test func onlyTheUnsearchedMineListIsStored() async throws {
        let rig = try await makeRig()
        let body = String(decoding: try Scenarios.data("scenario-thread-list"), as: UTF8.self)
        await rig.transport.on("GET threads", .json(200, body))
        await rig.feed.setFilter(.everyone, search: nil)
        #expect(await rig.cache.threadList(.everyone) == nil)
        #expect(await rig.cache.threadList(.mine) == nil)
        #expect(await rig.instances.all().first?.needsYouCount == 0)
        #expect(await rig.cache.widgetSnapshot() == nil)
        await rig.feed.setFilter(.mine, search: nil)
        #expect(await rig.cache.threadList(.mine) != nil)
        #expect(await rig.instances.all().first?.needsYouCount == 2)
        #expect(await rig.cache.widgetSnapshot()?.needsYouCount == 2)
        #expect(await rig.instances.badgeCount() == 2)
    }

    @Test func aSearchCancelsTheRequestInFlight() async throws {
        let rig = try await makeRig()
        await rig.transport.on("GET threads", .json(200, emptyList))
        await rig.transport.hold("GET threads")
        await rig.feed.setVisible(true)
        await rig.transport.waitForRequests(1, on: "GET threads")
        let recorder = StreamRecorder(await rig.feed.states())
        let search = Task { await rig.feed.setFilter(.mine, search: "import") }
        await rig.transport.waitForRequests(2, on: "GET threads")
        #expect(await rig.transport.cancelledRequests == 1)
        await rig.transport.release("GET threads")
        await search.value
        let requests = await rig.transport.requests(on: "GET threads")
        #expect(requests.last?.url?.query?.contains("search=import") == true)
        let state = await recorder.next { $0.search == "import" && $0.load == .ready }
        #expect(state.list != nil)
        await rig.feed.setVisible(false)
    }

    @Test func anEmptyListIsEmpty() async throws {
        let rig = try await makeRig()
        await rig.transport.on("GET threads", .json(200, emptyList))
        let recorder = StreamRecorder(await rig.feed.states())
        await rig.feed.refresh()
        let state = await recorder.next { $0.load == .ready }
        #expect(state.isEmpty)
    }

    @Test func offlineKeepsTheList() async throws {
        let rig = try await makeRig()
        let body = String(decoding: try Scenarios.data("scenario-thread-list"), as: UTF8.self)
        await rig.transport.on("GET threads", .json(200, body))
        let recorder = StreamRecorder(await rig.feed.states())
        await rig.feed.refresh()
        _ = await recorder.next { $0.load == .ready && !$0.isOffline }
        await rig.transport.on("GET threads", .failure(.notConnectedToInternet))
        await rig.feed.refresh()
        let offline = await recorder.next { $0.isOffline }
        #expect(offline.list != nil && offline.load == .ready)
        await rig.transport.on("GET threads", .json(200, body))
        await rig.feed.refresh()
        let back = await recorder.next { !$0.isOffline && $0.list != nil }
        #expect(!back.isOffline)
    }

    @Test func aFailureWithoutAListFails() async throws {
        let rig = try await makeRig()
        await rig.transport.on("GET threads", .json(401, #"{"error":"unauthenticated","message":"No."}"#))
        await rig.feed.refresh()
        let recorder = StreamRecorder(await rig.feed.states())
        let state = await recorder.next { _ in true }
        #expect(state.load == .failed(.needsPairing) && state.list == nil)
        await rig.transport.on("GET threads", .json(200, emptyList))
        await rig.transport.hold("GET threads")
        let retry = Task { await rig.feed.refresh() }
        _ = await recorder.next { $0.load == .loading }
        await rig.transport.release("GET threads")
        await retry.value
        _ = await recorder.next { $0.load == .ready }
    }

    @Test func aFirstLoadStillWaitingAfterEightSecondsIsSlow() async throws {
        let rig = try await makeRig()
        await rig.transport.on("GET threads", .json(200, emptyList))
        await rig.transport.hold("GET threads")
        let recorder = StreamRecorder(await rig.feed.states())
        await rig.feed.setVisible(true)
        await rig.clock.waitForSleeper(endingAt: .seconds(8))
        await rig.clock.advance(by: .seconds(8))
        _ = await recorder.next { $0.load == .slow }
        await rig.transport.release("GET threads")
        _ = await recorder.next { $0.load == .ready }
        await rig.feed.setVisible(false)
    }
}
