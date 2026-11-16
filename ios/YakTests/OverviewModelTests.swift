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

private struct NoSockets: WebSocketConnector {
    struct Refused: Error {}
    func connect(to url: URL) async throws -> any WebSocketConnection { throw Refused() }
}

/// Lets the model's timers run a hundred times faster, so a six second line leaves within a blink.
private struct FastClock: YakClock {
    func now() -> Date { Date() }
    func sleep(for duration: Duration) async throws { try await Task.sleep(for: duration / 100) }
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

/// Serves `me`, the thread `s7ackr00`, its stack, its environment and the repository retry.
private final class OverviewTransport: HTTPTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var paths: [String] = []
    /// What the environment answers after the retry was sent.
    var settlesAfterRetry = false
    private var retried = false

    static let me = """
        {"user":{"id":7,"name":"Test Person","email":"person@example.test","initials":"TP","isAdmin":false},
        "device":{"id":3,"name":"Test phone","kind":"phone","pairedAt":"2026-11-15T08:00:00Z"},
        "instance":{"name":"Example Yak","url":"https://example.test","version":"2026.11.1"},"needsYouCount":0,
        "realtime":null,"terminal":{"wsUrl":"wss://example.test/terminal"},
        "attachments":{"maxFiles":8,"maxFileBytes":10485760},
        "push":{"apns":false,"bundleId":"com.example.yak","environment":"production"},
        "features":{"liveActivities":false,"widgets":false}}
        """

    func requestCount(for path: String) -> Int { lock.withLock { paths.filter { $0 == path }.count } }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url!
        let path = url.path.replacingOccurrences(of: "/api/app/v1/", with: "")
        lock.withLock { paths.append(path) }
        var status = 200
        var body: String
        if path.hasSuffix("me") {
            body = Self.me
        } else if path.hasSuffix("threads/s7ackr00") {
            body = scenario("thread-show")
        } else if path.hasSuffix("events") {
            body = #"{"events":[],"latestSequence":14,"source":"database"}"#
        } else if path.hasSuffix("/stack") {
            body = scenario("no-sub-threads")
        } else if path.hasSuffix("/environment") {
            body = scenario("environment-setup-failed")
            if lock.withLock({ retried }), settlesAfterRetry {
                body = body.replacingOccurrences(of: "\"failed\"", with: "\"pending\"")
            }
        } else if path.hasSuffix("/retry") {
            lock.withLock { retried = true }
            if !settlesAfterRetry {
                status = 409
                body = #"{"error":"nothing_to_retry","message":"Only a failed setup can be retried."}"#
            } else {
                body = #"{"status":"retrying"}"#
            }
            try await Task.sleep(for: .milliseconds(30))
        } else {
            throw URLError(.cancelled)
        }
        let response = HTTPURLResponse(
            url: url, statusCode: status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(body.utf8), response)
    }
}

@MainActor private struct Fixture {
    let transport = OverviewTransport()
    let session: InstanceSession
    let thread: ThreadModel
    let model: OverviewModel

    init() async throws {
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
        let instance = try await instances.add(
            url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person", token: "token")
        session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens,
            environment: SessionEnvironment(
                http: transport, webSockets: NoSockets(), files: InMemoryFileStore(), clock: SystemClock(),
                makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false))
        await session.start()
        thread = ThreadModel(threadID: "s7ackr00", session: session)
        model = OverviewModel(threadID: "s7ackr00", session: session, thread: thread, clock: FastClock())
    }

    func openThread() async {
        await thread.start(initialTitle: nil)
        _ = await eventually { thread.snapshot?.show != nil }
    }

    func eventually(_ condition: () -> Bool) async -> Bool {
        for _ in 0..<600 {
            if condition() { return true }
            try? await Task.sleep(for: .milliseconds(5))
        }
        return condition()
    }

    func finish() async {
        await model.stop()
        await thread.stop()
        await session.stop()
    }
}

@MainActor @Test func contentFollowsTheSnapshotAndTheFeed() async throws {
    let fixture = try await Fixture()
    await fixture.openThread()
    // The snapshot alone gives the status from the show and no repositories.
    let first = try #require(fixture.model.content)
    #expect(first.status.sandbox == .running)
    #expect(first.links.repositories.isEmpty)
    await fixture.model.setVisible(true)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.content?.links.repositories.count == 2 })
    #expect(fixture.model.content?.links.branch == "yak/rebuild-search-index")
    if case .you? = fixture.model.content?.people.startedBy {} else { Issue.record("started by you") }
    await fixture.finish()
}

@MainActor @Test func startOpensOneFeedAndStopClosesIt() async throws {
    let fixture = try await Fixture()
    await fixture.openThread()
    await fixture.model.setVisible(true)
    await fixture.model.start()
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.transport.requestCount(for: "threads/s7ackr00/stack") == 1 })
    #expect(fixture.transport.requestCount(for: "threads/s7ackr00/environment") == 1)
    await fixture.model.stop()
    // The feed is closed, so a new caller gets a fresh one that asks again.
    let again = try #require(await fixture.session.openOverview("s7ackr00"))
    await again.setVisible(true)
    #expect(await fixture.eventually { fixture.transport.requestCount(for: "threads/s7ackr00/stack") == 2 })
    await fixture.session.closeOverview("s7ackr00")
    await fixture.finish()
}

@MainActor @Test func visibilityFollowsTheScreen() async throws {
    let fixture = try await Fixture()
    await fixture.openThread()
    await fixture.model.setVisible(false)
    await fixture.model.start()
    try await Task.sleep(for: .milliseconds(150))
    #expect(fixture.transport.requestCount(for: "threads/s7ackr00/stack") == 0)
    #expect(fixture.transport.requestCount(for: "threads/s7ackr00/environment") == 0)
    await fixture.model.setVisible(true)
    #expect(await fixture.eventually { fixture.transport.requestCount(for: "threads/s7ackr00/stack") == 1 })
    await fixture.finish()
}

@MainActor @Test func aRetryWithNothingToRetryLetsTheRowSpeak() async throws {
    let fixture = try await Fixture()
    await fixture.openThread()
    await fixture.model.setVisible(true)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.content?.links.repositories.count == 2 })
    await fixture.model.retryRepositorySetup(LenientID("22"))
    #expect(fixture.model.actionLine == "The retry did not start. Try again.")
    #expect(fixture.model.runningAction == nil)
    // The line leaves by itself.
    #expect(await fixture.eventually { fixture.model.actionLine == nil })
    await fixture.finish()
}

@MainActor @Test func aRetryThatTakesLeavesNoLine() async throws {
    let fixture = try await Fixture()
    fixture.transport.settlesAfterRetry = true
    await fixture.openThread()
    await fixture.model.setVisible(true)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.content?.links.repositories.count == 2 })
    await fixture.model.retryRepositorySetup(LenientID("22"))
    #expect(fixture.model.actionLine == nil)
    #expect(fixture.model.content?.links.repositories.last?.status == .pending)
    await fixture.finish()
}

@MainActor @Test func aDoubleTapRetriesOnce() async throws {
    let fixture = try await Fixture()
    fixture.transport.settlesAfterRetry = true
    await fixture.openThread()
    await fixture.model.setVisible(true)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.content?.links.repositories.count == 2 })
    async let first: Void = fixture.model.retryRepositorySetup(LenientID("22"))
    async let second: Void = fixture.model.retryRepositorySetup(LenientID("22"))
    _ = await (first, second)
    #expect(fixture.transport.requestCount(for: "threads/s7ackr00/repositories/22/retry") == 1)
    await fixture.finish()
}
