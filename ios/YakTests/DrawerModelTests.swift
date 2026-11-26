import Foundation
import Testing
import YakKit

@testable import Yak

/// A clock whose sleeps wait until the test releases them; a cancelled sleep throws.
private final class GateClock: YakClock, @unchecked Sendable {
    private let lock = NSLock()
    private var waiters: [UUID: CheckedContinuation<Void, any Error>] = [:]
    private var cancelled: Set<UUID> = []

    var sleeperCount: Int { lock.withLock { waiters.count } }

    func now() -> Date { Date(timeIntervalSince1970: 1_794_000_000) }

    func sleep(for duration: Duration) async throws {
        let id = UUID()
        try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, any Error>) in
                register(id, continuation)
            }
        } onCancel: {
            cancel(id)
        }
    }

    private func register(_ id: UUID, _ continuation: CheckedContinuation<Void, any Error>) {
        lock.lock()
        let isCancelled = cancelled.contains(id)
        if !isCancelled { waiters[id] = continuation }
        lock.unlock()
        if isCancelled { continuation.resume(throwing: CancellationError()) }
    }

    private func cancel(_ id: UUID) {
        lock.lock()
        cancelled.insert(id)
        let waiting = waiters.removeValue(forKey: id)
        lock.unlock()
        waiting?.resume(throwing: CancellationError())
    }

    func release() {
        let all = lock.withLock { () -> [CheckedContinuation<Void, any Error>] in
            defer { waiters = [:] }
            return Array(waiters.values)
        }
        for waiting in all { waiting.resume() }
    }
}

/// Answers the thread list from a fixed document and keeps the queries it was asked; a new thread never finishes.
private final class ListTransport: HTTPTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var queries: [String] = []

    static let list = """
        {"starred":[],"needsYou":[],"threads":[{"id":"a1b2c3d4","title":"Private notes on billing","repo":"example/api",
        "status":"running","needsYouReason":null,"needsYouLabel":null,"isPrivate":true,"isShared":false,
        "isUnattended":false,"isStarred":false,"canDelete":true,"ownerInitials":"TP",
        "lastActivityAt":"2026-11-15T07:00:00Z","group":"today","children":[{"id":"e5f6g7h8",
        "title":"Split the billing job","repo":"example/api","status":"stopped","needsYouReason":null,
        "needsYouLabel":null,"isPrivate":true,"isShared":false,"isUnattended":false,"isStarred":false,
        "canDelete":false,"ownerInitials":"TP","lastActivityAt":"2026-11-15T06:00:00Z","group":"today",
        "children":[]}]}],"needsYouCount":0,"hasAnyThreads":true}
        """

    var requestedQueries: [String] { lock.withLock { queries } }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url!
        if request.httpMethod == "POST" {
            try await Task.sleep(for: .seconds(3600))
        }
        lock.withLock { queries.append(url.query ?? "") }
        let response = HTTPURLResponse(
            url: url, statusCode: 200, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(Self.list.utf8), response)
    }
}

/// The texts the server's search was asked for; every search finds nothing.
private final class SearchRecorder: @unchecked Sendable {
    private let lock = NSLock()
    private var texts: [String] = []
    private var failure: APIFailure?
    var asked: [String] { lock.withLock { texts } }
    func fail(with failure: APIFailure) { lock.withLock { self.failure = failure } }
    func answer(_ text: String) throws(APIFailure) -> SearchAnswer {
        let failure = lock.withLock { () -> APIFailure? in
            texts.append(text)
            return self.failure
        }
        if let failure { throw failure }
        return SearchAnswer(results: [])
    }
}

@MainActor private struct Fixture {
    let transport = ListTransport()
    let searches = SearchRecorder()
    let typingClock = GateClock()
    let scene = SceneModel(state: SceneState())
    let feed: ThreadListFeed
    let outbox: Outbox
    let model: DrawerModel

