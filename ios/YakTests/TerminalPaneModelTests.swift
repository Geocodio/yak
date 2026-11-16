import Foundation
import Testing
import UIKit
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

private struct NoTerminal: TerminalSocketConnector {
    func connect(to url: URL, origin: String, handshakeTimeout: Duration) async throws -> any TerminalSocket {
        throw TerminalSocketClosed(code: nil)
    }
}

/// Serves the thread with the person able or unable to drive; every shell request is answered "thread closed".
private final class WorkspaceTransport: HTTPTransport, @unchecked Sendable {
    let canDrive: Bool
    private let lock = NSLock()
    private var shellRequests = 0
    var terminalRequests: Int { lock.withLock { shellRequests } }

    init(canDrive: Bool) { self.canDrive = canDrive }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url!
        let path = url.path
        var status = 200
        let body: String
        if path.hasSuffix("/me") {
            body = """
                {"user":{"id":7,"name":"Test Person","email":"person@example.test","initials":"TP","isAdmin":false},
                "device":{"id":3,"name":"Test phone","kind":"phone","pairedAt":"2026-11-15T08:00:00Z"},
                "instance":{"name":"Example Yak","url":"https://example.test","version":"2026.11.1"},"needsYouCount":0,
                "realtime":null,"terminal":{"wsUrl":"wss://example.test/terminal"},
                "attachments":{"maxFiles":8,"maxFileBytes":10485760},
                "push":{"apns":false,"bundleId":"com.example.yak","environment":"production"},
                "features":{"liveActivities":false,"widgets":false}}
                """
        } else if path.hasSuffix("/terminal-sessions") {
            lock.withLock { shellRequests += 1 }
            status = 409
            body = #"{"error":"thread_closed","message":"This thread is closed."}"#
        } else if path.hasSuffix("threads/d0e515ef") {
            body = """
                {"thread":{"id":"d0e515ef","name":"retry-failed-rows","title":"Retry failed rows","repo":"example/api",
                "kind":"root","status":"open","visibility":"team","branch":null,"sandboxState":"running",
                "runnerState":"idle","blockedReason":null,"parentThreadId":null,"rootThreadId":null,
                "lastActivityAt":"2026-11-15T09:30:00Z","pullRequestCount":0,"isOwner":true,"isStarred":false},
                "canDrive":\(canDrive),"messages":[],"composer":{"state":"idle","model":"sonnet","accountName":"Test account",
                "nextAttachmentNumber":1,"models":[]},"notice":null,
                "panes":{"chat":true,"preview":false,"terminal":true,"changes":true,"files":true},"question":null,
                "latestSequence":14}
                """
        } else if path.hasSuffix("events") {
            body = #"{"events":[],"latestSequence":14,"source":"database"}"#
        } else {
            throw URLError(.cancelled)
        }
        let response = HTTPURLResponse(
            url: url, statusCode: status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(body.utf8), response)
    }
}

@MainActor private final class FakeSurface: TerminalSurface {
    let view = UIView()
    var onInput: (([UInt8]) -> Void)?
    var onSizeChange: ((Int, Int) -> Void)?
    var onScrollPositionChange: ((Bool) -> Void)?
    var isApplicationCursorMode = false
    var visibleText = ""
    var isScrolledToEnd = true
    func feed(_ bytes: Data) {}
    func feedDivider(_ text: String) {}
    func scrollToEnd() {}
    func setFontSize(_ points: CGFloat) {}
    func focus() {}
    func resignFocus() {}
}

@MainActor private struct Fixture {
    let transport: WorkspaceTransport
    let session: InstanceSession
    let thread: ThreadModel
    let model: TerminalPaneModel

    init(canDrive: Bool = true) async throws {
        transport = WorkspaceTransport(canDrive: canDrive)
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
        let instance = try await instances.add(
            url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person", token: "token")
        let session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens,
            environment: SessionEnvironment(
                http: transport, webSockets: NoSockets(), files: InMemoryFileStore(), clock: SystemClock(),
                makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false))
        await session.start()
        self.session = session
        thread = ThreadModel(threadID: "d0e515ef", session: session)
        await thread.start(initialTitle: nil)
        model = TerminalPaneModel(
            thread: thread, restore: thread.restore,
            makeConnection: { state in
                await session.terminalConnection(
                    thread: "d0e515ef", sandboxState: state, connector: NoTerminal(), timings: .shortWaits)
            },
            makeSurface: { FakeSurface() })
        for _ in 0..<600 where thread.snapshot?.show == nil { try? await Task.sleep(for: .milliseconds(5)) }
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

@MainActor @Test func startOpensMainOnce() async throws {
    let fixture = try await Fixture()
    await fixture.model.start()
    await fixture.model.start()
    #expect(fixture.model.shells.shells.map(\.title) == ["main"])
    #expect(await fixture.eventually { fixture.transport.terminalRequests >= 1 })
    #expect(fixture.transport.terminalRequests == 1)
    await fixture.finish()
}

@MainActor @Test func aReaderNeverAsksForAShell() async throws {
    let fixture = try await Fixture(canDrive: false)
    await fixture.model.start()
    #expect(fixture.model.shells.shells.isEmpty)
    #expect(fixture.model.focusedSnapshot?.phase == .refused(.forbidden))
    #expect(fixture.transport.terminalRequests == 0)
    await fixture.finish()
}

@MainActor @Test func newShellNamesAndStopsAtFour() async throws {
    let fixture = try await Fixture()
    await fixture.model.start()
    for _ in 0..<5 {
        #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .refused(.threadClosed) })
        await fixture.model.newShell()
    }
    #expect(fixture.model.shells.shells.map(\.title) == ["main", "shell 2", "shell 3", "shell 4"])
    await fixture.finish()
}

@MainActor @Test func theControlLatchAppliesToTheNextKeyOnly() async throws {
    let fixture = try await Fixture()
    fixture.model.control.toggle()
    #expect(fixture.model.bytesForTyped([0x63]) == [3])
    #expect(fixture.model.bytesForTyped([0x63]) == [0x63])
    await fixture.finish()
}

@MainActor @Test func stopClosesEveryShell() async throws {
    let fixture = try await Fixture()
    await fixture.model.start()
    await fixture.model.stop()
    #expect(fixture.model.shells.shells.isEmpty)
    #expect(fixture.model.open.isEmpty)
    await fixture.session.stop()
}
