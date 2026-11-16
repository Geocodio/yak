import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private actor OrderRecorder {
    private(set) var values: [String] = []
    func append(_ value: String) { values.append(value) }
}

private actor CompletionFlag {
    private(set) var isDone = false
    func finish() { isDone = true }
}

private let baseURL = URL(string: "https://yak.example.com")!

private func makeClient(_ transport: any HTTPTransport) -> APIClient {
    APIClient(
        baseURL: baseURL, token: "yakdev_abc", transport: transport, appVersion: "1.0.0",
        systemDescription: "27.2.1; Test phone", sleep: { _ in })
}

@Test func manualClockWakesSleepersInDeadlineOrder() async throws {
    let clock = ManualClock()
    let order = OrderRecorder()
    let late = Task {
        try await clock.sleep(for: .seconds(5))
        await order.append("late")
    }
    let early = Task {
        try await clock.sleep(for: .seconds(2))
        await order.append("early")
    }
    await clock.waitForSleepers(2)
    #expect(clock.sleeperDeadlines == [.seconds(2), .seconds(5)])
    await clock.advance(by: .seconds(5))
    _ = try await (late.value, early.value)
    #expect(await order.values == ["early", "late"])
    #expect(clock.now() == Date(timeIntervalSince1970: 1_800_000_005))
}

@Test func manualClockKeepsSubSecondDeadlinesExact() async throws {
    let clock = ManualClock()
    let sleeper = Task { try await clock.sleep(for: .milliseconds(100)) }
    await clock.waitForSleeper(endingAt: .milliseconds(100))
    #expect(clock.sleeperDeadlines == [.milliseconds(100)])
    await clock.advance(by: .milliseconds(100))
    try await sleeper.value
}

@Test func advanceLeavesLaterSleepersAsleep() async throws {
    let clock = ManualClock()
    let sleeper = Task { try await clock.sleep(for: .seconds(10)) }
    await clock.waitForSleeper(endingAt: .seconds(10))
    await clock.advance(by: .seconds(9))
    #expect(clock.sleeperDeadlines == [.seconds(10)])
    await clock.advance(by: .seconds(1))
    try await sleeper.value
    #expect(clock.sleeperDeadlines == [])
}

@Test func cancellingASleeperRemovesIt() async throws {
    let clock = ManualClock()
    let sleeper = Task { try await clock.sleep(for: .seconds(3)) }
    await clock.waitForSleepers(1)
    sleeper.cancel()
    await #expect(throws: CancellationError.self) { try await sleeper.value }
    #expect(clock.sleeperDeadlines == [])
}

@Test func sleepingInACancelledTaskThrowsAtOnce() async throws {
    let clock = ManualClock()
    let sleeper = Task {
        withUnsafeCurrentTask { $0?.cancel() }
        try await clock.sleep(for: .seconds(3))
    }
    await #expect(throws: CancellationError.self) { try await sleeper.value }
    #expect(clock.sleeperDeadlines == [])
}

@Test func broadcastGivesTheCurrentValueFirst() async throws {
    let broadcast = Broadcast(1)
    broadcast.send(2)
    let recorder = StreamRecorder(broadcast.stream())
    #expect(try await recorder.next(where: { _ in true }) == 2)
    broadcast.send(3)
    #expect(try await recorder.next(where: { _ in true }) == 3)
    #expect(broadcast.value == 3)
    broadcast.finish()
}

@Test func broadcastStreamEndsOnFinish() async throws {
    let broadcast = Broadcast("a")
    var received: [String] = []
    let stream = broadcast.stream()
    broadcast.send("b")
    broadcast.finish()
    for await value in stream { received.append(value) }
    #expect(received == ["a", "b"])
    var late: [String] = []
    for await value in broadcast.stream() { late.append(value) }
    #expect(late == ["b"])
}