    init() async throws {
        let instance = Instance(
            url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test Person",
            lastUsedAt: Date())
        let client = APIClient(
            baseURL: instance.url, token: "token", transport: transport, appVersion: "1.0",
            systemDescription: "Test", sleep: { _ in })
        let files = InMemoryFileStore()
        let feedClock = GateClock()
        let store = InstanceStore(defaults: MemoryDefaults(), tokens: InMemoryTokenStore(), now: { Date() })
        feed = ThreadListFeed(
            instance: instance, client: client, cache: Cache(instance: instance.id, files: files, clock: feedClock),
            instances: store, clock: feedClock)
        outbox = Outbox(
            instance: instance.id, client: client, files: files, clock: GateClock(), makeID: { UUID() })
        let searches = searches
        model = DrawerModel(
            feed: feed, outbox: outbox, scene: scene, clock: typingClock,
            search: { (text: String) throws(APIFailure) in try searches.answer(text) })
    }

    /// Waits until `condition` holds; the tasks it waits for run on this actor or on the feed.
    func eventually(_ condition: () -> Bool) async -> Bool {
        await TestTimeScale.poll(condition)
    }
}

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

@MainActor @Test func searchWaitsForTheTypingToStop() async throws {
    let fixture = try await Fixture()
    let running = Task { await fixture.model.run() }
    defer { running.cancel() }
    fixture.model.search = "w"
    fixture.model.search = "we"
    fixture.model.search = "web"
    #expect(await fixture.eventually { fixture.typingClock.sleeperCount == 1 })
    #expect(fixture.searches.asked.isEmpty)
    fixture.typingClock.release()
    #expect(await fixture.eventually { !fixture.searches.asked.isEmpty })
    #expect(fixture.searches.asked == ["web"])
    // The list is never asked to search: the field narrows the loaded rows below two characters.
    #expect(fixture.transport.requestedQueries.allSatisfy { !$0.contains("search") })
}

@MainActor @Test func oneCharacterNarrowsTheLoadedListWithoutAskingTheServer() async throws {
    let fixture = try await Fixture()
    let running = Task { await fixture.model.run() }
    defer { running.cancel() }
    await fixture.feed.refresh()
    #expect(await fixture.eventually { fixture.model.content != nil })
    fixture.model.search = "z"
    guard case .noResults? = fixture.model.content else {
        Issue.record("the list was not narrowed")
        return
    }
    fixture.model.search = "p"
    guard case .sections(let sections)? = fixture.model.content else {
        Issue.record("no rows")
        return
    }
    #expect(sections.flatMap(\.rows).first?.id == "thread:a1b2c3d4")
    #expect(fixture.model.searchState.phase == .tooShort && fixture.searches.asked.isEmpty)
}

@MainActor @Test func fromTwoCharactersTheServersAnswerReplacesTheFilter() async throws {
    let fixture = try await Fixture()
    let running = Task { await fixture.model.run() }
    defer { running.cancel() }
    await fixture.feed.refresh()
    #expect(await fixture.eventually { fixture.model.content != nil })
    fixture.model.search = "zz"
    #expect(fixture.model.localQuery == nil && fixture.model.searchState.phase == .loading)
    #expect(await fixture.eventually { fixture.typingClock.sleeperCount == 1 })
    fixture.typingClock.release()
    #expect(await fixture.eventually { fixture.model.searchState.phase == .empty })
    guard case .sections? = fixture.model.content else {
        Issue.record("the list was filtered")
        return
    }
    fixture.model.search = ""
    #expect(fixture.model.searchState.phase == .idle)
}

@MainActor @Test func offlineTheLoadedThreadsStandInUnderTheirOwnHeading() async throws {
    let fixture = try await Fixture()
    fixture.searches.fail(with: .network(.notConnectedToInternet))
    let running = Task { await fixture.model.run() }
    defer { running.cancel() }
    await fixture.feed.refresh()
    #expect(await fixture.eventually { fixture.model.content != nil })
    fixture.model.search = "billing"
    #expect(await fixture.eventually { fixture.typingClock.sleeperCount == 1 })
    fixture.typingClock.release()
    #expect(await fixture.eventually { fixture.model.searchState.phase == .offline })
    #expect(fixture.model.isSearchFallback)
    guard case .sections(let sections)? = fixture.model.displayedContent else {
        Issue.record("no rows")
        return
    }
    #expect(sections.map(\.kind) == [.savedOnThisPhone] && sections.first?.rows.map(\.id) == ["thread:a1b2c3d4"])
}

