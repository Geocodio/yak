import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let threadID = "d0e515ef"
private let meRoute = "GET me"
private let showRoute = "GET threads/\(threadID)"
private let eventsRoute = "GET threads/\(threadID)/events"
private let revokedAnswer = FakeTransport.Result.json(
    401, #"{"error":"device_revoked","message":"This device was removed."}"#)

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct UnreadableTokenStore: TokenStore {
    struct Locked: Error {}
    func token(for instance: UUID) throws -> String? { throw Locked() }
    func setToken(_ token: String, for instance: UUID) throws { throw Locked() }
    func removeToken(for instance: UUID) throws { throw Locked() }
}

private struct Rig {
    let session: InstanceSession
    let transport: ScriptedTransport
    let server: FakeWebSocketServer
    let clock: ManualClock
    let files: InMemoryFileStore
    let instances: InstanceStore
    let instance: Instance
    let tokens: InMemoryTokenStore
}

/// Answers `me` with the scenario and the channel authorization by channel kind. Everything else is
/// the test's to script.
private func makeRig(
    sessionTokens: (any TokenStore)? = nil, keepsToken: Bool = true,
    script: (ScriptedTransport) async throws -> Void = { _ in }
) async throws -> Rig {
    let clock = ManualClock()
    let files = InMemoryFileStore()
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { clock.now() })
    let instance = try await instances.add(
        url: URL(string: "https://example.test")!, name: "Old name", userName: "Old person", token: "test-token")
    if !keepsToken { try tokens.removeToken(for: instance.id) }
    let transport = ScriptedTransport()
    await transport.on(meRoute, try .scenario(200, "scenario-me"))
    let privateAnswer = try FakeTransport.Result.scenario(200, "scenario-broadcasting-auth-private")
    let presenceAnswer = try FakeTransport.Result.scenario(200, "scenario-broadcasting-auth-presence")
    await transport.on(prefix: "POST broadcasting/auth") { request in
        String(decoding: request.httpBody ?? Data(), as: UTF8.self).contains("presence-")
            ? presenceAnswer : privateAnswer
    }
    try await script(transport)
    let server = FakeWebSocketServer()
    let ids = IDSequence()
    let environment = SessionEnvironment(
        http: transport, webSockets: server, files: files, clock: clock, makeID: { ids.next() },
        appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
    let session = InstanceSession(
        instance: instance, instances: instances, tokens: sessionTokens ?? tokens, environment: environment)
    return Rig(
        session: session, transport: transport, server: server, clock: clock, files: files, instances: instances,
        instance: instance, tokens: tokens)
}

private func startedRig(
    script: (ScriptedTransport) async throws -> Void = { _ in }
) async throws -> Rig {
    let rig = try await makeRig(script: script)
    await rig.session.start()
    return rig
}

private func showAnswer(latestSequence: Int = 5) throws -> FakeTransport.Result {
    .json(
        200,
        String(
            decoding: try Scenarios.data(
                "scenario-thread-show", setting: ["latestSequence"], toJSON: "\(latestSequence)"),
            as: UTF8.self))
}

private func establish(_ connection: FakeConnection, socketID: String = "1.1") async {
    await connection.serverSends(Frames.established(socketID: socketID, activityTimeout: 30))
}

private func confirmBoth(_ connection: FakeConnection) async {
    await connection.serverSends(Frames.subscribed("private-thread.\(threadID)"))
    await connection.serverSends(Frames.subscribed("presence-thread-presence.\(threadID)"))
}

/// The engine publishes a snapshot once per frame on the injected clock, so the clock is stepped a
/// frame at a time for as long as the test waits, and only while a sleeper is due within that frame, so the
/// pump never runs ahead of the engine.
private func nextSnapshot(
    _ recorder: StreamRecorder<ConversationSnapshot>, clock: ManualClock,
    where predicate: @escaping @Sendable (ConversationSnapshot) -> Bool
) async -> ConversationSnapshot {
    let pump = Task {
        while !Task.isCancelled {
            let frame: Duration = .milliseconds(16)
            guard let next = clock.sleeperDeadlines.first, next <= clock.offset + frame else {
                await Task.yield()
                continue
            }
            await clock.advance(by: frame)
        }
    }
    defer { pump.cancel() }
    return await recorder.next(where: predicate)
}

@Suite struct InstanceSessionTests {
    @Test func aRevokedDeviceErasesTheCacheAndAsksToPairAgain() async throws {
        let rig = try await startedRig { await $0.on(showRoute, revokedAnswer) }
        let cache = Cache(instance: rig.instance.id, files: rig.files, clock: rig.clock)
        await cache.saveThreadList(try decoded(ThreadList.self, "scenario-thread-list"), filter: .mine)
        let statuses = StreamRecorder(await rig.session.statuses())
        _ = await rig.session.openThread(threadID)
        _ = await statuses.next { $0 == .needsPairing }
        #expect(try rig.files.list(InstanceFolders.cache(rig.instance.id)).isEmpty)
        #expect(await rig.instances.state(of: rig.instance.id) == .needsPairing)
        #expect(await rig.session.outbox()?.pauseReason() == .unauthorized(code: "device_revoked"))
        #expect(await rig.session.openThread(threadID) == nil)
        await rig.session.stop()
        #expect(await rig.session.outbox() == nil)
    }

    @Test func startWithoutATokenNeedsPairing() async throws {
        let rig = try await makeRig(keepsToken: false)
        await rig.session.start()
        let statuses = StreamRecorder(await rig.session.statuses())
        _ = await statuses.next { $0 == .needsPairing }
        #expect(await rig.transport.requests.isEmpty)
        #expect(await rig.session.openThread(threadID) == nil)
    }

    @Test func aLockedKeychainIsNotRevoked() async throws {
        let rig = try await makeRig(sessionTokens: UnreadableTokenStore())
        await rig.session.start()
        let statuses = StreamRecorder(await rig.session.statuses())
        _ = await statuses.next { $0 == .tokenUnreadable }
        #expect(try rig.tokens.token(for: rig.instance.id) == "test-token")
        #expect(await rig.transport.requests.isEmpty)
        #expect(await rig.server.acceptedCount == 0)
    }

    @Test func startStoresWhatMeSays() async throws {
        let rig = try await startedRig()
        let statuses = StreamRecorder(await rig.session.statuses())
        _ = await statuses.next { $0 == .active }
        let stored = try #require(await rig.instances.all().first)
        #expect(stored.name == "Example Yak" && stored.userName == "Test Person" && stored.needsYouCount == 2)
        #expect(await rig.session.me()?.user.name == "Test Person")
        _ = await rig.server.waitForConnection(0)
        let url = try #require(await rig.server.connectedURLs.first)
        #expect(url.absoluteString.hasPrefix("wss://ws.example.test:443/app/reverb-key?"))
        await rig.session.stop()
    }

    @Test func startTwiceIsOneStart() async throws {
        let rig = try await makeRig()
        await rig.session.start()
        await rig.session.start()
        _ = await rig.server.waitForConnection(0)
        #expect(await rig.transport.requests(on: meRoute).count == 1)
        #expect(await rig.server.acceptedCount == 1)
        await rig.session.stop()
    }

    @Test func offlineStartShowsCachedCopiesAndRetriesMe() async throws {
        let rig = try await makeRig { transport in
            await transport.on(meRoute, .failure(.notConnectedToInternet), try .scenario(200, "scenario-me"))
        }
        let cache = Cache(instance: rig.instance.id, files: rig.files, clock: rig.clock)
        let cached = try decoded(ThreadList.self, "scenario-thread-list")
        await cache.saveThreadList(cached, filter: .mine)
        await rig.transport.hold("GET threads")
        await rig.session.start()
        let statuses = StreamRecorder(await rig.session.statuses())
        _ = await statuses.next { $0 == .active }
        #expect(await rig.session.me() == nil)
        #expect(await rig.server.acceptedCount == 0)
        let feed = try #require(await rig.session.threadList())
        let states = StreamRecorder(await feed.states())
        await feed.setVisible(true)
        let shown = await states.next { $0.isShowingCachedCopy }
        #expect(shown.list == cached)
        await rig.clock.waitForSleeper(endingAt: .seconds(1))
        await rig.clock.advance(by: .seconds(1))
        await rig.transport.waitForRequests(2, on: meRoute)
        _ = await rig.server.waitForConnection(0)
        #expect(await rig.session.me()?.instance.name == "Example Yak")
        await feed.setVisible(false)
        await rig.session.stop()
    }

    @Test func twoScenesShareOneEngine() async throws {
        let rig = try await startedRig { await $0.on(showRoute, try showAnswer()) }
        let first = try #require(await rig.session.openThread(threadID))
        let second = try #require(await rig.session.openThread(threadID))
        #expect(first === second)
        let connection = await rig.server.waitForConnection(0)
        await establish(connection)
        await connection.waitForSent(2)
        await rig.session.closeThread(threadID)
        #expect(await connection.sent.count == 2)
        #expect(await rig.session.openThread(threadID) === first)
        await rig.session.closeThread(threadID)
        await rig.session.closeThread(threadID)
        #expect(await connection.sent.suffix(2).allSatisfy { $0.contains("pusher:unsubscribe") })
        await rig.session.stop()
    }

    @Test func socketEventsReachTheEngine() async throws {
        let list = try EventList.load("events/simple-turn")
        let events = EventServer(threadID: threadID, events: list.stored)
        await events.publish(through: 5)
        let rig = try await startedRig { transport in
            await transport.on(showRoute, try showAnswer(latestSequence: 5))
            await transport.on("POST threads/\(threadID)/seen", .json(200, "{}"))
            await events.install(on: transport)
        }
        let engine = try #require(await rig.session.openThread(threadID))
        let snapshots = StreamRecorder(await engine.snapshots())
        _ = await nextSnapshot(snapshots, clock: rig.clock) {
            $0.load == .ready && $0.lastSequence == 5 && !$0.isShowingCachedCopy
        }
        let connection = await rig.server.waitForConnection(0)
        await establish(connection)
        await connection.waitForSent(2)
        await confirmBoth(connection)
        await events.publish(through: 6)
        let scenario = try JSONSerialization.jsonObject(with: Scenarios.data("events/simple-turn")) as? [String: Any]
        let broadcasts = try #require(scenario?["broadcasts"] as? [[String: Any]])
        let six = try #require(broadcasts.first { ($0["sequence"] as? Int) == 6 })
        let data = String(decoding: try JSONSerialization.data(withJSONObject: six), as: UTF8.self)
        await connection.serverSends(Frames.threadEvent(channel: "private-thread.\(threadID)", data: data))
        _ = await nextSnapshot(snapshots, clock: rig.clock) { $0.lastSequence == 6 }
        await rig.session.stop()
    }

    @Test func anUnsentMessageShowsInItsThread() async throws {
        let rig = try await startedRig { await $0.on(showRoute, try showAnswer()) }
        await rig.transport.hold("POST threads/\(threadID)/messages")
        let engine = try #require(await rig.session.openThread(threadID))
        let snapshots = StreamRecorder(await engine.snapshots())
        let item = try await rig.session.send(OutboxDraft(target: .thread(threadID), text: "Please add a test"))
        let snapshot = await nextSnapshot(snapshots, clock: rig.clock) {
            if case .unsent(let unsent)? = $0.entries.last { unsent.id == item.id } else { false }
        }
        #expect(snapshot.entries.last?.id == "outbox:\(item.id.uuidString.lowercased())")
        await rig.transport.release("POST threads/\(threadID)/messages")
        await rig.session.stop()
    }

    @Test func aServerThatIsTooOldStopsCalling() async throws {
        let rig = try await makeRig { transport in
            await transport.on(meRoute, try .scenario(200, "scenario-me").withHeader(ClientProtocol.headerName, "0"))
        }
        await rig.session.start()
        let statuses = StreamRecorder(await rig.session.statuses())
        _ = await statuses.next { $0 == .serverTooOld }
        #expect(await rig.server.acceptedCount == 0)
        #expect(await rig.session.threadList() == nil)
        #expect(await rig.session.openThread(threadID) == nil)
        #expect(await rig.transport.requests.count == 1)
    }

    @Test func foregroundCatchesUpEveryOpenThread() async throws {
        let list = try EventList.load("events/simple-turn")
        let events = EventServer(threadID: threadID, events: list.stored)
        await events.publish(through: 5)
        let rig = try await startedRig { transport in
            await transport.on(showRoute, try showAnswer(latestSequence: 5))
            await transport.on("POST threads/\(threadID)/seen", .json(200, "{}"))
            await events.install(on: transport)
        }
        let engine = try #require(await rig.session.openThread(threadID))
        let snapshots = StreamRecorder(await engine.snapshots())
        _ = await nextSnapshot(snapshots, clock: rig.clock) {
            $0.load == .ready && $0.lastSequence == 5 && !$0.isShowingCachedCopy
        }
        let first = await rig.server.waitForConnection(0)
        await establish(first)
        await first.waitForSent(2)
        await rig.session.enterBackground()
        await first.waitUntilClosed()
        let before = await rig.transport.requests(on: eventsRoute).count
        await rig.session.enterForeground()
        let second = await rig.server.waitForConnection(1)
        await establish(second, socketID: "2.2")
        await second.waitForSent(2)
        await rig.transport.waitForRequests(before + 1, on: eventsRoute)
        for _ in 0..<50 { await Task.yield() }
        #expect(await rig.transport.requests(on: eventsRoute).count == before + 1)
        #expect(await rig.server.acceptedCount == 2)
        await rig.session.stop()
    }

    @Test func aStopThenStartLeavesOneOutboxSending() async throws {
        let messages = "POST threads/\(threadID)/messages"
        let rig = try await startedRig { transport in
            await transport.on(messages, try .scenario(200, "scenario-message-sent"))
        }
        await rig.transport.hold(messages)
        let item = try await rig.session.send(OutboxDraft(target: .thread(threadID), text: "Please add a test"))
        await rig.transport.waitForRequests(1, on: messages)
        await rig.session.stop()
        await rig.session.start()
        let outbox = try #require(await rig.session.outbox())
        let deliveries = StreamRecorder(await outbox.deliveries())
        await rig.transport.waitForRequests(2, on: messages)
        await rig.transport.release(messages)
        _ = await deliveries.next { _ in true }
        for _ in 0..<100 { await Task.yield() }
        let posts = await rig.transport.requests(on: messages)
        #expect(posts.count == 2)
        #expect(await outbox.items().isEmpty)
        let sendKeys = posts.compactMap {
            ($0.httpBody.flatMap { try? JSONSerialization.jsonObject(with: $0) } as? [String: Any])?["send_key"]
                as? String
        }
        #expect(sendKeys == [item.id.uuidString, item.id.uuidString])
        await rig.session.stop()
    }

    @Test func aSessionStartsAgainAfterARevokedDevice() async throws {
        let rig = try await startedRig { await $0.on(showRoute, revokedAnswer) }
        let statuses = StreamRecorder(await rig.session.statuses())
        _ = await rig.session.openThread(threadID)
        _ = await statuses.next { $0 == .needsPairing }
        try rig.tokens.setToken("new-test-token", for: rig.instance.id)
        await rig.session.start()
        _ = await statuses.next { $0 == .active }
        await rig.session.stop()
    }

    @Test func aStopOrRevokeAtAnyPointOfStartingLeavesNoSocketOpen() async throws {
        for revokes in [false, true] {
            for yields in 0..<150 {
                let rig = try await makeRig()
                let starting = Task { await rig.session.start() }
                for _ in 0..<yields { await Task.yield() }
                if revokes {
                    await rig.session.handle(.unauthorized(code: "device_revoked"))
                } else {
                    await rig.session.stop()
                }
                await starting.value
                await rig.session.stop()
                for _ in 0..<200 { await Task.yield() }
                for index in 0..<(await rig.server.acceptedCount) {
                    let connection = await rig.server.waitForConnection(index)
                    #expect(await connection.isClosed, "revokes \(revokes), yields \(yields), socket \(index)")
                }
            }
        }
    }

    @Test func theOutboxStaysReadableAfterARevokeUntilTheNextStart() async throws {
        let messages = "POST threads/\(threadID)/messages"
        let rig = try await startedRig { transport in
            await transport.on(messages, revokedAnswer, try .scenario(200, "scenario-message-sent"))
        }
        let statuses = StreamRecorder(await rig.session.statuses())
        let item = try await rig.session.send(OutboxDraft(target: .thread(threadID), text: "Held for pairing"))
        _ = await statuses.next { $0 == .needsPairing }
        let paused = try #require(await rig.session.outbox())
        #expect(await paused.pauseReason() == .unauthorized(code: "device_revoked"))
        #expect(await paused.items().map(\.id) == [item.id])

        // Pairing again keeps the instance, so its saved items are found and sent.
        let paired = try await rig.instances.add(
            url: rig.instance.url, name: "Example Yak", userName: "Test Person", token: "new-test-token")
        #expect(paired.id == rig.instance.id)
        await rig.session.start()
        let fresh = try #require(await rig.session.outbox())
        #expect(fresh !== paused)
        let deliveries = StreamRecorder(await fresh.deliveries())
        let delivery = await deliveries.next { _ in true }
        #expect(delivery.sendKey == item.id)
        #expect(await fresh.items().isEmpty && fresh !== paused)
        await rig.session.stop()
    }

    @Test func unpairingAfterARevokeErasesTheHeldMessages() async throws {
        let messages = "POST threads/\(threadID)/messages"
        let rig = try await startedRig { await $0.on(messages, revokedAnswer) }
        await rig.transport.on("DELETE device", .json(200, "{}"))
        let statuses = StreamRecorder(await rig.session.statuses())
        _ = try await rig.session.send(OutboxDraft(target: .thread(threadID), text: "Private words"))
        _ = await statuses.next { $0 == .needsPairing }
        await rig.session.unpair()
        #expect(try rig.files.list(InstanceFolders.outbox(rig.instance.id)).isEmpty)
        #expect(try rig.files.list("instances/\(rig.instance.id.uuidString)").isEmpty)
    }

    @Test func theOutboxIsReadableWhenMeAnswersRevokedAtStart() async throws {
        let messages = "POST threads/\(threadID)/messages"
        let rig = try await startedRig()
        await rig.transport.hold(messages)
        let item = try await rig.session.send(OutboxDraft(target: .thread(threadID), text: "Held for pairing"))
        await rig.session.stop()
        await rig.transport.on(meRoute, revokedAnswer)
        let statuses = StreamRecorder(await rig.session.statuses())
        await rig.session.start()
        _ = await statuses.next { $0 == .needsPairing }
        let paused = try #require(await rig.session.outbox())
        #expect(await paused.pauseReason() == .unauthorized(code: "device_revoked"))
        #expect(await paused.items().map(\.id) == [item.id])
        #expect(await rig.transport.requests(on: messages).count == 1)
        await rig.session.stop()
    }

    @Test func aSentMessageIsNeverMissingFromItsThread() async throws {
        let messages = "POST threads/\(threadID)/messages"
        let rig = try await startedRig { transport in
            await transport.on(showRoute, try showAnswer())
            await transport.on(messages, try .scenario(200, "scenario-message-sent"))
        }
        await rig.transport.hold(messages)
        let engine = try #require(await rig.session.openThread(threadID))
        let snapshots = StreamRecorder(await engine.snapshots())
        let item = try await rig.session.send(OutboxDraft(target: .thread(threadID), text: "Please add a test"))
        let outboxID = "outbox:\(item.id.uuidString.lowercased())"
        _ = await nextSnapshot(snapshots, clock: rig.clock) { $0.entries.contains { $0.id == outboxID } }
        await rig.transport.release(messages)
        _ = await nextSnapshot(snapshots, clock: rig.clock) {
            $0.entries.contains { if case .delivered = $0 { true } else { false } }
        }
        let shown = await snapshots.values
        let first = try #require(shown.firstIndex { $0.entries.contains { $0.id == outboxID } })
        #expect(shown[first...].allSatisfy { $0.entries.contains { $0.id == outboxID } })
        await rig.session.stop()
    }

    @Test func unpairingASessionThatNeverStartedStillTellsTheServer() async throws {
        let rig = try await makeRig { await $0.on("DELETE device", .json(200, "{}")) }
        await rig.session.unpair()
        #expect(await rig.transport.requests(on: "DELETE device").count == 1)
    }

    @Test func unpairErasesEverything() async throws {
        let rig = try await startedRig { await $0.on("DELETE device", .json(200, "{}")) }
        let cache = Cache(instance: rig.instance.id, files: rig.files, clock: rig.clock)
        await cache.saveThreadList(try decoded(ThreadList.self, "scenario-thread-list"), filter: .mine)
        _ = try await rig.session.send(OutboxDraft(target: .thread(threadID), text: "Please add a test"))
        await rig.session.unpair()
        #expect(await rig.transport.requests(on: "DELETE device").count == 1)
        #expect(await rig.instances.all().isEmpty)
        #expect(try rig.files.list("instances/\(rig.instance.id.uuidString)").isEmpty)
        #expect(try rig.tokens.token(for: rig.instance.id) == nil)
        await #expect(throws: SendProblem.notActive) {
            _ = try await rig.session.send(OutboxDraft(target: .thread(threadID), text: "Again"))
        }
    }
}

extension FakeTransport.Result {
    fileprivate func withHeader(_ name: String, _ value: String) -> FakeTransport.Result {
        guard case .response(let status, let body, var headers) = self else { return self }
        headers[name] = value
        return .response(status: status, body: body, headers: headers)
    }
}
