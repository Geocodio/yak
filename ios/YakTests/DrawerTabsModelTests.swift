import Foundation
import Testing
import YakKit

@testable import Yak

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

/// Sleeps end at once, so the search is sent as soon as the text changes.
private struct Immediate: YakClock {
    func now() -> Date { Date(timeIntervalSince1970: 1_794_000_000) }
    func sleep(for duration: Duration) async throws {}
}

private struct Frozen: YakClock {
    func now() -> Date { Date(timeIntervalSince1970: 1_794_000_000) }
    func sleep(for duration: Duration) async throws { try await Task.sleep(for: .seconds(3600)) }
}

/// Answers the list by its `filter`; a filter in `held` waits until released, `failing` answers an error.
private final class TabTransport: HTTPTransport, @unchecked Sendable {
    enum Failure { case offline, server }
    private let lock = NSLock()
    private var queries: [String] = []
    private var held: [String: CheckedContinuation<Void, Never>] = [:]
    private var holding: Set<String> = []
    private var failing: [String: Failure] = [:]

    var requested: [String] { lock.withLock { queries } }
    func hold(_ filter: String) { lock.withLock { _ = holding.insert(filter) } }
    func fail(_ filter: String, _ failure: Failure) { lock.withLock { failing[filter] = failure } }
    func release(_ filter: String) {
        let waiting = lock.withLock { () -> CheckedContinuation<Void, Never>? in
            holding.remove(filter)
            return held.removeValue(forKey: filter)
        }
        waiting?.resume()
    }

    static func body(title: String, reviewCount: Int = 5) -> String {
        """
        {"starred":[],"needsYou":[],"threads":[{"id":"\(title)","title":"\(title)","repo":"example/web",
        "status":"stopped","isPrivate":false,"isShared":false,"isUnattended":false,"isStarred":false,
        "canDelete":true,"lastActivityAt":"2026-11-15T07:00:00Z","group":"today","children":[]}],
        "needsYouCount":0,"hasAnyThreads":true,"reviewCount":\(reviewCount),
        "facets":{"sources":[{"key":"slack","label":"Slack","count":4}],
        "starters":[{"key":"user:1","name":"Test person","count":4},{"key":"yak","name":"Yak","count":2}]}}
        """
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url!
        let query = url.query ?? ""
        let filter =
            URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems?
            .first { $0.name == "filter" }?.value ?? ""
        lock.withLock { queries.append(query) }
        let mustWait = lock.withLock { holding.contains(filter) }
        if mustWait {
            await withTaskCancellationHandler {
                await withCheckedContinuation { continuation in
                    lock.withLock { held[filter] = continuation }
                }
            } onCancel: {
                let waiting = lock.withLock { held.removeValue(forKey: filter) }
                waiting?.resume()
            }
            try Task.checkCancellation()
        }
        let failure = lock.withLock { failing[filter] }
        if failure == .offline { throw URLError(.notConnectedToInternet) }
        let status = failure == .server ? 500 : 200
        let response = HTTPURLResponse(
            url: url, statusCode: status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        let text =
            failure == .server ? #"{"error":{"code":"server_error","message":"Broken."}}"# : Self.body(title: filter)
        return (Data(text.utf8), response)
    }
}

@MainActor private struct TabsRig {
    let transport = TabTransport()
    let scene: SceneModel
    let feed: ThreadListFeed
    let cache: Cache
    let model: DrawerModel

    init(saved: SceneState = SceneState()) async throws {
        scene = SceneModel(state: saved)
        let instance = Instance(
            url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test Person",
            lastUsedAt: Date())
        let client = APIClient(
            baseURL: instance.url, token: "token", transport: transport, appVersion: "1.0",
            systemDescription: "Test", sleep: { _ in })
        let files = InMemoryFileStore()
        cache = Cache(instance: instance.id, files: files, clock: Frozen())
        let store = InstanceStore(defaults: MemoryDefaults(), tokens: InMemoryTokenStore(), now: { Date() })
        feed = ThreadListFeed(instance: instance, client: client, cache: cache, instances: store, clock: Frozen())
        model = DrawerModel(feed: feed, outbox: nil, scene: scene, clock: Immediate())
    }

    func titles() -> [String] {
        guard case .sections(let sections)? = model.content else { return [] }
        return sections.flatMap(\.rows).map(\.title)
    }

