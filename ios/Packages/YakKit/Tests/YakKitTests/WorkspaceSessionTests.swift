import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let threadID = "d0e515ef"
private let terminalRoute = "POST threads/\(threadID)/terminal-sessions"
private let searchRoute = "GET threads/\(threadID)/files/search"

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct SessionRig {
    let session: InstanceSession
    let transport: ScriptedTransport
}

private func makeSession(start: Bool) async throws -> SessionRig {
    let clock = ManualClock()
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { clock.now() })
    let instance = try await instances.add(
        url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person", token: "test-token")
    let transport = ScriptedTransport()
    await transport.on("GET me", try .scenario(200, "scenario-me"))
    await transport.on(terminalRoute, try .scenario(200, "scenario-terminal-session"))
    let privateAnswer = try FakeTransport.Result.scenario(200, "scenario-broadcasting-auth-private")
    let presenceAnswer = try FakeTransport.Result.scenario(200, "scenario-broadcasting-auth-presence")
    await transport.on(prefix: "POST broadcasting/auth") { request in
        String(decoding: request.httpBody ?? Data(), as: UTF8.self).contains("presence-")
            ? presenceAnswer : privateAnswer
    }
    let ids = IDSequence()
    let environment = SessionEnvironment(
        http: transport, webSockets: FakeWebSocketServer(), files: InMemoryFileStore(), clock: clock,
        makeID: { ids.next() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
    let session = InstanceSession(instance: instance, instances: instances, tokens: tokens, environment: environment)
    if start { await session.start() }
    return SessionRig(session: session, transport: transport)
}

private func connection(_ rig: SessionRig) async -> TerminalConnection? {
    await rig.session.terminalConnection(
        thread: threadID, sandboxState: "running", connector: FakeTerminalConnector())
}

/// Waits until the connector has seen a connection, with a real-time bound.
private func connectorSettles(_ connector: FakeTerminalConnector) async {
    let deadline = ContinuousClock.now + .seconds(10)
    while await connector.connections.isEmpty, ContinuousClock.now < deadline { await Task.yield() }
}

@Suite struct WorkspaceSessionTests {
    @Test func accessorsNeedAnActiveSession() async throws {
        let rig = try await makeSession(start: false)
        #expect(await connection(rig) == nil)
        #expect(await rig.session.fileTree(thread: threadID, scope: .repo) == nil)
        #expect(await rig.session.fileDocument(thread: threadID, scope: .repo, path: "README.md") == nil)
        await rig.session.start()
        #expect(await connection(rig) != nil)
        #expect(await rig.session.fileTree(thread: threadID, scope: .repo) != nil)
        #expect(await rig.session.fileDocument(thread: threadID, scope: .repo, path: "README.md") != nil)
        await rig.session.stop()
        #expect(await connection(rig) == nil)
    }

    @Test func theTerminalOriginIsTheInstance() async throws {
        let rig = try await makeSession(start: true)
        let connector = FakeTerminalConnector()
        await connector.enqueue(.socket(FakeTerminalSocket()))
        let shell = try #require(
            await rig.session.terminalConnection(thread: threadID, sandboxState: "running", connector: connector))
        await shell.start(columns: 80, rows: 24)
        await rig.transport.waitForRequests(1, on: terminalRoute)
        let request = try #require(await rig.transport.requests(on: terminalRoute).first)
        #expect(request.value(forHTTPHeaderField: "Origin") == "https://yak.example.com")
        await connectorSettles(connector)
        #expect(await connector.connections.first?.origin == "https://yak.example.com")
        await shell.close()
    }

    @Test func eachCallIsANewShell() async throws {
        let rig = try await makeSession(start: true)
        let first = try #require(await connection(rig))
        let second = try #require(await connection(rig))
        #expect(first !== second)
        await first.start(columns: 80, rows: 24)
        await second.start(columns: 80, rows: 24)
        await rig.transport.waitForRequests(2, on: terminalRoute)
        #expect(await rig.transport.requests(on: terminalRoute).count == 2)
        await first.close()
        await second.close()
    }
}

// MARK: Server search

private struct SearchRig {
    let tree: FileTree
    let transport: ScriptedTransport
    let clock: ManualClock
    let snapshots: StreamRecorder<FileTreeSnapshot>
}

private func makeSearchRig(search: FakeTransport.Result) async throws -> SearchRig {
    let clock = ManualClock()
    let transport = ScriptedTransport()
    await transport.on(searchRoute, search)
    await transport.on("GET threads/\(threadID)/files", try .scenario(200, "scenario-file-tree-root"))
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let tree = FileTree(threadID: threadID, scope: .repo, client: client, clock: clock)
    return SearchRig(
        tree: tree, transport: transport, clock: clock, snapshots: StreamRecorder(await tree.snapshots()))
}

private func queryValue(_ request: URLRequest, _ name: String) -> String? {
    URLComponents(url: request.url!, resolvingAgainstBaseURL: false)?.queryItems?.first { $0.name == name }?.value
}

@Suite struct FileSearchTests {
    @Test func searchWaitsForTheTypingToStop() async throws {
        let rig = try await makeSearchRig(search: try .scenario(200, "scenario-file-search"))
        let first = Task { await rig.tree.search("im") }
        await rig.clock.waitForSleepers(1)
        await rig.clock.advance(by: .milliseconds(200))
        let second = Task { await rig.tree.search("imp") }
        await rig.clock.waitForSleeper(endingAt: .milliseconds(500))
        await rig.clock.advance(by: .milliseconds(300))
        await first.value
        await second.value
        let found = try await rig.snapshots.next { snapshot in
            if case .results = snapshot.find { return true }
            return false
        }
        #expect(
            found.find
                == .results(
                    [
                        FileFindResult(name: "Importer.php", path: "src/Importer.php", mark: nil),
                        FileFindResult(name: "ImporterTest.php", path: "tests/ImporterTest.php", mark: nil),
                    ], truncated: false, isPartial: false))
        let requests = await rig.transport.requests(on: searchRoute)
        #expect(requests.count == 1)
        #expect(queryValue(try #require(requests.first), "q") == "imp")
        #expect(queryValue(try #require(requests.first), "scope") == "repo")
    }

    @Test func oneCharacterIsIdleAndALongQueryIsCut() async throws {
        let rig = try await makeSearchRig(search: try .scenario(200, "scenario-file-search"))
        await rig.tree.search("i")
        #expect(await rig.transport.requests(on: searchRoute).isEmpty)
        let long = Task { await rig.tree.search(String(repeating: "a", count: 300)) }
        await rig.clock.waitForSleepers(1)
        await rig.clock.advance(by: .milliseconds(300))
        await long.value
        let request = try #require(await rig.transport.requests(on: searchRoute).first)
        #expect(queryValue(request, "q")?.count == 200)
    }

    @Test func aFailedSearchFallsBackToTheLoadedFolders() async throws {
        let rig = try await makeSearchRig(
            search: .json(503, #"{"error":"unavailable","message":"The sandbox did not answer."}"#))
        await rig.tree.start()
        let search = Task { await rig.tree.search("readme") }
        await rig.clock.waitForSleepers(1)
        await rig.clock.advance(by: .milliseconds(300))
        await search.value
        let state = try await rig.snapshots.next { snapshot in
            if case .results = snapshot.find { return true }
            return false
        }
        #expect(
            state.find
                == .results(
                    [FileFindResult(name: "README.md", path: "README.md", mark: nil)], truncated: false,
                    isPartial: true))
    }

    @Test func theSearchFixtureDecodes() throws {
        try #require(Fixtures.exists("thread-files-search"), "The server fixture thread-files-search is missing.")
        _ = try JSONDecoder().decode(FileSearchResult.self, from: Fixtures.data("thread-files-search"))
    }
}
