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
    let clock: ManualClock
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
    return Rig(engine: engine, transport: transport, cache: cache, clock: clock)
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

// MARK: Rate limits and read-only refusals

private func rateLimited(after seconds: Int) -> FakeTransport.Result {
    .json(
        429, #"{"error":"rate_limited","message":"Slow down."}"#, headers: ["Retry-After": String(seconds)])
}

@Test func aRateLimitedActionWaitsForRetryAfterAndSendsAgainOnce() async throws {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/stop"
    await rig.transport.on(route, rateLimited(after: 4), ok)
    async let outcome = rig.engine.stopTurn()
    await rig.transport.waitForRequests(1, on: route)
    await rig.clock.waitForSleeper(endingAt: rig.clock.offset + .seconds(4))
    #expect(await rig.transport.requests(on: route).count == 1)
    await rig.clock.advance(by: .seconds(4))
    #expect(await outcome == .done)
    #expect(await rig.transport.requests(on: route).count == 2)
}

@Test func aLongWaitShowsTheTakingLongerLineAfterFiveSecondsAndClearsWhenDone() async throws {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/stop"
    await rig.transport.on(route, rateLimited(after: 9), ok)
    let snapshots = StreamRecorder(await rig.engine.snapshots())
    async let outcome = rig.engine.stopTurn()
    await rig.clock.waitForSleeper(endingAt: rig.clock.offset + .seconds(9))
    await rig.clock.waitForSleeper(endingAt: rig.clock.offset + .seconds(5))
    await rig.clock.advance(by: .seconds(5))
    _ = try await snapshots.next { $0.isActionSlow }
    await rig.clock.advance(by: .seconds(4))
    #expect(await outcome == .done)
    _ = try await snapshots.next { !$0.isActionSlow }
}

@Test func aShortWaitNeverShowsTheLine() async throws {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/stop"
    await rig.transport.on(route, rateLimited(after: 1), ok)
    async let outcome = rig.engine.stopTurn()
    await rig.clock.waitForSleeper(endingAt: rig.clock.offset + .seconds(1))
    await rig.clock.advance(by: .seconds(1))
    #expect(await outcome == .done)
    let last = try await StreamRecorder(await rig.engine.snapshots()).next { _ in true }
    #expect(!last.isActionSlow)
}

@Test func aSecondRateLimitIsShownAsTheServersRefusal() async throws {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/stop"
    await rig.transport.on(route, rateLimited(after: 1))
    async let outcome = rig.engine.stopTurn()
    await rig.clock.waitForSleeper(endingAt: rig.clock.offset + .seconds(1))
    await rig.clock.advance(by: .seconds(1))
    #expect(await outcome == .refused(code: "rate_limited", message: "Slow down.", fieldErrors: [:]))
    #expect(await rig.transport.requests(on: route).count == 2)
}

@Test func aVeryLongRetryAfterIsNotWaitedOut() async throws {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/stop"
    await rig.transport.on(route, rateLimited(after: 120))
    #expect(await rig.engine.stopTurn() == .refused(code: "rate_limited", message: "Slow down.", fieldErrors: [:]))
    #expect(await rig.transport.requests(on: route).count == 1)
}

@Test func closingTheEngineEndsTheWaitWithoutASecondRequest() async throws {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/stop"
    await rig.transport.on(route, rateLimited(after: 10), ok)
    async let outcome = rig.engine.stopTurn()
    await rig.clock.waitForSleeper(endingAt: rig.clock.offset + .seconds(10))
    await rig.engine.close()
    #expect(await outcome == .failed(.offline))
    #expect(await rig.transport.requests(on: route).count == 1)
}

private func forbiddenStop(refreshedCanDrive: Bool) async throws -> ConversationSnapshot {
    let rig = try await makeRig()
    let route = "POST threads/\(threadID)/stop"
    await rig.transport.on(route, .json(403, #"{"error":"forbidden","message":"Only the owner can do that."}"#))
    let page = String(decoding: try Scenarios.data("scenario-thread-question"), as: UTF8.self)
    await rig.transport.on(
        showRoute,
        .json(200, page.replacingOccurrences(of: "\"canDrive\": true", with: "\"canDrive\": \(refreshedCanDrive)")))
    let outcome = await rig.engine.stopTurn()
    #expect(outcome == .refused(code: "forbidden", message: "Only the owner can do that.", fieldErrors: [:]))
    return try await StreamRecorder(await rig.engine.snapshots()).next { _ in true }
}

@Test func aForbiddenActionLeavesTheServersCanDriveInChargeWhenThePageSaysTheyCanDrive() async throws {
    let snapshot = try await forbiddenStop(refreshedCanDrive: true)
    #expect(snapshot.show?.canDrive == true)
}

@Test func aForbiddenActionShowsTheReadOnlyBarWhenThePageSaysTheyCannotDrive() async throws {
    let snapshot = try await forbiddenStop(refreshedCanDrive: false)
    #expect(snapshot.show?.canDrive == false)
    let bar = ComposerBarState.make(
        composer: snapshot.show?.composer.state, canDrive: snapshot.show?.canDrive, turn: snapshot.turn, notice: nil,
        typedText: "", attachmentCount: 0)
    #expect(bar.unavailable == .readOnly)
}

// MARK: Work actions

private func answer(_ status: String, _ keys: [String: String] = [:]) throws -> FakeTransport.Result {
    var all = keys
    all["status"] = #""\#(status)""#
    return .json(200, String(decoding: try workShowData(all), as: UTF8.self))
}

private func shows(_ rig: Rig) async -> Int { await rig.transport.requests(on: showRoute).count }

@Test func anActionAdoptsTheAnsweredShowWithoutASecondRequest() async throws {
    let rig = try await makeRig(question: false)
    let route = "POST threads/\(threadID)/retry"
    await rig.transport.on(route, try answer("queued", ["card": #""repository_question""#]))
    let before = await shows(rig)
    let recorder = StreamRecorder(await rig.engine.snapshots())
    #expect(await rig.engine.perform(.retry) == .done)
    _ = try await recorder.next { $0.show?.card == .repositoryQuestion }
    #expect(await shows(rig) == before)
    #expect(await rig.transport.requests(on: route).count == 1)
}

@Test func anAnswerOlderThanTheAppliedEventsIsFetchedAgain() async throws {
    let rig = try await makeRig(question: false)
    await rig.engine.receive(
        .event(ThreadEvent(sequence: 1, type: "stream.reset", createdAt: "2026-11-15T09:00:00Z", data: .null)))
    await rig.transport.on("POST threads/\(threadID)/retry", try answer("queued", ["latestSequence": "0"]))
    let before = await shows(rig)
    #expect(await rig.engine.perform(.retry) == .done)
    let settled = rig.clock.offset + ThreadEngine.refreshSettle
    await waitUntil { rig.clock.sleeperDeadlines.contains(settled) }
    await rig.clock.advance(by: ThreadEngine.refreshSettle)
    await waitUntil { await shows(rig) == before + 1 }
}

@Test func aMoveToANewThreadSetsTheReplacement() async throws {
    let rig = try await makeRig(question: false)
    var object = try #require(
        JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-show")) as? [String: Any])
    var thread = try #require(object["thread"] as? [String: Any])
    thread["id"] = "b7c8d9e0"
    object["thread"] = thread
    object["status"] = "moved"
    let moved = String(decoding: try JSONSerialization.data(withJSONObject: object), as: UTF8.self)
    await rig.transport.on("POST threads/\(threadID)/move", .json(200, moved))
    let recorder = StreamRecorder(await rig.engine.snapshots())
    #expect(await rig.engine.perform(.move(repo: "example/web")) == .done)
    let snapshot = try await recorder.next { $0.replacementThreadID == "b7c8d9e0" }
    // The old page keeps its own thread: the new thread's show is not adopted.
    #expect(snapshot.show?.thread.id == threadID)
}

@Test func aMoveOnTheSameThreadAdoptsTheShow() async throws {
    let rig = try await makeRig(question: false)
    await rig.transport.on(
        "POST threads/\(threadID)/move", try answer("moved", ["card": #""repository_question""#]))
    let recorder = StreamRecorder(await rig.engine.snapshots())
    #expect(await rig.engine.perform(.move(repo: "example/web")) == .done)
    let snapshot = try await recorder.next { $0.show?.card == .repositoryQuestion }
    #expect(snapshot.replacementThreadID == nil)
}

@Test func aGitHubFailureIsARefusalWithItsMessage() async throws {
    let rig = try await makeRig(question: false)
    let message = "GitHub refused the pull request: branch is protected."
    await rig.transport.on(
        "POST threads/\(threadID)/co-owner/approve",
        .json(503, #"{"error":"github_failed","message":"\#(message)","details":{"githubStatus":422}}"#))
    #expect(
        await rig.engine.perform(.approve)
            == .refused(code: "github_failed", message: message, fieldErrors: [:], connectURL: nil))
    await rig.transport.on(
        "POST threads/\(threadID)/co-owner/check-access",
        .json(503, #"{"error":"access_check_failed","message":"Yak could not check your GitHub access."}"#))
    guard case .refused("access_check_failed", _, _, _) = await rig.engine.perform(.checkAccess) else {
        Issue.record("not refused")
        return
    }
}

@Test func aTransientServerErrorStaysAFailure() async throws {
    let rig = try await makeRig(question: false)
    await rig.transport.on(
        "POST threads/\(threadID)/request-review",
        .json(502, #"{"error":"server_error","message":"Yak could not handle this request."}"#))
    #expect(
        await rig.engine.perform(.requestReview)
            == .failed(.server(code: "server_error", message: "Yak could not handle this request.")))
    await rig.transport.on(
        "POST threads/\(threadID)/retry-render", .json(503, #"{"error":"unavailable","message":"Try again."}"#))
    #expect(await rig.engine.perform(.retryRender) == .failed(.offline))
}

@Test func notLinkedCarriesTheConnectURL() async throws {
    let rig = try await makeRig(question: false)
    await rig.transport.on(
        "POST threads/\(threadID)/co-owner/approve",
        .json(
            409,
            #"{"error":"not_linked","message":"Link your GitHub account first.","details":{"connectUrl":"https://yak.example.com/connect"}}"#
        ))
    #expect(
        await rig.engine.perform(.approve)
            == .refused(
                code: "not_linked", message: "Link your GitHub account first.", fieldErrors: [:],
                connectURL: "https://yak.example.com/connect"))
}

@Test func aValidationRefusalKeepsItsFieldErrors() async throws {
    let rig = try await makeRig(question: false)
    await rig.transport.on(
        "POST threads/\(threadID)/co-owner/request-changes",
        .json(
            422,
            #"{"error":"validation_failed","message":"Invalid.","details":{"errors":{"feedback":["Say what to change."]}}}"#
        ))
    #expect(
        await rig.engine.perform(.requestChanges(feedback: "x"))
            == .refused(
                code: "validation_failed", message: "Invalid.", fieldErrors: ["feedback": ["Say what to change."]]))
}

@Test func aDoubleTapOnApproveSendsOnce() async throws {
    let rig = try await makeRig(question: false)
    let route = "POST threads/\(threadID)/co-owner/approve"
    await rig.transport.on(route, try answer("approved"))
    await rig.transport.hold(route)
    async let first = rig.engine.perform(.approve)
    await rig.transport.waitForRequests(1, on: route)
    async let second = rig.engine.perform(.approve)
    try await Task.sleep(for: .milliseconds(50))
    await rig.transport.release(route)
    let outcomes = await (first, second)
    #expect(outcomes.0 == .done && outcomes.1 == .done)
    #expect(await rig.transport.requests(on: route).count == 1)
}

@Test func twoDifferentFeedbacksAreTwoRequests() async throws {
    let rig = try await makeRig(question: false)
    let route = "POST threads/\(threadID)/co-owner/request-changes"
    await rig.transport.on(route, try answer("changes_requested"), try answer("changes_requested"))
    #expect(await rig.engine.perform(.requestChanges(feedback: "One")) == .done)
    #expect(await rig.engine.perform(.requestChanges(feedback: "Two")) == .done)
    #expect(await rig.transport.requests(on: route).count == 2)
}

@Test func forbiddenDropsDriveRights() async throws {
    let rig = try await makeRig(question: false)
    await rig.transport.on(
        "POST threads/\(threadID)/cancel", .json(403, #"{"error":"forbidden","message":"Only the owner can do that."}"#)
    )
    await rig.transport.on(showRoute, try .scenario(200, "scenario-thread-show"))
    guard case .refused("forbidden", _, _, _) = await rig.engine.perform(.cancel) else {
        Issue.record("not refused")
        return
    }
    let snapshot = try await StreamRecorder(await rig.engine.snapshots()).next { _ in true }
    #expect(snapshot.show != nil)
}

@Test func aCoOwnerErrorThatIsNotForbiddenKeepsDriveRights() async throws {
    let rig = try await makeRig(question: false)
    await rig.transport.on(
        "POST threads/\(threadID)/co-owner/approve",
        .json(403, #"{"error":"no_write_access","message":"You need write access."}"#))
    _ = await rig.engine.perform(.approve)
    let snapshot = try await StreamRecorder(await rig.engine.snapshots()).next { _ in true }
    #expect(snapshot.show?.canDrive == true)
}

@Test func offlineSendsNothing() async throws {
    let rig = try await makeRig(question: false)
    let route = "POST threads/\(threadID)/retry"
    await rig.transport.on(route, .failure(.notConnectedToInternet))
    let before = await shows(rig)
    #expect(await rig.engine.perform(.retry) == .failed(.offline))
    #expect(await shows(rig) == before)
    await rig.engine.close()
    #expect(await rig.engine.perform(.retry) == .failed(.offline))
    #expect(await rig.transport.requests(on: route).count == 1)
}

@Test func theCandidatesAreAskedOnTheThreadRoute() async throws {
    let rig = try await makeRig(question: false)
    let route = "GET threads/\(threadID)/co-owner/candidates"
    await rig.transport.on(
        route, .json(200, #"{"candidates":[{"id":4,"name":"Test person"}],"isComplete":false}"#),
        .json(200, #"{"candidates":[{"id":4,"name":"Test person"},{"id":5,"name":"Second person"}],"isComplete":true}"#)
    )
    guard case .success(let first) = await rig.engine.coOwnerCandidates() else {
        Issue.record("failed")
        return
    }
    #expect(!first.isComplete && first.candidates.count == 1)
    guard case .success(let second) = await rig.engine.coOwnerCandidates() else {
        Issue.record("failed")
        return
    }
    #expect(second.isComplete && second.candidates.count == 2)
    await rig.transport.on(route, .json(403, #"{"error":"forbidden","message":"No."}"#))
    guard case .failure(.server(let refusal)) = await rig.engine.coOwnerCandidates() else {
        Issue.record("not refused")
        return
    }
    #expect(refusal.status == 403)
}
