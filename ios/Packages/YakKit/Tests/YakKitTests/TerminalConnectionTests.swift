import Foundation
import Testing

@testable import YakKit

private let threadID = "d0e515ef"
private let route = "POST threads/\(threadID)/terminal-sessions"
private let origin = "https://yak.example.com"
private let banner = Data("Example sandbox\r\n".utf8)

private struct Rig {
    let connection: TerminalConnection
    let transport: ScriptedTransport
    let connector: FakeTerminalConnector
    let clock: ManualClock
    let phases: StreamRecorder<TerminalSnapshot>
    let output: StreamRecorder<TerminalOutput>

    /// Waits for the next snapshot whose phase matches.
    func phase(where predicate: @escaping @Sendable (TerminalPhase) -> Bool) async throws {
        _ = try await phases.next { predicate($0.phase) }
    }

    func currentPhase() async -> TerminalPhase {
        for await snapshot in await connection.snapshots() { return snapshot.phase }
        return .idle
    }

    /// Wakes the one sleeper that ends `duration` from now, after it has registered.
    func step(_ duration: Duration) async {
        await clock.waitForSleeper(endingAt: clock.offset + duration)
        await clock.advance(by: duration)
    }
}

private func makeRig(
    sandboxState: String = "paused", allowsInsecure: Bool = false, timings: TerminalTimings = .standard
) async -> Rig {
    let clock = ManualClock()
    let transport = ScriptedTransport()
    let connector = FakeTerminalConnector()
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let connection = TerminalConnection(
        threadID: threadID, client: client, origin: origin, connector: connector, clock: clock,
        allowsInsecure: allowsInsecure, sandboxState: sandboxState, timings: timings)
    return Rig(
        connection: connection, transport: transport, connector: connector, clock: clock,
        phases: StreamRecorder(await connection.snapshots()), output: StreamRecorder(await connection.output()))
}

private func shell(banner shown: Data? = banner) async -> FakeTerminalSocket {
    let socket = FakeTerminalSocket()
    if let shown { await socket.serverSends(.output(shown)) }
    return socket
}

private func isConnected(_ phase: TerminalPhase) -> Bool { phase == .connected }

private func scenarioResult(_ status: Int, _ name: String) throws -> FakeTransport.Result {
    try .scenario(status, name)
}

@Test func opensAShellThroughTheThreeSteps() async throws {
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    await rig.connector.enqueue(.socket(await shell()))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase(where: isConnected)
    let names = await rig.phases.values.map { $0.phase.logName }
    #expect(names.contains("opening"))
    var steps: [TerminalStep] = []
    for snapshot in await rig.phases.values {
        if case .opening(let step, _, _) = snapshot.phase, steps.last != step { steps.append(step) }
    }
    #expect(steps == [.sandbox, .link, .shell])
    let connection = try #require(await rig.connector.connections.first)
    #expect(connection.url.absoluteString == "wss://yak.example.com/terminal/ws?token=t3rm7k3n&cols=80&rows=24")
    #expect(connection.origin == origin)
    #expect(connection.handshakeTimeout == .seconds(15))
    let first = try await rig.output.next { _ in true }
    #expect(first == .bytes(banner))
    let request = try #require(await rig.transport.requests(on: route).first)
    #expect(request.value(forHTTPHeaderField: "Origin") == origin)
    await rig.connection.close()
}

@Test func waitsForASlotThenOpens() async throws {
    let rig = await makeRig()
    await rig.transport.on(
        route, try scenarioResult(202, "scenario-terminal-waiting"),
        try scenarioResult(202, "scenario-terminal-waiting"),
        try scenarioResult(200, "scenario-terminal-session"))
    await rig.connector.enqueue(.socket(await shell()))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase { if case .waitingForSlot(.personShare, _) = $0 { true } else { false } }
    await rig.step(.seconds(2))
    await rig.step(.seconds(2))
    try await rig.phase(where: isConnected)
    #expect(await rig.transport.requests(on: route).count == 3)
    await rig.connection.close()
}

@Test func stopsWaitingAfterTwoMinutes() async throws {
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(202, "scenario-terminal-waiting"))
    await rig.connection.start(columns: 80, rows: 24)
    for _ in 0..<60 { await rig.step(.seconds(2)) }
    try await rig.phase { $0 == .waitedTooLong }
    #expect(await rig.transport.requests(on: route).count <= 61)
    await rig.connection.reconnectNow()
    await rig.transport.waitForRequests(62, on: route)
    await rig.connection.close()
}

