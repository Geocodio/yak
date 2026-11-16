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
    /// What the shell request answers.
    enum SessionAnswer { case closed, removed, open, waiting }

    let canDrive: Bool
    private let lock = NSLock()
    private var shellRequests = 0
    private var restoreCount = 0
    private var answer = SessionAnswer.closed
    private var threadStatus = "open"
    var terminalRequests: Int { lock.withLock { shellRequests } }
    var restoreRequests: Int { lock.withLock { restoreCount } }

    init(canDrive: Bool, answer: SessionAnswer = .closed) {
        self.canDrive = canDrive
        self.answer = answer
    }

    func setAnswer(_ next: SessionAnswer) { lock.withLock { answer = next } }
    func setThreadStatus(_ next: String) { lock.withLock { threadStatus = next } }

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
            let current = lock.withLock {
                shellRequests += 1
                return answer
            }
            switch current {
            case .closed:
                status = 409
                body = #"{"error":"thread_closed","message":"This thread is closed."}"#
            case .removed:
                status = 409
                body = #"{"error":"sandbox_destroyed","message":"The sandbox was removed."}"#
            case .open:
                body =
                    #"{"url":"wss://example.test/terminal/ws?token=t3rm7k3n","token":"t3rm7k3n","expires_at":"2026-11-16T10:15:00+00:00"}"#
            case .waiting:
                status = 202
                body = #"{"state":"waiting","limit":null,"restoring":false}"#
            }
        } else if path.hasSuffix("restore/retry") {
            lock.withLock { restoreCount += 1 }
            try await Task.sleep(for: .milliseconds(150))
            body = #"{"status":"ok"}"#
        } else if path.hasSuffix("threads/d0e515ef") {
            body = """
                {"thread":{"id":"d0e515ef","name":"retry-failed-rows","title":"Retry failed rows","repo":"example/api",
                "kind":"root","status":"\(lock.withLock { threadStatus })","visibility":"team","branch":null,"sandboxState":"running",
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

private actor RecordingSocket: TerminalSocket {
    private(set) var sentBytes: [Data] = []
    private(set) var closeCode: Int?
    private var inbox: [TerminalFrame] = []
    private var receiver: CheckedContinuation<TerminalFrame, any Error>?

    func send(bytes: Data) async throws {
        if let closeCode { throw TerminalSocketClosed(code: closeCode) }
        sentBytes.append(bytes)
    }

    func send(text: String) async throws {}

    func receive() async throws -> TerminalFrame {
        if !inbox.isEmpty { return inbox.removeFirst() }
        if let closeCode { throw TerminalSocketClosed(code: closeCode) }
        return try await withCheckedThrowingContinuation { receiver = $0 }
    }

    func close() async {
        guard closeCode == nil else { return }
        closeCode = 1000
        receiver?.resume(throwing: TerminalSocketClosed(code: 1000))
        receiver = nil
    }

    func serverSends(_ frame: TerminalFrame) {
        if let receiver {
            self.receiver = nil
            receiver.resume(returning: frame)
        } else {
            inbox.append(frame)
        }
    }
}

/// Hands out a new recording socket per connection; each starts with a banner so the shell shows as connected.
private final class RecordingConnector: TerminalSocketConnector, @unchecked Sendable {
    private let lock = NSLock()
    private var opened: [RecordingSocket] = []
    var sockets: [RecordingSocket] { lock.withLock { opened } }

    func connect(to url: URL, origin: String, handshakeTimeout: Duration) async throws -> any TerminalSocket {
        let socket = RecordingSocket()
        await socket.serverSends(.output(Data("Example sandbox\r\n".utf8)))
        lock.withLock { opened.append(socket) }
        return socket
    }
}

private final class Counter: @unchecked Sendable {
    private let lock = NSLock()
    private var value = 0
    var count: Int { lock.withLock { value } }
    func increment() { lock.withLock { value += 1 } }
}

@MainActor private final class FakeSurface: TerminalSurface {
    let view = UIView()
    var onInput: (([UInt8]) -> Void)?
    var onSizeChange: ((Int, Int) -> Void)?
    var onScrollPositionChange: ((Bool) -> Void)?
    var isApplicationCursorMode = false
    var visibleText = ""
    var isScrolledToEnd = true
    var fed = Data()
    func feed(_ bytes: Data) { fed.append(bytes) }
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

    let connector = RecordingConnector()
    let connectionsMade = Counter()

    init(canDrive: Bool = true, answer: WorkspaceTransport.SessionAnswer = .closed) async throws {
        transport = WorkspaceTransport(canDrive: canDrive, answer: answer)
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
        let connector = connector
        let connectionsMade = connectionsMade
        model = TerminalPaneModel(
            thread: thread, restore: thread.restore,
            makeConnection: { state in
                connectionsMade.increment()
                let socketConnector: any TerminalSocketConnector = answer == .open ? connector : NoTerminal()
                return await session.terminalConnection(
                    thread: "d0e515ef", sandboxState: state, connector: socketConnector, timings: .shortWaits)
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

    func eventuallyAsync(_ condition: () async -> Bool) async -> Bool {
        for _ in 0..<600 {
            if await condition() { return true }
            try? await Task.sleep(for: .milliseconds(5))
        }
        return await condition()
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

@MainActor @Test func arrowsFollowTheEmulatorsMode() async throws {
    let fixture = try await Fixture(answer: .open)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .connected })
    let surface = try #require(fixture.model.focusedShell?.surface as? FakeSurface)
    let socket = try #require(fixture.connector.sockets.first)
    fixture.model.press(.up)
    #expect(await fixture.eventuallyAsync { await socket.sentBytes.last == Data([0x1B, 0x5B, 0x41]) })
    surface.isApplicationCursorMode = true
    fixture.model.press(.up)
    #expect(await fixture.eventuallyAsync { await socket.sentBytes.last == Data([0x1B, 0x4F, 0x41]) })
    await fixture.finish()
}

@MainActor @Test func restoreIsSharedWithFiles() async throws {
    let fixture = try await Fixture()
    let second = TerminalPaneModel(
        thread: fixture.thread, restore: fixture.thread.restore, makeConnection: { _ in nil },
        makeSurface: { FakeSurface() })
    async let first: Void = fixture.model.restore()
    async let other: Void = second.restore()
    _ = await (first, other)
    #expect(fixture.transport.restoreRequests == 1)
    await fixture.finish()
}

@MainActor @Test func aRestoreTellsEveryShell() async throws {
    let fixture = try await Fixture(answer: .removed)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .refused(.sandboxRemoved) })
    fixture.transport.setAnswer(.waiting)
    await fixture.model.restore()
    #expect(
        await fixture.eventually {
            if case .restoring = fixture.model.focusedSnapshot?.phase { true } else { false }
        })
    if case .waitingForSlot = fixture.model.focusedSnapshot?.phase { Issue.record("showed a slot wait") }
    await fixture.finish()
}

@MainActor @Test func aClosedThreadEventMovesThePane() async throws {
    let fixture = try await Fixture(answer: .open)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .connected })
    fixture.transport.setThreadStatus("closed")
    fixture.transport.setAnswer(.closed)
    await fixture.thread.retry()
    #expect(await fixture.eventually { fixture.thread.snapshot?.show?.thread.status == .closed })
    await fixture.model.threadFactsChanged()
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .refused(.threadClosed) })
    #expect(await fixture.connector.sockets[0].closeCode == 1000)
    await fixture.finish()
}

@MainActor @Test func backgroundSuspendsAndForegroundResumes() async throws {
    let fixture = try await Fixture(answer: .open)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .connected })
    await fixture.model.scenePhaseChanged(.background)
    fixture.model.backgroundTimeExpired()
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .offline })
    #expect(await fixture.connector.sockets[0].closeCode == 1000)
    await fixture.model.scenePhaseChanged(.active)
    #expect(await fixture.eventually { fixture.connector.sockets.count == 2 })
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .connected })
    await fixture.finish()
}

@MainActor @Test func twoNewShellCallsOpenOneShell() async throws {
    let fixture = try await Fixture(answer: .open)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .connected })
    async let first: Void = fixture.model.newShell()
    async let second: Void = fixture.model.newShell()
    _ = await (first, second)
    #expect(fixture.connectionsMade.count == 2)
    #expect(fixture.model.shells.shells.count == 2)
    await fixture.finish()
}

@MainActor @Test func outputReachesTheSurfaceAfterTheModelStartsAgain() async throws {
    let fixture = try await Fixture(answer: .open)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .connected })
    let firstID = try #require(fixture.model.focusedID)
    fixture.model.receive(.bytes(Data("early".utf8)), from: firstID)
    await fixture.model.stop()
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .connected })
    let id = try #require(fixture.model.focusedID)
    fixture.model.receive(.bytes(Data("later".utf8)), from: id)
    let surface = try #require(fixture.model.focusedShell?.surface as? FakeSurface)
    #expect(await fixture.eventually { String(decoding: surface.fed, as: UTF8.self).contains("later") })
    await fixture.finish()
}

@MainActor @Test func closingAConnectedShellAsksFirst() async throws {
    let fixture = try await Fixture(answer: .open)
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.focusedSnapshot?.phase == .connected })
    let entry = try #require(fixture.model.shells.shells.first)
    fixture.model.requestClose(entry)
    #expect(fixture.model.closingShell == entry)
    #expect(fixture.model.shells.shells.count == 1)
    await fixture.finish()
}

@MainActor @Test func remoteOutputNeverWritesTheClipboard() {
    let surface = SwiftTermSurface()
    UIPasteboard.general.string = "kept"
    let payload = Data("stolen".utf8).base64EncodedString()
    surface.feed(Data("\u{1B}]52;c;\(payload)\u{07}".utf8))
    #expect(UIPasteboard.general.string == "kept")
}
