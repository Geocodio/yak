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

/// Answers `me` and the thread `d0e515ef`; every other thread is unreachable.
private final class ThreadTransport: HTTPTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var paths: [String] = []

    static let me = """
        {"user":{"id":7,"name":"Test Person","email":"person@example.test","initials":"TP","isAdmin":false},
        "device":{"id":3,"name":"Test phone","kind":"phone","pairedAt":"2026-11-15T08:00:00Z"},
        "instance":{"name":"Example Yak","url":"https://example.test","version":"2026.11.1"},"needsYouCount":0,
        "realtime":null,"terminal":{"wsUrl":"wss://example.test/terminal"},
        "attachments":{"maxFiles":8,"maxFileBytes":10485760},
        "push":{"apns":false,"bundleId":"com.example.yak","environment":"production"},
        "features":{"liveActivities":false,"widgets":false}}
        """

    static let show = """
        {"thread":{"id":"d0e515ef","name":"retry-failed-rows","title":"Retry failed rows when the import times out",
        "repo":"example/api","kind":"root","status":"open","visibility":"team","branch":null,"sandboxState":"running",
        "runnerState":"idle","blockedReason":null,"parentThreadId":null,"rootThreadId":null,
        "lastActivityAt":"2026-11-15T09:30:00Z","pullRequestCount":0,"isOwner":true,"isStarred":false},
        "canDrive":true,"messages":[],"composer":{"state":"idle","model":"sonnet","accountName":"Test account",
        "nextAttachmentNumber":1,"models":[]},"notice":null,
        "panes":{"chat":true,"preview":false,"terminal":true,"changes":true,"files":true},"question":null,
        "latestSequence":14}
        """

    func requestCount(for path: String) -> Int { lock.withLock { paths.filter { $0 == path }.count } }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url!
        let path = url.path.replacingOccurrences(of: "/api/app/v1/", with: "")
        lock.withLock { paths.append(path) }
        let body: String
        if path.hasSuffix("me") {
            body = Self.me
        } else if path.hasSuffix("threads/d0e515ef") {
            body = Self.show
        } else if path.hasSuffix("events") {
            body = #"{"events":[],"latestSequence":14,"source":"database"}"#
        } else {
            // A cancelled request is not retried, so the failure reaches the engine at once as offline.
            throw URLError(.cancelled)
        }
        let response = HTTPURLResponse(
            url: url, statusCode: 200, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(body.utf8), response)
    }
}

@MainActor private struct Fixture {
    let transport = ThreadTransport()
    let session: InstanceSession

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
    }

    func eventually(_ condition: () -> Bool) async -> Bool {
        for _ in 0..<600 {
            if condition() { return true }
            try? await Task.sleep(for: .milliseconds(5))
        }
        return condition()
    }
}

@MainActor @Test func startOpensOneEngineAndStopClosesIt() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: "From the drawer")
    await model.start(initialTitle: "From the drawer")
    #expect(model.engine != nil)
    #expect(model.title == "From the drawer")
    #expect(await fixture.eventually { model.snapshot?.show != nil })
    #expect(model.title == "Retry failed rows when the import times out")
    #expect(fixture.transport.requestCount(for: "threads/d0e515ef") == 1)
    await model.stop()
    #expect(model.engine == nil)
    // The engine is closed, so a new caller gets a fresh one that asks the server again.
    let again = try #require(await fixture.session.openThread("d0e515ef"))
    #expect(await fixture.eventually { fixture.transport.requestCount(for: "threads/d0e515ef") == 2 })
    _ = again
    await fixture.session.closeThread("d0e515ef")
    await fixture.session.stop()
}

@MainActor @Test func retryOpensANewEngine() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    let first = try #require(model.engine)
    #expect(await fixture.eventually { model.snapshot?.show != nil })
    await model.retry()
    let second = try #require(model.engine)
    #expect(first !== second)
    #expect(await fixture.eventually { fixture.transport.requestCount(for: "threads/d0e515ef") == 2 })
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func panesComeFromTheShow() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    #expect(model.panes == [.chat])
    await model.start(initialTitle: nil)
    #expect(await fixture.eventually { model.snapshot?.show != nil })
    #expect(model.snapshot?.show?.panes.preview == false)
    #expect(model.panes == [.chat, .files])
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func connectionLineFollowsTheSnapshot() async throws {
    let fixture = try await Fixture()
    let reachable = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    #expect(reachable.connectionLine == nil)
    await reachable.start(initialTitle: nil)
    #expect(await fixture.eventually { reachable.snapshot?.load == .ready })
    #expect(reachable.connectionLine == nil)
    let unreachable = ThreadModel(threadID: "zzzz0000", session: fixture.session)
    await unreachable.start(initialTitle: nil)
    #expect(await fixture.eventually { unreachable.connectionLine == .offline })
    await reachable.stop()
    await unreachable.stop()
    await fixture.session.stop()
}
