import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let root = "s7ackr00"
private let addRoute = "POST threads/\(root)/stack/children"
private let stackRoute = "GET threads/\(root)/stack"
private let busySentence = "Yak is still handling another request for this thread. Try again in a moment."
private let fullSentence = "This thread has 12 of 12 sub-threads. Close one to add another."
private let sendKeyMessage = "This key was already used for another request. Try again with a new one."
private let created = SubThreadCreated(id: "n3wch11d", name: "check-the-cache")
private let createdAnswer = try! FakeTransport.Result.scenario(201, "scenario-overview-sub-thread-created")
private let firstID = UUID(uuidString: "00000000-0000-4000-8000-000000000001")!
private let secondID = UUID(uuidString: "00000000-0000-4000-8000-000000000002")!

private func error(_ code: String, _ message: String, details: String = "{}") -> String {
    #"{"error":"\#(code)","message":"\#(message)","details":\#(details)}"#
}

private struct OverviewRig {
    let actions: OverviewActions
    let transport: ScriptedTransport
    let clock: ManualClock
    let files: InMemoryFileStore
    let instance: UUID
    let ids: IDSequence

    init(
        files: InMemoryFileStore = InMemoryFileStore(), instance: UUID = UUID(), ids: IDSequence = IDSequence(),
        clock: ManualClock = ManualClock()
    ) {
        let transport = ScriptedTransport()
        let client = APIClient(
            baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
            appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
        self.transport = transport
        self.clock = clock
        self.files = files
        self.instance = instance
        self.ids = ids
        actions = OverviewActions(
            client: client, cache: Cache(instance: instance, files: files, clock: clock), clock: clock,
            makeID: { ids.next() })
    }

    /// A new actions value over the same disk, as after a relaunch.
    func relaunched() -> OverviewRig { OverviewRig(files: files, instance: instance, ids: ids, clock: clock) }
}

extension ScriptedTransport {
    /// Sets the held route's next answer and lets the request go on.
    fileprivate func answer(held route: String, with result: FakeTransport.Result) {
        on(route, result)
        release(route)
    }
}

private func sendKey(of request: URLRequest) throws -> UUID {
    let body = try #require(request.httpBody)
    let object = try #require(try JSONSerialization.jsonObject(with: body) as? [String: Any])
    let text = try #require(object["send_key"] as? String)
    return try #require(UUID(uuidString: text))
}

private let draft = SubThreadDraft(name: "check-the-cache", brief: "Look at the cache keys.")

@Test func aRepeatedAddNeverMakesTwoSubThreads() async throws {
    let rig = OverviewRig()
    await rig.transport.hold(addRoute)
    async let first = rig.actions.addSubThread(parentID: root, draft: draft)
    async let second = rig.actions.addSubThread(parentID: root, draft: draft)
    await rig.transport.waitForRequests(1, on: addRoute)
    let saved = try #require(await rig.actions.subThreadDraft(parentID: root))
    #expect(saved.sendKey == firstID && saved.sendKeyIsInFlight)
    await rig.transport.answer(held: addRoute, with: .failure(.timedOut))
    let outcomes = await (first, second)
    #expect(outcomes.0 == outcomes.1)
    #expect(outcomes.0 == .notAdded(.failed(.offline)))
    #expect(await rig.transport.requests(on: addRoute).count == 1)

    let again = rig.relaunched()
    let restored = try #require(await again.actions.subThreadDraft(parentID: root))
    #expect(restored.sendKeyIsInFlight)
    await again.transport.on(addRoute, createdAnswer)
    #expect(await again.actions.addSubThread(parentID: root, draft: restored) == .added(created))
    let sent = await again.transport.requests(on: addRoute)
    #expect(try sendKey(of: sent[0]) == firstID)
    #expect(await again.actions.subThreadDraft(parentID: root) == nil)
}

@Test func aRefusalKeepsTheTextAndClearsTheKey() async throws {
    let rig = OverviewRig()
    await rig.transport.on(addRoute, .json(409, error("stack_full", fullSentence)))
    #expect(
        await rig.actions.addSubThread(parentID: root, draft: draft)
            == .notAdded(.refused(code: "stack_full", message: fullSentence, fieldErrors: [:])))
    let saved = try #require(await rig.actions.subThreadDraft(parentID: root))
    #expect(saved.name == "check-the-cache" && saved.brief == "Look at the cache keys.")
    #expect(saved.sendKey == nil && !saved.sendKeyIsInFlight)