@Test func aQueuedRestoreShowsRestoringNotASlotWait() async throws {
    let rig = await makeRig()
    await rig.transport.on(
        route, try scenarioResult(202, "scenario-terminal-restoring"),
        try scenarioResult(202, "scenario-terminal-restoring"), try scenarioResult(200, "scenario-terminal-session"))
    await rig.connector.enqueue(.socket(await shell()))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase { if case .restoring = $0 { true } else { false } }
    await rig.step(.seconds(2))
    await rig.step(.seconds(2))
    try await rig.phase(where: isConnected)
    let waited = await rig.phases.values.contains { if case .waitingForSlot = $0.phase { true } else { false } }
    #expect(!waited)
    await rig.connection.close()
}

@Test func theRestoreHintCoversAServerWithoutTheRestoringKey() async throws {
    let hinted = await makeRig()
    await hinted.transport.on(route, try scenarioResult(202, "scenario-terminal-waiting"))
    await hinted.connection.restoreStarted()
    await hinted.connection.start(columns: 80, rows: 24)
    try await hinted.phase { if case .restoring = $0 { true } else { false } }
    await hinted.connection.close()

    let plain = await makeRig()
    await plain.transport.on(route, try scenarioResult(202, "scenario-terminal-waiting"))
    await plain.connection.start(columns: 80, rows: 24)
    try await plain.phase { if case .waitingForSlot = $0 { true } else { false } }
    await plain.connection.close()
}

@Test func restoringOutlastsTheSlotCap() async throws {
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(202, "scenario-terminal-restoring"))
    await rig.connection.start(columns: 80, rows: 24)
    for _ in 0..<60 { await rig.step(.seconds(2)) }
    if case .restoring = await rig.currentPhase() {} else { Issue.record("not restoring at 120 seconds") }
    for _ in 0..<240 { await rig.step(.seconds(2)) }
    if case .restoring = await rig.currentPhase() {} else { Issue.record("not restoring at 600 seconds") }
    for _ in 0..<150 { await rig.step(.seconds(2)) }
    try await rig.phase { $0 == .stillRestoring }
    #expect(await rig.transport.requests(on: route).count <= 451)
    await rig.connection.reconnectNow()
    await rig.transport.waitForRequests(452, on: route)
    await rig.connection.close()
}

