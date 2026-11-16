import Foundation
import Testing
import YakKit

@testable import Yak

/// A clock the test moves; a sleep waits until it is cancelled.
private final class MovingClock: YakClock, @unchecked Sendable {
    private let lock = NSLock()
    private var current = Date(timeIntervalSince1970: 1_794_000_000)

    func now() -> Date { lock.withLock { current } }
    func advance(by seconds: TimeInterval) { lock.withLock { current = current.addingTimeInterval(seconds) } }
    func sleep(for duration: Duration) async throws { try await Task.sleep(for: .seconds(3600)) }
}

private var scenarios: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios")
}

private func scenario(_ name: String) -> String {
    (try? String(contentsOf: scenarios.appendingPathComponent("scenario-overview-\(name).json"), encoding: .utf8))
        ?? "{}"
}

/// Answers the thread list and the stack of `s7ackr00`; a drop is refused when `refusesActions` is set.
private final class ListAndStackTransport: HTTPTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var paths: [String] = []
    var refusesActions = false

    func requestCount(for path: String) -> Int { lock.withLock { paths.filter { $0 == path }.count } }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url!
        let path = url.path.replacingOccurrences(of: "/api/app/v1/", with: "")
        lock.withLock { paths.append(path) }
        var status = 200
        let body: String
        if path.hasSuffix("/stack") {
            body = scenario("busy-stack")
        } else if path.contains("/stack/") || path.hasSuffix("/drop") {
            if refusesActions {
                status = 409
                body = #"{"error":"child_not_failed","message":"Only a sub-thread that failed can be dropped."}"#
            } else {
                body = #"{"status":"dropped"}"#
            }
        } else {
            body = scenario("thread-list")
        }
        let response = HTTPURLResponse(
            url: url, statusCode: status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(body.utf8), response)
    }
}

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

@MainActor private struct Fixture {
    let transport = ListAndStackTransport()
    let clock = MovingClock()
    let model: DrawerModel
    let feed: ThreadListFeed

    init() async throws {
        let instance = Instance(
            url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person",
            lastUsedAt: Date())
        let client = APIClient(
            baseURL: instance.url, token: "token", transport: transport, appVersion: "1.0",
            systemDescription: "Test", sleep: { _ in })
        let cache = Cache(instance: instance.id, files: InMemoryFileStore(), clock: clock)
        let store = InstanceStore(defaults: MemoryDefaults(), tokens: InMemoryTokenStore(), now: { Date() })
        feed = ThreadListFeed(instance: instance, client: client, cache: cache, instances: store, clock: clock)
        let actions = OverviewActions(client: client, cache: cache, clock: clock, makeID: { UUID() })
        model = DrawerModel(
            feed: feed, outbox: nil, scene: SceneModel(state: SceneState()), clock: clock, overview: actions)
    }

    func row(_ id: String) -> DrawerRow? {
        guard case .sections(let sections)? = model.content else { return nil }
        return sections.flatMap(\.rows).first { $0.id == "thread:\(id)" }
    }

    func eventually(_ condition: () -> Bool) async -> Bool {
        for _ in 0..<600 {
            if condition() { return true }
            try? await Task.sleep(for: .milliseconds(5))
        }
        return condition()
    }

    /// Shows the list and waits for the stack of the expanded root.
    func open() async throws -> Task<Void, Never> {
        let running = Task { await model.run() }
        await model.setVisible(true)
        await feed.refresh()
        _ = await eventually {
            if let failed = row("q7adapt0"), case .ready = model.childMenu(failed) { true } else { false }
        }
        return running
    }
}

@MainActor @Test func expandingAParentPrefetchesItsStack() async throws {
    let fixture = try await Fixture()
    let running = try await fixture.open()
    defer { running.cancel() }
    #expect(fixture.transport.requestCount(for: "threads/s7ackr00/stack") == 1)
    fixture.model.toggle("thread:s7ackr00")
    fixture.model.toggle("thread:s7ackr00")
    #expect(await fixture.eventually { fixture.row("q7adapt0") != nil })
    #expect(fixture.transport.requestCount(for: "threads/s7ackr00/stack") == 1)
}

@MainActor @Test func theMenuOffersOverridesOnlyForAFailedChild() async throws {
    let fixture = try await Fixture()
    let running = try await fixture.open()
    defer { running.cancel() }
    let failed = try #require(fixture.row("q7adapt0"))
    let working = try #require(fixture.row("b4ckf111"))
    #expect(fixture.model.childMenu(failed) == .ready([.open, .takeOver, .split, .drop]))
    #expect(fixture.model.childMenu(working) == .ready([.open]))
}

@MainActor @Test func aStaleStackShowsLoading() async throws {
    let fixture = try await Fixture()
    let running = try await fixture.open()
    defer { running.cancel() }
    let failed = try #require(fixture.row("q7adapt0"))
    fixture.clock.advance(by: 11)
    #expect(fixture.model.childMenu(failed) == .loading)
    fixture.model.menuOpening(failed)
    #expect(await fixture.eventually { fixture.model.childMenu(failed) != .loading })
    #expect(fixture.transport.requestCount(for: "threads/s7ackr00/stack") == 2)
}

@MainActor @Test func aRefusedDropShowsTheSentence() async throws {
    let fixture = try await Fixture()
    let running = try await fixture.open()
    defer { running.cancel() }
    fixture.transport.refusesActions = true
    let failed = try #require(fixture.row("q7adapt0"))
    await fixture.model.perform(.drop, on: failed)
    #expect(fixture.model.actionLine == "Only a sub-thread that failed can be dropped.")
    #expect(fixture.model.refusalCount == 1)
    #expect(fixture.model.runningChildID == nil)
}