    let tooLong = "The name may not be greater than 60 characters."
    await rig.transport.on(
        addRoute, .json(422, error("validation_failed", "Invalid.", details: #"{"errors":{"name":["\#(tooLong)"]}}"#)))
    let outcome = await rig.actions.addSubThread(parentID: root, draft: saved)
    #expect(
        outcome == .notAdded(.refused(code: "validation_failed", message: "Invalid.", fieldErrors: ["name": [tooLong]]))
    )
    let sent = await rig.transport.requests(on: addRoute)
    #expect(try sendKey(of: sent[0]) == firstID)
    #expect(try sendKey(of: sent[1]) == secondID)
    #expect(try #require(await rig.actions.subThreadDraft(parentID: root)).sendKey == nil)
}

@Test func anUnknownOutcomeKeepsTheKeyAndTheText() async throws {
    let rig = OverviewRig()
    let busy = FakeTransport.Result.json(503, error("unavailable", busySentence))
    await rig.transport.on(
        addRoute, .failure(.notConnectedToInternet), .failure(.timedOut), busy, busy, busy, busy)
    for _ in 0..<2 {
        let outcome = await rig.actions.addSubThread(parentID: root, draft: draft)
        #expect(outcome == .notAdded(.failed(.offline)))
        let saved = try #require(await rig.actions.subThreadDraft(parentID: root))
        #expect(saved.sendKey == firstID && saved.sendKeyIsInFlight)
    }
    async let busyAdd = rig.actions.addSubThread(parentID: root, draft: draft)
    for wait in [Duration.seconds(1), .seconds(2)] {
        await rig.clock.waitForSleepers(1)
        await rig.clock.advance(by: wait)
    }
    #expect(
        await busyAdd == .notAdded(.refused(code: "unavailable", message: busySentence, fieldErrors: [:])))
    #expect(try #require(await rig.actions.subThreadDraft(parentID: root)).sendKeyIsInFlight)

    var edited = draft
    edited.brief = "A different brief."
    await rig.transport.on(addRoute, createdAnswer)
    #expect(await rig.actions.addSubThread(parentID: root, draft: edited) == .added(created))
    let sent = await rig.transport.requests(on: addRoute)
    #expect(try sent.map(sendKey(of:)).allSatisfy { $0 == firstID })
    let lastBody = try #require(sent.last?.httpBody)
    let body = String(decoding: lastBody, as: UTF8.self)
    #expect(body.contains("Look at the cache keys.") && !body.contains("A different brief."))
    #expect(await rig.actions.subThreadDraft(parentID: root) == nil)
}

@Test func aKeyUsedByAnotherPersonIsReplacedOnce() async throws {
    let rig = OverviewRig()
    let keyError = FakeTransport.Result.json(
        422, error("validation_failed", "Invalid.", details: #"{"errors":{"send_key":["\#(sendKeyMessage)"]}}"#))
    await rig.transport.on(addRoute, keyError, createdAnswer)
    #expect(await rig.actions.addSubThread(parentID: root, draft: draft) == .added(created))
    var sent = await rig.transport.requests(on: addRoute)
    #expect(sent.count == 2)
    #expect(try sendKey(of: sent[0]) == firstID && sendKey(of: sent[1]) == secondID)

    let second = OverviewRig()
    await second.transport.on(addRoute, keyError)
    #expect(
        await second.actions.addSubThread(parentID: root, draft: draft)
            == .notAdded(
                .refused(code: "validation_failed", message: "Invalid.", fieldErrors: ["send_key": [sendKeyMessage]])))
    sent = await second.transport.requests(on: addRoute)
    #expect(sent.count == 2)
    let saved = try #require(await second.actions.subThreadDraft(parentID: root))
    #expect(saved.sendKey == nil && !saved.sendKeyIsInFlight)
}

@Test func anInvalidDraftSendsNothing() async {
    let rig = OverviewRig()
    let outcome = await rig.actions.addSubThread(parentID: root, draft: SubThreadDraft(name: "check", brief: "  "))
    #expect(outcome == .notAdded(.refused(code: "validation_failed", message: "", fieldErrors: [:])))
    #expect(await rig.transport.requests.isEmpty)
    #expect(await rig.actions.subThreadDraft(parentID: root) == nil)
}

@Test func theDraftTextIsLockedWhileAKeyIsInFlight() async throws {
    let rig = OverviewRig()
    await rig.transport.on(addRoute, .failure(.timedOut))
    _ = await rig.actions.addSubThread(parentID: root, draft: draft)
    var edited = draft
    edited.name = "other-name"
    await rig.actions.saveSubThreadDraft(edited, parentID: root)
    #expect(try #require(await rig.actions.subThreadDraft(parentID: root)).name == "check-the-cache")

    let other = OverviewRig()
    await other.actions.saveSubThreadDraft(edited, parentID: root)
    #expect(await other.actions.subThreadDraft(parentID: root)?.name == "other-name")
    await other.actions.saveSubThreadDraft(SubThreadDraft(), parentID: root)
    #expect(await other.actions.subThreadDraft(parentID: root) == nil)
}

@Test func aBusyThreadIsRetriedThenExplained() async throws {
    let rig = OverviewRig()
    let route = "POST threads/q7adapt0/stack/take-over"
    let busy = FakeTransport.Result.json(503, error("unavailable", busySentence))
    await rig.transport.on(route, busy, busy, .json(200, #"{"status":"taken_over"}"#))
    async let first = rig.actions.takeOver("q7adapt0")
    for wait in [Duration.seconds(1), .seconds(2)] {
        await rig.clock.waitForSleepers(1)
        await rig.clock.advance(by: wait)
    }
    #expect(await first == .done)
    #expect(await rig.transport.requests(on: route).count == 3)

    let stuck = OverviewRig()
    await stuck.transport.on(route, busy)
    async let second = stuck.actions.takeOver("q7adapt0")
    for wait in [Duration.seconds(1), .seconds(2)] {
        await stuck.clock.waitForSleepers(1)
        await stuck.clock.advance(by: wait)
    }
    #expect(await second == .refused(code: "unavailable", message: busySentence, fieldErrors: [:]))
    #expect(await stuck.transport.requests(on: route).count == 3)
}

@Test func everyActionAnswersAndAnnouncesItsChange() async throws {
    let rig = OverviewRig()
    let changes = StreamRecorder(await rig.actions.changes())
    await rig.transport.on("POST threads/\(root)/stack/stop", .json(200, #"{"status":"stopping"}"#))
    await rig.transport.on(
        "PATCH threads/\(root)/stack/limit",
        .json(200, #"{"status":"updated","limit":{"current":3,"max":4,"awake":1,"waiting":0}}"#))
    await rig.transport.on("POST threads/q7adapt0/stack/split", .json(200, #"{"status":"split_requested"}"#))
    await rig.transport.on("POST threads/q7adapt0/stack/drop", .json(200, #"{"status":"dropped"}"#))
    await rig.transport.on("POST threads/q7adapt0/stack/take-over", .json(200, #"{"status":"taken_over"}"#))
    await rig.transport.on("POST threads/\(root)/repositories/22/retry", .json(200, #"{"status":"retrying"}"#))
    await rig.transport.on(
        "PUT threads/\(root)/share", .json(200, #"{"mode":"people","userIds":[7]}"#))

    #expect(await rig.actions.stopStack(root) == .done)
    _ = try await changes.next { $0 == .stack(threadID: root) }
    let limit = await rig.actions.setAwakeLimit(root, limit: 3)
    #expect(limit.outcome == .done && limit.limit == StackLimit(current: 3, max: 4, awake: 1, waiting: 0))
    #expect(await rig.actions.split("q7adapt0") == .done)
    _ = try await changes.next { $0 == .stack(threadID: "q7adapt0") }
    #expect(await rig.actions.drop("q7adapt0") == .done)
    #expect(await rig.actions.retryRepositorySetup(root, repository: LenientID("22")) == .done)
    _ = try await changes.next { $0 == .environment(threadID: root) }
    #expect(await rig.actions.updateShare(root, mode: .people, userIDs: [7]) == .done)
    _ = try await changes.next { $0 == .share(threadID: root) }
    #expect(await rig.actions.takeOver("q7adapt0") == .done)
    #expect(await rig.actions.takeOver("q7adapt0") == .done)
}

@Test func refusalsKeepTheServersSentence() async throws {
    let rig = OverviewRig()
    let notFailed = "Only a sub-thread that failed can be taken over, split or dropped."
    await rig.transport.on(
        "POST threads/q7adapt0/stack/take-over", .json(409, error("child_not_failed", notFailed)))
    #expect(
        await rig.actions.takeOver("q7adapt0")
            == .refused(code: "child_not_failed", message: notFailed, fieldErrors: [:]))
    await rig.transport.on(
        "POST threads/q7adapt0/stack/drop", .json(403, error("forbidden", "You do not have access to this.")))
    #expect(
        await rig.actions.drop("q7adapt0")
            == .refused(code: "forbidden", message: "You do not have access to this.", fieldErrors: [:]))
    await rig.transport.on(
        "PATCH threads/\(root)/stack/limit",
        .json(422, error("validation_failed", "Invalid.", details: #"{"errors":{"limit":["Too high."]}}"#)))
    let limit = await rig.actions.setAwakeLimit(root, limit: 9)
    #expect(
        limit.outcome == .refused(code: "validation_failed", message: "Invalid.", fieldErrors: ["limit": ["Too high."]])
    )
    #expect(limit.limit == nil)
    let missing = await rig.actions.split("zzzzzzzz")
    #expect(missing == .refused(code: "not_found", message: "Not found.", fieldErrors: [:]))
}

@Test func aRepeatedRetryThatFindsNothingToRetryIsDone() async throws {
    let rig = OverviewRig()
    let changes = StreamRecorder(await rig.actions.changes())
    let route = "POST threads/\(root)/repositories/22/retry"
    await rig.transport.on(
        route, .json(409, error("nothing_to_retry", "Only a failed setup of an extra repository can be retried.")))
    #expect(await rig.actions.retryRepositorySetup(root, repository: LenientID("22")) == .done)
    _ = try await changes.next { $0 == .environment(threadID: root) }
    #expect(await rig.transport.requests(on: route).count == 1)
}

@Test func stackLookupsAreSharedAndFresh() async throws {
    let rig = OverviewRig()
    await rig.transport.on(stackRoute, try .scenario(200, "scenario-overview-busy-stack"))
    await rig.transport.hold(stackRoute)
    async let first = rig.actions.stack(root)
    async let second = rig.actions.stack(root)
    await rig.transport.waitForRequests(1, on: stackRoute)
    await rig.transport.release(stackRoute)
    let both = try await (first, second)
    #expect(both.0.stack == both.1.stack)
    #expect(await rig.transport.requests(on: stackRoute).count == 1)
    #expect(await rig.actions.cachedStack(root)?.stack == both.0.stack)

    await rig.clock.advance(by: .seconds(5))
    _ = try await rig.actions.stack(root)
    #expect(await rig.transport.requests(on: stackRoute).count == 1)
    await rig.clock.advance(by: .seconds(5))
    _ = try await rig.actions.stack(root)
    #expect(await rig.transport.requests(on: stackRoute).count == 2)

    await rig.transport.on("POST threads/q7adapt0/stack/drop", .json(200, #"{"status":"dropped"}"#))
    #expect(await rig.actions.drop("q7adapt0") == .done)
    #expect(await rig.actions.cachedStack(root) == nil)
    _ = try await rig.actions.stack(root)
    #expect(await rig.transport.requests(on: stackRoute).count == 3)
}

@Test func aFailedStackLookupKeepsNothingAndTheCacheHoldsTwentyStacks() async throws {
    let rig = OverviewRig()
    await rig.transport.on(stackRoute, .json(500, error("server_error", "Broken.")))
    await #expect(throws: APIFailure.self) { _ = try await rig.actions.stack(root) }
    #expect(await rig.actions.cachedStack(root) == nil)

    await rig.transport.on(
        prefix: "GET threads/"
    ) { _ in
        try! .scenario(200, "scenario-overview-no-sub-threads")
    }
    for index in 0..<21 { _ = try await rig.actions.stack(String(format: "t%07d", index)) }
    #expect(await rig.actions.cachedStack("t0000000") == nil)
    #expect(await rig.actions.cachedStack("t0000020") != nil)
}

@Test func theDraftLeavesWithItsThread() async throws {
    let rig = OverviewRig()
    let cache = Cache(instance: rig.instance, files: rig.files, clock: rig.clock)
    await rig.actions.saveSubThreadDraft(draft, parentID: root)
    #expect(await rig.actions.subThreadDraft(parentID: root) != nil)
    await cache.removeThread(root)
    #expect(await rig.actions.subThreadDraft(parentID: root) == nil)
    await rig.actions.saveSubThreadDraft(draft, parentID: root)
    await cache.eraseAll()
    #expect(await rig.actions.subThreadDraft(parentID: root) == nil)
}

@Test func aDraftThatCannotBeSavedSendsNothing() async throws {
    struct Full: FileStore {
        func read(_ path: String) throws(FileStoreError) -> Data? { nil }
        func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
            throw .unreadable
        }
        func remove(_ path: String) throws(FileStoreError) {}
        func list(_ folder: String) throws(FileStoreError) -> [String] { [] }
    }
    let transport = ScriptedTransport()
    let clock = ManualClock()
    let actions = OverviewActions(
        client: APIClient(
            baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
            appVersion: "1.0", systemDescription: "Test", sleep: { _ in }),
        cache: Cache(instance: UUID(), files: Full(), clock: clock), clock: clock, makeID: { UUID() })
    let outcome = await actions.addSubThread(parentID: root, draft: draft)
    #expect(
        outcome
            == .notAdded(
                .failed(.server(code: "not_saved", message: "The draft could not be saved. Try again."))))
    #expect(await transport.requests.isEmpty)
}

// MARK: Session

private final class SessionDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

@Test func sessionGivesActionsOnlyWhileActive() async throws {
    let clock = ManualClock()
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: SessionDefaults(), tokens: tokens, now: { clock.now() })
    let instance = try await instances.add(
        url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test person", token: "test-token")
    let transport = ScriptedTransport()
    await transport.on("GET me", try .scenario(200, "scenario-me"))
    await transport.on(prefix: "POST broadcasting/auth") { _ in
        try! .scenario(200, "scenario-broadcasting-auth-private")
    }
    let ids = IDSequence()
    let session = InstanceSession(
        instance: instance, instances: instances, tokens: tokens,
        environment: SessionEnvironment(
            http: transport, webSockets: FakeWebSocketServer(), files: InMemoryFileStore(), clock: clock,
            makeID: { ids.next() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false))
    #expect(await session.overviewActions() == nil)
    await session.start()
    #expect(await session.overviewActions() != nil)
    await session.stop()
    #expect(await session.overviewActions() == nil)
}

@Test func anAutosaveNeverWipesTheKeyOfAnAddInFlight() async throws {
    let rig = OverviewRig()
    await rig.transport.hold(addRoute)
    async let added = rig.actions.addSubThread(parentID: root, draft: draft)
    async let autosave: Void = rig.actions.saveSubThreadDraft(
        SubThreadDraft(name: "other", brief: "Other text."), parentID: root)
    await rig.transport.waitForRequests(1, on: addRoute)
    await autosave
    let saved = try #require(await rig.actions.subThreadDraft(parentID: root))
    #expect(saved.name == "check-the-cache" && saved.sendKey == firstID && saved.sendKeyIsInFlight)
    await rig.transport.answer(held: addRoute, with: createdAnswer)
    #expect(await added == .added(created))
    #expect(await rig.actions.subThreadDraft(parentID: root) == nil)
}

@Test func aDropLandingDuringAStackLookupNeverServesTheOldStack() async throws {
    let rig = OverviewRig()
    await rig.transport.on(stackRoute, try .scenario(200, "scenario-overview-busy-stack"))
    await rig.transport.hold(stackRoute)
    async let old = rig.actions.stack(root)
    await rig.transport.waitForRequests(1, on: stackRoute)
    await rig.transport.on("POST threads/q7adapt0/stack/drop", .json(200, #"{"status":"dropped"}"#))
    #expect(await rig.actions.drop("q7adapt0") == .done)
    async let fresh = rig.actions.stack(root)
    await rig.transport.waitForRequests(2, on: stackRoute)
    await rig.transport.release(stackRoute)
    _ = try await (old, fresh)
    #expect(await rig.transport.requests(on: stackRoute).count == 2)
}

@Test func aRootActionDuringAnotherThreadsLookupStillLetsLaterLookupsAsk() async throws {
    let rig = OverviewRig()
    let otherRoute = "GET threads/q7other0/stack"
    await rig.transport.on(otherRoute, try .scenario(200, "scenario-overview-busy-stack"))
    await rig.transport.hold(otherRoute)
    async let held = rig.actions.stack("q7other0")
    await rig.transport.waitForRequests(1, on: otherRoute)
    await rig.transport.on("POST threads/\(root)/stack/stop", .json(200, #"{"status":"stopping"}"#))
    #expect(await rig.actions.stopStack(root) == .done)
    await rig.transport.release(otherRoute)
    _ = try await held
    await rig.clock.advance(by: .seconds(60))
    _ = try await rig.actions.stack("q7other0")
    #expect(await rig.transport.requests(on: otherRoute).count == 2)
}