@MainActor @Test func aResultThreadUsesTheLoadedRowWhenTheTabHasIt() async throws {
    let fixture = try await Fixture()
    let running = Task { await fixture.model.run() }
    defer { running.cancel() }
    await fixture.feed.refresh()
    #expect(await fixture.eventually { fixture.model.content != nil })
    let known = SearchResult(kind: .thread, id: "a1b2c3d4", title: "Private notes on billing", subtitle: "example/api")
    #expect(fixture.model.threadRow(for: known).isPrivate && fixture.model.threadRow(for: known).canDelete)
    let unknown = SearchResult(kind: .thread, id: "zzzzzzzz", title: "Other", subtitle: "example/api / yak/other")
    #expect(fixture.model.threadRow(for: unknown).repositoryDetail == "example/api / yak/other")
}

@MainActor @Test func widenSearchKeepsTheText() async throws {
    let fixture = try await Fixture()
    let running = Task { await fixture.model.run() }
    defer { running.cancel() }
    fixture.model.search = "webhook"
    #expect(await fixture.eventually { fixture.typingClock.sleeperCount == 1 })
    fixture.typingClock.release()
    #expect(await fixture.eventually { fixture.searches.asked == ["webhook"] })
    await fixture.model.widenSearch()
    #expect(fixture.scene.state.filter == .everyone)
    #expect(fixture.model.search == "webhook")
    #expect(await fixture.eventually { fixture.transport.requestedQueries.last == "filter=everyone" })
}

@MainActor @Test func togglingAParentShowsItsChildren() async throws {
    let fixture = try await Fixture()
    let running = Task { await fixture.model.run() }
    defer { running.cancel() }
    await fixture.feed.refresh()
    #expect(await fixture.eventually { fixture.model.content != nil })
    func ids() -> [String] {
        guard case .sections(let sections)? = fixture.model.content else { return [] }
        return sections.flatMap(\.rows).map(\.id)
    }
    #expect(ids() == ["thread:a1b2c3d4"])
    fixture.model.toggle("thread:a1b2c3d4")
    #expect(ids() == ["thread:a1b2c3d4", "thread:e5f6g7h8"])
    fixture.model.toggle("thread:a1b2c3d4")
    #expect(ids() == ["thread:a1b2c3d4"])
}

@MainActor @Test func unsentNewThreadsShowFirst() async throws {
    let fixture = try await Fixture()
    let running = Task { await fixture.model.run() }
    defer { running.cancel() }
    await fixture.feed.refresh()
    #expect(await fixture.eventually { fixture.model.content != nil })
    let draft = NewThreadDraft(repo: "example/api", mode: "task")
    _ = try await fixture.outbox.enqueue(OutboxDraft(target: .newThread(draft), text: "Fix the import retry"))
    #expect(
        await fixture.eventually {
            guard case .sections(let sections)? = fixture.model.content else { return false }
            return sections.first?.kind == .notSentYet
        })
    guard case .sections(let sections)? = fixture.model.content else { return }
    #expect(sections.first?.rows.first?.title == "Fix the import retry")
    #expect(sections.count == 2)
}

@MainActor
@Suite struct InstanceSwitchModelTests {
    private struct SwitchFailure: Error {}

    @Test func ignoresASecondTapWhileOneSwitchIsUnderWay() async {
        var activated: [UUID] = []
        var release: CheckedContinuation<Void, Never>?
        let model = InstanceSwitchModel(
            activate: { id in
                activated.append(id)
                await withCheckedContinuation { release = $0 }
            }, finish: {})
        let first = UUID()
        let running = Task { await model.switchTo(id: first, name: "One") }
        while release == nil { await Task.yield() }
        #expect(model.isSwitching)
        #expect(model.switchingID == first)
        #expect(await model.switchTo(id: UUID(), name: "Two") == false)
        release?.resume()
        #expect(await running.value)
        #expect(activated == [first])
    }

    @Test func aFailedSwitchNamesTheInstanceAndFreesTheRows() async {
        var shouldFail = true
        var finished = 0
        let model = InstanceSwitchModel(
            activate: { _ in if shouldFail { throw SwitchFailure() } }, finish: { finished += 1 })
        #expect(await model.switchTo(id: UUID(), name: "Other") == false)
        #expect(model.failedName == "Other")
        #expect(!model.isSwitching)
        #expect(finished == 0)
        shouldFail = false
        #expect(await model.switchTo(id: UUID(), name: "Other"))
        #expect(model.failedName == nil)
        #expect(finished == 1)
    }
}