    func eventually(_ condition: () -> Bool) async -> Bool { await TestTimeScale.poll(condition) }
}

private let allFilters = ["mine", "yak", "everyone", "closed", "review"]

@MainActor @Suite struct DrawerTabsModelTests {
    @Test func switchingTabsLoadsEachTabAndRemembersItInTheWindow() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: allFilters, viewerID: "1")
        let running = Task { await rig.model.run() }
        defer { running.cancel() }
        for tab in [ThreadListFilter.review, .everyone, .closed, .mine] {
            await rig.model.setFilter(tab)
            #expect(await rig.eventually { rig.titles() == [tab.rawValue] })
            #expect(rig.scene.state.filter == tab && rig.model.selection.filter == tab)
        }
        #expect(
            rig.transport.requested.suffix(4) == ["filter=review", "filter=everyone", "filter=closed", "filter=mine"])
        let saved = try JSONDecoder().decode(SceneState.self, from: #require(rig.scene.encodedState))
        #expect(saved.filter == .mine)
    }

    @Test func theReviewCountStaysWhileAnotherTabIsOn() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: allFilters, viewerID: nil)
        let running = Task { await rig.model.run() }
        defer { running.cancel() }
        await rig.model.setFilter(.everyone)
        #expect(await rig.eventually { rig.model.reviewCount == 5 })
        await rig.model.setFilter(.closed)
        #expect(await rig.eventually { rig.titles() == ["closed"] })
        #expect(rig.model.reviewCount == 5)
    }

    @Test func aNewTabCancelsTheLoadOfTheOneLeftAndItsAnswerNeverShows() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: allFilters, viewerID: nil)
        rig.transport.hold("review")
        let running = Task { await rig.model.run() }
        defer { running.cancel() }
        let leaving = Task { await rig.model.setFilter(.review) }
        #expect(await rig.eventually { rig.transport.requested.contains("filter=review") })
        #expect(rig.model.state?.load == .loading && rig.model.content == nil)
        await rig.model.setFilter(.everyone)
        #expect(await rig.eventually { rig.titles() == ["everyone"] })
        rig.transport.release("review")
        await leaving.value
        #expect(rig.titles() == ["everyone"] && rig.model.selection.filter == .everyone)
    }

    @Test func theServersFiltersDecideTheTabs() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: ["mine", "everyone", "review"], viewerID: nil)
        #expect(rig.model.tabs.tabs == [.mine, .review, .everyone])
    }

    @Test func aSavedTabTheServerDoesNotListFallsBackToMine() async throws {
        var saved = SceneState()
        saved.filter = .review
        let rig = try await TabsRig(saved: saved)
        await rig.model.configure(serverFilters: ["mine", "everyone"], viewerID: nil)
        // The window's tab is applied once the cold start has run.
        #expect(rig.transport.requested.isEmpty)
        await rig.model.syncFilter()
        #expect(rig.scene.state.filter == .mine)
        #expect(!rig.transport.requested.contains("filter=review"))
    }

    @Test func aRestoredTabWaitsForTheColdStartAndThenLoads() async throws {
        var saved = SceneState()
        saved.filter = .review
        let rig = try await TabsRig(saved: saved)
        await rig.model.configure(serverFilters: allFilters, viewerID: nil)
        #expect(rig.transport.requested.isEmpty)
        rig.scene.applyColdStart(.drawer(.mine))
        #expect(rig.scene.state.filter == .review)
        await rig.model.syncFilter()
        #expect(rig.transport.requested == ["filter=review"])
    }

    @Test func anOlderServerOffersMineAndEveryoneAndNeverSendsReviewOrClosed() async throws {
        var saved = SceneState()
        saved.filter = .closed
        let rig = try await TabsRig(saved: saved)
        await rig.model.configure(serverFilters: nil, viewerID: nil)
        let running = Task { await rig.model.run() }
        defer { running.cancel() }
        #expect(rig.model.tabs.tabs == [.mine, .everyone] && !rig.model.tabs.supportsFacets)
        await rig.model.setFilter(.review)
        await rig.model.setFilter(.everyone)
        #expect(await rig.eventually { rig.titles() == ["everyone"] })
        #expect(rig.transport.requested.allSatisfy { !$0.contains("review") && !$0.contains("closed") })
    }

    @Test func onAnOlderServerStartedByYakAsksTheOldYakFilter() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: nil, viewerID: nil)
        let running = Task { await rig.model.run() }
        defer { running.cancel() }
        await rig.model.toggleStarter("yak")
        #expect(await rig.eventually { rig.titles() == ["yak"] })
        #expect(rig.transport.requested.last == "filter=yak")
        #expect(rig.model.selection == ThreadListSelection(filter: .mine, starters: ["yak"]))
        #expect(rig.model.starterChoices().map(\.key) == ["yak"])
    }

    @Test func onAnOlderServerStartedByYakIsOfferedOnMineOnlyAndLeavesWithTheTab() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: nil, viewerID: nil)
        let running = Task { await rig.model.run() }
        defer { running.cancel() }
        await rig.model.toggleStarter("yak")
        #expect(rig.model.starterChoices().map(\.key) == ["yak"])
        await rig.model.setFilter(.everyone)
        #expect(await rig.eventually { rig.titles() == ["everyone"] })
        #expect(rig.model.starterChoices().isEmpty)
        #expect(rig.model.selection == ThreadListSelection(filter: .everyone))
        #expect(rig.transport.requested.last == "filter=everyone")
    }

    @Test func theFilterSheetNarrowsTheTabAndTheSelectionIsKept() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: allFilters, viewerID: "1")
        let running = Task { await rig.model.run() }
        defer { running.cancel() }
        await rig.model.setFilter(.everyone)
        #expect(await rig.eventually { rig.model.facets != nil })
        await rig.model.toggleSource("slack")
        await rig.model.toggleStarter("yak")
        // The sheet keeps its rows while the narrower list loads.
        #expect(rig.model.facets != nil)
        #expect(
            await rig.eventually {
                rig.transport.requested.last == "filter=everyone&sources%5B%5D=slack&starters%5B%5D=yak"
                    || rig.transport.requested.last == "filter=everyone&sources[]=slack&starters[]=yak"
            })
        #expect(rig.scene.state.sources == ["slack"] && rig.scene.state.starters == ["yak"])
        #expect(rig.model.selection.activeCount == 2)
        #expect(rig.model.sourceChoices().first?.isPicked == true)
        #expect(rig.model.starterChoices().map(\.role) == [.viewer, .yak])
        await rig.model.setFilter(.closed)
        #expect(rig.model.selection.sources == ["slack"])
        await rig.model.clearFilters()
        #expect(rig.model.selection.activeCount == 0 && rig.model.selection.filter == .closed)
    }

    @Test func theSearchStaysWithinTheSelectedTab() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: allFilters, viewerID: nil)
        await rig.model.setFilter(.closed)
        rig.model.search = "pricing"
        // The text narrows the closed tab's loaded rows; the list is not asked to search.
        #expect(rig.model.localQuery == "pricing" && rig.model.selection.filter == .closed)
        #expect(await rig.eventually { rig.transport.requested.last == "filter=closed" })
    }

    @Test func theNeedsYouCountReadsOnlyTheMineList() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: allFilters, viewerID: nil)
        let running = Task { await rig.model.run() }
        defer { running.cancel() }
        await rig.model.setFilter(.closed)
        #expect(await rig.eventually { rig.titles() == ["closed"] })
        #expect(rig.model.state?.isWholeMine == false)
        await rig.model.setFilter(.mine)
        #expect(await rig.eventually { rig.titles() == ["mine"] })
        #expect(rig.model.state?.isWholeMine == true)
    }

    @Test func aFailedTabShowsTheErrorAndRetryLoadsItAgain() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: allFilters, viewerID: nil)
        rig.transport.fail("closed", .server)
        let running = Task { await rig.model.run() }
        defer { running.cancel() }
        await rig.model.setFilter(.closed)
        #expect(await rig.eventually { if case .failed? = rig.model.state?.load { true } else { false } })
        #expect(rig.model.content == nil && rig.model.selection.filter == .closed)
        rig.transport.fail("closed", .offline)
        await rig.model.retry()
        #expect(await rig.eventually { rig.model.state?.load == .failed(.offline) })
    }

    @Test func aTabLoadedBeforeShowsItsSavedCopyOfflineWhileANewOneShowsTheOfflineState() async throws {
        let rig = try await TabsRig()
        await rig.model.configure(serverFilters: allFilters, viewerID: nil)
        let running = Task { await rig.model.run() }
        defer { running.cancel() }
        await rig.model.setFilter(.closed)
        #expect(await rig.eventually { rig.titles() == ["closed"] })
        await rig.model.setFilter(.mine)
        #expect(await rig.eventually { rig.titles() == ["mine"] })
        rig.transport.fail("closed", .offline)
        rig.transport.fail("review", .offline)
        await rig.model.setFilter(.closed)
        #expect(await rig.eventually { rig.model.isOffline && rig.titles() == ["closed"] })
        await rig.model.setFilter(.review)
        #expect(await rig.eventually { rig.model.state?.load == .failed(.offline) })
        #expect(rig.model.content == nil)
    }
}

@Suite struct SceneStateFilterMigrationTests {
    private func decode(_ json: String) throws -> SceneState {
        try JSONDecoder().decode(SceneState.self, from: Data(json.utf8))
    }

    @Test func theOldChipsYakChoiceBecomesMineStartedByYak() throws {
        let state = try decode(#"{"filter":"yak"}"#)
        #expect(state.filter == .mine && state.starters == ["yak"] && state.sources.isEmpty)
    }

    @Test func aSavedTabAndFiltersComeBack() throws {
        var state = SceneState()
        state.filter = .review
        state.sources = ["slack"]
        state.starters = ["user:1"]
        let again = try JSONDecoder().decode(SceneState.self, from: JSONEncoder().encode(state))
        #expect(again.selection == ThreadListSelection(filter: .review, sources: ["slack"], starters: ["user:1"]))
    }

    @Test func aWordThisBuildDoesNotKnowReadsAsMine() throws {
        #expect(try decode(#"{"filter":"archived"}"#).filter == .mine)
        #expect(try decode(#"{}"#).selection == ThreadListSelection())
    }
}
