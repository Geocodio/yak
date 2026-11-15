import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let threadID = "d0e515ef"
private let showRoute = "GET threads/\(threadID)"
private let roundKey = "k3m9p2qa:on_failure,notify"
private let ok = FakeTransport.Result.json(200, #"{"status":"ok"}"#)

private struct Rig {
    let engine: ThreadEngine
    let transport: ScriptedTransport
    let cache: Cache
}

/// An engine that is open on the scenario thread with an open question (`question: true`) or without one.
private func makeRig(question: Bool = true, cache sharedCache: Cache? = nil) async throws -> Rig {
    let clock = ManualClock()
    let transport = ScriptedTransport()
    let scenario = question ? "scenario-thread-question" : "scenario-thread-show"
    await transport.on(showRoute, try .scenario(200, scenario))
    await transport.on("POST threads/\(threadID)/seen", .json(200, "{}"))
    await EventServer(threadID: threadID, events: []).install(on: transport)
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let cache = sharedCache ?? Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let engine = ThreadEngine(threadID: threadID, client: client, cache: cache, clock: clock, frameInterval: .zero)
    await engine.open()
    _ = try await StreamRecorder(await engine.snapshots()).next { $0.load == .ready && !$0.isShowingCachedCopy }
    return Rig(engine: engine, transport: transport, cache: cache)
}

private func body(of request: URLRequest) throws -> String {
    String(decoding: try #require(request.httpBody), as: UTF8.self)
}

@Test func stopSendsOnceForADoubleTap() async throws {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/stop"
    await rig.transport.on(route, ok)
    await rig.transport.hold(route)
    let shows = await rig.transport.requests(on: showRoute).count
    async let first = rig.engine.stopTurn()
    await rig.transport.waitForRequests(1, on: route)
    async let second = rig.engine.stopTurn()
    for _ in 0..<50 { await Task.yield() }
    await rig.transport.release(route)
    #expect(await first == .done)
    #expect(await second == .done)
    #expect(await rig.transport.requests(on: route).count == 1)
    #expect(await rig.transport.requests(on: showRoute).count == shows + 1)
}

@Test func aDeliveredQueuedMessageIsAlreadySent() async throws {
    let rig = try await makeRig()
    let route = "PATCH threads/\(threadID)/queued-messages/abc"
    await rig.transport.on(route, .json(409, #"{"error":"message_delivered","message":"Already sent."}"#))
    let shows = await rig.transport.requests(on: showRoute).count
    #expect(await rig.engine.setQueuedMessageMode("abc", mode: .steer) == .alreadySent)
    #expect(await rig.transport.requests(on: showRoute).count == shows + 1)
}

@Test func editTrimsAndRefusesEmptyText() async throws {
    let rig = try await makeRig()
    let route = "PATCH threads/\(threadID)/queued-messages/abc"
    await rig.transport.on(route, ok)
    #expect(
        await rig.engine.editQueuedMessage("abc", text: "  ")
            == .refused(code: "validation_failed", message: "", fieldErrors: ["text": []]))
    #expect(await rig.transport.requests(on: route).isEmpty)
    #expect(await rig.engine.editQueuedMessage("abc", text: " New ") == .done)
    let request = try #require(await rig.transport.requests(on: route).first)
    #expect(try body(of: request) == #"{"text":"New"}"#)
}

@Test func aValidationErrorCarriesTheFieldMessages() async throws {
    let rig = try await makeRig()
    let route = "PATCH threads/\(threadID)/queued-messages/abc"
    await rig.transport.on(
        route,
        .json(
            422,
            #"{"error":"validation_failed","message":"Invalid.","details":{"errors":{"answers.on_failure":["Pick an option."]}}}"#
        ))
    #expect(
        await rig.engine.editQueuedMessage("abc", text: "x")
            == .refused(
                code: "validation_failed", message: "Invalid.",
                fieldErrors: ["answers.on_failure": ["Pick an option."]]))
}

@Test func networkFailureIsOfflineWithoutRefresh() async throws {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/continue"
    await rig.transport.on(route, .failure(.notConnectedToInternet))
    let shows = await rig.transport.requests(on: showRoute).count
    #expect(await rig.engine.continueTurn() == .failed(.offline))
    #expect(await rig.transport.requests(on: showRoute).count == shows)
}

@Test func aServerErrorFailsAndStillRefreshes() async throws {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/restore/retry"
    await rig.transport.on(route, .json(500, #"{"error":"server_error","message":"Boom."}"#))
    let shows = await rig.transport.requests(on: showRoute).count
    #expect(await rig.engine.retryRestore() == .failed(.server(code: "server_error", message: "Boom.")))
    #expect(await rig.transport.requests(on: showRoute).count == shows + 1)
}

@Test func theDraftSurvivesAndIsSent() async throws {
    let first = try await makeRig()
    let draft = QuestionDraft(
        roundKey: roundKey,
        answers: [
            "on_failure": QuestionAnswer(choices: ["Mark the row as failed"]),
            "notify": QuestionAnswer(choices: ["The owner", "The channel"]),
        ])
    await first.engine.updateQuestionDraft(draft)
    await first.engine.close()

    let second = try await makeRig(cache: first.cache)
    #expect(await second.engine.questionDraft() == draft)
    let route = "POST threads/\(threadID)/clarification-answers"
    await second.transport.on(route, ok)
    #expect(await second.engine.answerQuestion() == .done)
    let request = try #require(await second.transport.requests(on: route).first)
    let data = try #require(request.httpBody)
    let sent = try #require(JSONSerialization.jsonObject(with: data) as? NSDictionary)
    let expected: NSDictionary = [
        "answers": [
            "on_failure": ["choices": ["Mark the row as failed"], "other": NSNull()],
            "notify": ["choices": ["The owner", "The channel"], "other": NSNull()],
        ],
        "note": NSNull(),
    ]
    #expect(sent == expected)
    #expect(await second.engine.questionDraft() == nil)
}

@Test func aDraftForAnotherRoundIsIgnored() async throws {
    let rig = try await makeRig()
    await rig.engine.updateQuestionDraft(QuestionDraft(roundKey: "other:x"))
    #expect(await rig.engine.questionDraft() == nil)
}

@Test func aNewRoundDropsTheOldDraft() async throws {
    let rig = try await makeRig()
    await rig.engine.updateQuestionDraft(QuestionDraft(roundKey: roundKey))
    #expect(await rig.engine.questionDraft() != nil)
    let data = try Scenarios.data("scenario-thread-question", setting: ["question", "taskId"], toJSON: #""z9z9z9z9""#)
    await rig.transport.on(showRoute, .json(200, String(decoding: data, as: UTF8.self)))
    await rig.engine.refreshShow()
    #expect(await rig.engine.questionDraft() == nil)
}

@Test func answeringWithoutARoundIsRefusedLocally() async throws {
    let rig = try await makeRig(question: false)
    let route = "POST threads/\(threadID)/clarification-answers"
    #expect(
        await rig.engine.answerQuestion() == .refused(code: "question_closed", message: "", fieldErrors: [:]))
    #expect(await rig.transport.requests(on: route).isEmpty)
}

@Test func withdrawAndModeUseTheMessageUUID() async throws {
    let rig = try await makeRig()
    let uuid = "00000000-0000-4000-8000-000000000009"
    await rig.transport.on("DELETE threads/\(threadID)/queued-messages/\(uuid)", .json(204, ""))
    await rig.transport.on("PATCH threads/\(threadID)/queued-messages/\(uuid)", ok)
    #expect(await rig.engine.withdrawQueuedMessage(uuid) == .done)
    #expect(await rig.engine.setQueuedMessageMode(uuid, mode: .queue) == .done)
    let request = try #require(
        await rig.transport.requests(on: "PATCH threads/\(threadID)/queued-messages/\(uuid)").first)
    #expect(try body(of: request) == #"{"mode":"queue"}"#)
    #expect(await rig.transport.requests(on: "DELETE threads/\(threadID)/queued-messages/\(uuid)").count == 1)
}

@Test func aDifferentEditWhileOneIsInFlightIsSentToo() async throws {
    let rig = try await makeRig()
    let route = "PATCH threads/\(threadID)/queued-messages/abc"
    await rig.transport.on(route, ok)
    await rig.transport.hold(route)
    async let first = rig.engine.editQueuedMessage("abc", text: "A")
    await rig.transport.waitForRequests(1, on: route)
    async let second = rig.engine.editQueuedMessage("abc", text: "B")
    for _ in 0..<50 { await Task.yield() }
    await rig.transport.release(route)
    await rig.transport.waitForRequests(2, on: route)
    await rig.transport.release(route)
    #expect(await first == .done)
    #expect(await second == .done)
    let bodies = try await rig.transport.requests(on: route).map(body(of:))
    #expect(Set(bodies) == [#"{"text":"A"}"#, #"{"text":"B"}"#])
    #expect(bodies.count == 2)
}

@Test func closingCancelsAnActionAndRefusesNewOnes() async throws {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/stop"
    await rig.transport.on(route, ok)
    await rig.transport.hold(route)
    let shows = await rig.transport.requests(on: showRoute).count
    async let running = rig.engine.stopTurn()
    await rig.transport.waitForRequests(1, on: route)
    await rig.engine.close()
    _ = await running
    #expect(await rig.engine.continueTurn() == .failed(.offline))
    #expect(await rig.transport.requests(on: showRoute).count == shows)
    #expect(await rig.transport.requests(on: "POST threads/\(threadID)/continue").isEmpty)
}

@Test func aRefreshWithoutAQuestionDropsTheDraft() async throws {
    let rig = try await makeRig()
    await rig.engine.updateQuestionDraft(QuestionDraft(roundKey: roundKey))
    let data = try Scenarios.data("scenario-thread-show")
    await rig.transport.on(showRoute, .json(200, String(decoding: data, as: UTF8.self)))
    await rig.engine.refreshShow()
    #expect(await rig.engine.questionDraft() == nil)
}

@Test func aDraftSavedOnTheCachedCopySurvivesUntilConfirmed() async throws {
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: ManualClock())
    let rig = try await makeRig(cache: cache)
    await rig.engine.updateQuestionDraft(QuestionDraft(roundKey: roundKey))
    await rig.engine.close()
    // A second engine whose show request is held stays on the cached copy.
    let transport = ScriptedTransport()
    await transport.on(showRoute, try .scenario(200, "scenario-thread-question"))
    await transport.hold(showRoute)
    await transport.on("POST threads/\(threadID)/seen", .json(200, "{}"))
    await EventServer(threadID: threadID, events: []).install(on: transport)
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let engine = ThreadEngine(
        threadID: threadID, client: client, cache: cache, clock: ManualClock(), frameInterval: .zero)
    await engine.open()
    await transport.waitForRequests(1, on: showRoute)
    #expect(await engine.questionDraft() != nil)
    await transport.release(showRoute)
    _ = try await StreamRecorder(await engine.snapshots()).next { $0.load == .ready && !$0.isShowingCachedCopy }
    #expect(await engine.questionDraft() != nil)
    await engine.close()
}