@Test func concurrentSendsLeaveEveryStreamOnTheLastValue() async throws {
    let broadcast = Broadcast(0)
    let streams = (0..<4).map { _ in broadcast.stream() }
    await withTaskGroup(of: Void.self) { group in
        for value in 1...200 { group.addTask { broadcast.send(value) } }
    }
    broadcast.finish()
    let final = broadcast.value
    for stream in streams {
        var last = -1
        for await value in stream { last = value }
        #expect(last == final)
    }
}

@Test func streamRecorderMatchesInOrder() async throws {
    let broadcast = Broadcast(0)
    let recorder = StreamRecorder(broadcast.stream())
    for value in 1...4 { broadcast.send(value) }
    #expect(try await recorder.next(where: { $0 == 2 }) == 2)
    #expect(try await recorder.next(where: { $0.isMultiple(of: 2) }) == 4)
    #expect(await recorder.values == [0, 1, 2, 3, 4])
    broadcast.finish()
}

@Test func streamRecorderTimeoutThrowsInsteadOfCrashing() async {
    let broadcast = Broadcast(0)
    let recorder = StreamRecorder(broadcast.stream())
    await withKnownIssue {
        await #expect(throws: StreamRecorderTimeout.self) {
            try await recorder.next(timeout: .milliseconds(100), where: { $0 == 99 })
        }
    }
    broadcast.finish()
}

@Test func idSequenceCountsUp() {
    let ids = IDSequence()
    #expect(ids.next().uuidString.lowercased() == "00000000-0000-4000-8000-000000000001")
    #expect(ids.next().uuidString.lowercased() == "00000000-0000-4000-8000-000000000002")
}

@Test func scriptedTransportAnswersByRouteAndRepeatsTheLastAnswer() async throws {
    let transport = ScriptedTransport()
    await transport.on("GET me", .json(200, "{}"), .json(500, "{}"))
    let client = makeClient(transport)
    _ = try? await client.send(.me())
    #expect(await transport.requests(on: "GET me").count == 1)
    #expect(await transport.requests.count == 1)
}