@Test(arguments: [("paused", 8), ("stopped", 30)])
func slowLineFollowsTheSandboxState(state: String, seconds: Int) async throws {
    let rig = await makeRig(sandboxState: state)
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    await rig.transport.hold(route)
    await rig.connection.start(columns: 80, rows: 24)
    await rig.clock.waitForSleeper(endingAt: .seconds(seconds))
    await rig.clock.advance(by: .seconds(seconds - 1))
    for _ in 0..<50 { await Task.yield() }
    let before = await rig.phases.values.last?.phase
    if case .opening(.sandbox, _, let isSlow)? = before { #expect(!isSlow) } else { Issue.record("not opening") }
    await rig.clock.advance(by: .seconds(1))
    try await rig.phase { if case .opening(.sandbox, _, true) = $0 { true } else { false } }
    await rig.connection.close()
}

private struct Refusal: Sendable, CustomTestStringConvertible {
    let status: Int
    let code: String
    let details: String
    let expected: TerminalPhase
    var testDescription: String { "\(code) \(details)" }
}

private let refusals: [Refusal] = [
    Refusal(
        status: 403, code: "origin_not_allowed", details: "{}", expected: .refused(.originNotAllowed(message: "Said."))),
    Refusal(
        status: 409, code: "origin_not_allowed", details: "{}", expected: .refused(.originNotAllowed(message: "Said."))),
    Refusal(status: 403, code: "forbidden", details: "{}", expected: .refused(.forbidden)),
    Refusal(status: 409, code: "thread_closed", details: "{}", expected: .refused(.threadClosed)),
    Refusal(status: 409, code: "thread_owned_by_device", details: "{}", expected: .refused(.onComputer)),
    Refusal(status: 409, code: "sandbox_destroyed", details: "{}", expected: .refused(.sandboxRemoved)),
    Refusal(status: 409, code: "no_sandbox", details: "{}", expected: .refused(.noSandbox)),
    Refusal(
        status: 409, code: "thread_blocked", details: #"{"reason":"restore_failed"}"#,
        expected: .refused(.restoreFailed(message: "Said."))),
    Refusal(
        status: 409, code: "thread_blocked", details: #"{"reason":"usage_limit"}"#,
        expected: .refused(.blocked(reason: "usage_limit", message: "Said."))),
    Refusal(
        status: 422, code: "something_else", details: "{}",
        expected: .refused(.other(code: "something_else", message: "Said."))),
]

@Test(arguments: refusals)
private func everyRefusalHasItsPhase(refusal: Refusal) async throws {
    let rig = await makeRig()
    await rig.transport.on(
        route,
        .json(
            refusal.status,
            #"{"error":"\#(refusal.code)","message":"Said.","details":\#(refusal.details)}"#))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase { $0 == refusal.expected }
    for _ in 0..<5 { await rig.clock.advance(by: .seconds(12)) }
    #expect(await rig.transport.requests(on: route).count == 1)
    await rig.connection.close()
}

@Test func failuresOfTheRequestMapToProblems() async throws {
    let unavailable = await makeRig()
    await unavailable.transport.on(route, .json(503, #"{"error":"unavailable","message":"Try again."}"#))
    await unavailable.connection.start(columns: 80, rows: 24)
    try await unavailable.phase { $0 == .failed(.offline) }
    await unavailable.connection.close()

    let down = await makeRig()
    await down.transport.on(route, .failure(.notConnectedToInternet))
    await down.connection.networkChanged(isOnline: false)
    await down.connection.start(columns: 80, rows: 24)
    try await down.phase { $0 == .offline }
    await down.connection.close()

    let flaky = await makeRig()
    await flaky.transport.on(route, .failure(.timedOut))
    await flaky.connection.start(columns: 80, rows: 24)
    try await flaky.phase { $0 == .failed(.offline) }
    await flaky.connection.close()
}

@Test func aNetworkErrorDuringAReconnectSeriesIsAnotherTry() async throws {
    let rig = await makeRig()
    await rig.transport.on(
        route, try scenarioResult(200, "scenario-terminal-session"), .failure(.networkConnectionLost),
        try scenarioResult(200, "scenario-terminal-session"))
    let first = await shell(banner: Data("A".utf8))
    await first.closeWith(code: 1006)
    await rig.connector.enqueue(.socket(first), .socket(await shell(banner: Data("B".utf8))))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase { if case .lost(1, _) = $0 { true } else { false } }
    await rig.step(.seconds(1))
    try await rig.phase { if case .lost(2, _) = $0 { true } else { false } }
    await rig.step(.seconds(2))
    try await rig.phase(where: isConnected)
    #expect(await rig.transport.requests(on: route).count == 3)
    await rig.connection.close()
}

private struct Ending: Sendable, CustomTestStringConvertible {
    let name: String
    var testDescription: String { name }
}

@Test(arguments: ["exit", "idle", "too-many", "denied", "protocol", "lifetime", "error"].map(Ending.init))
private func everyEndingHasItsPhase(ending: Ending) async throws {
    struct Scenario: Decodable {
        struct Entry: Decodable {
            let name: String
            let exitCode: Int?
            let close: Int
        }
        let endings: [Entry]
    }
    let entry = try #require(
        try decoded(Scenario.self, "scenario-terminal-frames").endings.first { $0.name == ending.name })
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    let socket = await shell()
    if let code = entry.exitCode { await socket.serverSends(.control(#"{"type":"exit","code":\#(code)}"#)) }
    await socket.closeWith(code: entry.close)
    await rig.connector.enqueue(.socket(socket), .handshakeFails)
    await rig.connection.start(columns: 80, rows: 24)
    switch ending.name {
    case "exit": try await rig.phase { $0 == .ended(exitCode: 0) }
    case "idle": try await rig.phase { $0 == .idleClosed }
    case "too-many": try await rig.phase { $0 == .tooMany }
    case "denied": try await rig.phase { $0 == .denied }
    case "protocol": try await rig.phase { $0 == .broken }
    default:
        try await rig.phase { if case .lost(1, _) = $0 { true } else { false } }
        await rig.step(.seconds(1))
        await rig.transport.waitForRequests(2, on: route)
        await rig.connection.close()
        return
    }
    for _ in 0..<5 { await rig.clock.advance(by: .seconds(12)) }
    #expect(await rig.transport.requests(on: route).count == 1)
    await rig.connection.close()
}

@Test func reconnectBacksOffAndGivesUp() async throws {
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    await rig.connector.enqueue(.handshakeFails)
    await rig.connection.start(columns: 80, rows: 24)
    var starts: [Duration] = []
    for (index, delay) in [1, 2, 4, 8, 8].enumerated() {
        try await rig.phase { if case .lost(let attempt, _) = $0 { attempt == index + 1 } else { false } }
        starts.append(rig.clock.offset)
        await rig.step(.seconds(delay))
    }
    try await rig.phase { $0 == .couldNotReconnect }
    #expect(starts == [0, 1, 3, 7, 15].map { Duration.seconds($0) })
    #expect(await rig.transport.requests(on: route).count == 6)
    await rig.connection.close()
}

@Test func aReconnectOpensANewShellWithOneDivider() async throws {
    let rig = await makeRig()
    let dropped = rig.clock.now()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    let first = await shell(banner: Data("A".utf8))
    await first.closeWith(code: 1006)
    await rig.connector.enqueue(.socket(first), .socket(await shell(banner: Data("B".utf8))))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase { if case .lost = $0 { true } else { false } }
    await rig.step(.seconds(1))
    try await rig.phase(where: isConnected)
    _ = try await rig.output.next { $0 == .bytes(Data("B".utf8)) }
    let values = await rig.output.values
    #expect(values == [.bytes(Data("A".utf8)), .newShell(droppedAt: dropped), .bytes(Data("B".utf8))])
    await rig.connection.close()
}

@Test func offlineWaitsWithoutCountingTries() async throws {
    let rig = await makeRig()
    let dropped = rig.clock.now()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    await rig.connector.enqueue(
        .socket(await shell(banner: Data("A".utf8))), .socket(await shell(banner: Data("B".utf8))))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase(where: isConnected)
    await rig.connection.networkChanged(isOnline: false)
    try await rig.phase { $0 == .offline }
    await rig.clock.advance(by: .seconds(60))
    #expect(await rig.transport.requests(on: route).count == 1)
    await rig.connection.networkChanged(isOnline: true)
    _ = try await rig.output.next { $0 == .bytes(Data("B".utf8)) }
    #expect(await rig.transport.requests(on: route).count == 2)
    #expect(await rig.output.values == [.bytes(Data("A".utf8)), .newShell(droppedAt: dropped), .bytes(Data("B".utf8))])
    await rig.connection.close()
}

@Test func keysOnlyGoToAConnectedShell() async throws {
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    await rig.transport.hold(route)
    let socket = await shell()
    await rig.connector.enqueue(.socket(socket))
    await rig.connection.start(columns: 80, rows: 24)
    await rig.transport.waitForRequests(1, on: route)
    await rig.connection.send([0x6C])
    _ = try await rig.phases.next { $0.droppedKeys }
    #expect(await socket.sentBytes.isEmpty)
    await rig.transport.release(route)
    _ = try await rig.phases.next { $0.phase == .connected && !$0.droppedKeys }
    await rig.connection.send([0x6C, 0x73])
    #expect(await socket.sentBytes == [Data([0x6C, 0x73])])
    await rig.connection.close()
}

@Test func resizeIsSentOnlyWhenItChanges() async throws {
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    let first = await shell()
    let second = await shell()
    await rig.connector.enqueue(.socket(first), .socket(second))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase(where: isConnected)
    await rig.connection.resize(columns: 80, rows: 24)
    #expect(await first.sentTexts.isEmpty)
    await rig.connection.resize(columns: 100, rows: 30)
    #expect(await first.sentTexts == [BridgeControl.resize(cols: 100, rows: 30)])
    await first.closeWith(code: 1006)
    try await rig.phase { if case .lost = $0 { true } else { false } }
    await rig.step(.seconds(1))
    await rig.transport.waitForRequests(2, on: route)
    for _ in 0..<200 where await rig.connector.connections.count < 2 { await Task.yield() }
    let url = try #require(await rig.connector.connections.last?.url.absoluteString)
    #expect(url.hasSuffix("cols=100&rows=30"))
    await rig.connection.close()
}

@Test func aPlainWebSocketAddressNeedsTestMode() async throws {
    let plain = try Scenarios.data(
        "scenario-terminal-session", setting: ["url"], toJSON: #""ws://yak.example.com/terminal/ws?token=t3rm7k3n""#)
    let body = String(decoding: plain, as: UTF8.self)

    let refused = await makeRig()
    await refused.transport.on(route, .json(200, body))
    await refused.connection.start(columns: 80, rows: 24)
    try await refused.phase {
        $0
            == .failed(
                .server(code: "bad_terminal_url", message: "The server sent a terminal address the app cannot use."))
    }
    #expect(await refused.connector.connections.isEmpty)
    await refused.connection.close()

    let allowed = await makeRig(allowsInsecure: true)
    await allowed.transport.on(route, .json(200, body))
    await allowed.connector.enqueue(.socket(await shell()))
    await allowed.connection.start(columns: 80, rows: 24)
    try await allowed.phase(where: isConnected)
    await allowed.connection.close()
}

@Test func suspendAndResumeOpenANewShell() async throws {
    let rig = await makeRig()
    let dropped = rig.clock.now()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    let first = await shell(banner: Data("A".utf8))
    await rig.connector.enqueue(.socket(first), .socket(await shell(banner: Data("B".utf8))))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase(where: isConnected)
    await rig.connection.suspend()
    try await rig.phase { $0 == .offline }
    #expect(await first.isClosed)
    #expect(await first.closeCode == 1000)
    await rig.connection.networkChanged(isOnline: true)
    #expect(await rig.transport.requests(on: route).count == 1)
    await rig.connection.resume()
    _ = try await rig.output.next { $0 == .bytes(Data("B".utf8)) }
    #expect(await rig.transport.requests(on: route).count == 2)
    #expect(await rig.output.values == [.bytes(Data("A".utf8)), .newShell(droppedAt: dropped), .bytes(Data("B".utf8))])
    await rig.connection.close()
}

@Test func closeStopsEverything() async throws {
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(202, "scenario-terminal-waiting"))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase { if case .waitingForSlot = $0 { true } else { false } }
    await rig.connection.close()
    await rig.clock.advance(by: .seconds(60))
    #expect(await rig.transport.requests(on: route).count == 1)
    for await _ in await rig.connection.snapshots() {}
    for await _ in await rig.connection.output() {}
    await rig.connection.reconnectNow()
    #expect(await rig.transport.requests(on: route).count == 1)
}

@Test func neverSendsAPing() async throws {
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    let socket = await shell()
    await rig.connector.enqueue(.socket(socket))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase(where: isConnected)
    for _ in 0..<40 { await rig.clock.advance(by: .seconds(60)) }
    #expect(await socket.sentTexts.allSatisfy { !$0.contains("ping") })
    await rig.connection.close()
}

@Test func aSilentShellCountsAsALostTry() async throws {
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    let silent = await shell(banner: nil)
    await rig.connector.enqueue(.socket(silent), .socket(await shell()))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase { if case .opening(.shell, _, _) = $0 { true } else { false } }
    await rig.step(.seconds(15))
    try await rig.phase { if case .lost(1, _) = $0 { true } else { false } }
    #expect(await silent.isClosed)
    await rig.step(.seconds(1))
    try await rig.phase(where: isConnected)
    await rig.connection.close()
}

@Test func aNewShellAfterAnEndingGetsADivider() async throws {
    let rig = await makeRig()
    let dropped = rig.clock.now()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    let first = await shell(banner: Data("A".utf8))
    await first.serverSends(.control(#"{"type":"exit","code":0}"#))
    await first.closeWith(code: 1000)
    await rig.connector.enqueue(.socket(first), .socket(await shell(banner: Data("B".utf8))))
    await rig.connection.start(columns: 80, rows: 24)
    try await rig.phase { $0 == .ended(exitCode: 0) }
    await rig.connection.reconnectNow()
    _ = try await rig.output.next { $0 == .bytes(Data("B".utf8)) }
    #expect(await rig.output.values == [.bytes(Data("A".utf8)), .newShell(droppedAt: dropped), .bytes(Data("B".utf8))])
    await rig.connection.close()
}

@Test func aSlowOpeningCanBeRetried() async throws {
    let rig = await makeRig()
    await rig.transport.on(route, try scenarioResult(200, "scenario-terminal-session"))
    await rig.transport.hold(route)
    await rig.connection.start(columns: 80, rows: 24)
    await rig.clock.waitForSleeper(endingAt: .seconds(8))
    await rig.clock.advance(by: .seconds(8))
    try await rig.phase { if case .opening(.sandbox, _, true) = $0 { true } else { false } }
    await rig.connection.reconnectNow()
    await rig.transport.waitForRequests(2, on: route)
    await rig.connection.close()
}