@Test func scriptedTransportHoldsARouteUntilReleased() async throws {
    let transport = ScriptedTransport()
    await transport.on(
        "GET instance",
        .json(200, #"{"yak":true,"name":"X","api":{"current":1,"min":1},"pairing":{"qr":true,"address":true}}"#))
    await transport.hold("GET instance")
    let client = makeClient(transport)
    let completion = CompletionFlag()
    let pending = Task {
        defer { Task { await completion.finish() } }
        return try await client.send(.instance())
    }
    await transport.waitForRequests(1, on: "GET instance")
    for _ in 0..<50 { await Task.yield() }
    #expect(await !completion.isDone)
    await transport.release("GET instance")
    #expect(try await pending.value.name == "X")
}

@Test func aHeldRequestThrowsWhenItsTaskIsCancelled() async throws {
    let transport = ScriptedTransport()
    await transport.hold("GET me")
    let pending = Task { try await makeClient(transport).send(.me()) }
    await transport.waitForRequests(1, on: "GET me")
    pending.cancel()
    await #expect(throws: APIFailure.network(.cancelled)) { try await pending.value }
}

@Test func prefixHandlersAnswerAndAnExactRouteWins() async throws {
    let transport = ScriptedTransport()
    await transport.on(prefix: "GET threads/") { _ in .json(200, #"{"from":"prefix"}"#) }
    await transport.on("GET threads/d0e515ef/events", .json(200, #"{"from":"exact"}"#))
    var request = URLRequest(url: baseURL.appendingPathComponent("api/app/v1/threads/d0e515ef/events"))
    request.url = URL(string: request.url!.absoluteString + "?after=0")
    let (exact, _) = try await transport.send(request)
    #expect(String(decoding: exact, as: UTF8.self).contains("exact"))
    let other = URLRequest(url: baseURL.appendingPathComponent("api/app/v1/threads/zzzz"))
    let (viaPrefix, _) = try await transport.send(other)
    #expect(String(decoding: viaPrefix, as: UTF8.self).contains("prefix"))
}

@Test func unknownRoutesAnswerNotFound() async throws {
    let transport = ScriptedTransport()
    do {
        _ = try await makeClient(transport).send(.me())
        Issue.record("expected a failure")
    } catch {
        #expect(error == .server(APIError(status: 404, code: "not_found", message: "Not found.", details: [:])))
    }
}

@Test(arguments: [
    "scenario-thread-list", "scenario-thread-show", "scenario-thread-question", "scenario-thread-new",
    "scenario-thread-store", "scenario-message-sent", "scenario-message-queued", "scenario-message-clear",
    "scenario-thread-events", "scenario-thread-event", "scenario-broadcasting-auth-private",
    "scenario-broadcasting-auth-presence", "events/simple-turn", "events/steer", "events/question",
    "events/failed-turn", "events/stream-reset", "events/pointer", "scenario-thread-repository-access",
])
func everyScenarioLoads(name: String) throws {
    #expect(try JSONSerialization.jsonObject(with: Scenarios.data(name)) is [String: Any])
}

@Test func scenarioResultsCarryTheirBody() throws {
    guard case .response(let status, let body, _) = try FakeTransport.Result.scenario(201, "scenario-thread-store")
    else {
        Issue.record("expected a response")
        return
    }
    #expect(status == 201 && body.contains("k7m2q9rt"))
}

@Test func settingAValueChangesOnlyThatValue() throws {
    let data = try Scenarios.data("scenario-thread-store", setting: ["id"], toJSON: #""changed""#)
    #expect(try JSONSerialization.jsonObject(with: data) as? [String: String] == ["id": "changed"])
}

@Test func scenariosAndFixturesNeverMix() throws {
    #expect(Fixtures.exists("me"))
    #expect(!Fixtures.exists("scenario-thread-show"))
    #expect(throws: (any Error).self) { try Scenarios.data("me") }
    #expect(throws: (any Error).self) { try Fixtures.data("scenario-thread-show") }
    let folder = URL(fileURLWithPath: #filePath).deletingLastPathComponent().appendingPathComponent("Scenarios")
    let names = try FileManager.default.contentsOfDirectory(atPath: folder.path)
        .filter { $0 != "README.md" && $0 != "events" }
    #expect(!names.isEmpty)
    #expect(names.allSatisfy { $0.hasPrefix("scenario-") })
}

@Test(arguments: ["simple-turn", "steer", "question", "failed-turn", "stream-reset", "pointer"])
func eventListsAreInOrderWithoutRepeats(name: String) throws {
    let list = try #require(try JSONSerialization.jsonObject(with: Scenarios.data("events/\(name)")) as? [String: Any])
    let broadcasts = try #require(list["broadcasts"] as? [[String: Any]])
    let sequences = broadcasts.compactMap { $0["sequence"] as? Int }
    #expect(sequences == Array(1...broadcasts.count))
    let catchUp = try #require(list["catchUp"] as? [String: Any])
    #expect(catchUp["latestSequence"] as? Int == broadcasts.count)
    let caughtUp = try #require(catchUp["events"] as? [[String: Any]])
    #expect(caughtUp.allSatisfy { !["delta", "stream.reset"].contains($0["type"] as? String ?? "") })
    #expect(caughtUp.allSatisfy { $0["data"] != nil })
    let pointerSequences = broadcasts.filter { $0["pointer"] as? Bool == true }.compactMap { $0["sequence"] as? Int }
    let pointers = try #require(list["pointers"] as? [[String: Any]])
    #expect(pointers.compactMap { $0["sequence"] as? Int } == pointerSequences)
    #expect(pointers.allSatisfy { ($0["event"] as? [String: Any])?["data"] != nil })
}

@Test func theScenarioListsDecodeAsTheirModelsWillExpect() throws {
    let list = try #require(
        try JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-list")) as? [String: Any])
    #expect(list["needsYouCount"] as? Int == 2)
    #expect(try decoded([String: JSONValue].self, "scenario-thread-store")["id"] == .string("k7m2q9rt"))
}
