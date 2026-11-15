import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let threadRoute = "POST threads/d0e515ef/messages"

private func makeClient(_ transport: ScriptedTransport) -> APIClient {
    APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test phone")
}

private func draft(_ text: String, thread: String = "d0e515ef") -> OutboxDraft {
    OutboxDraft(target: .thread(thread), text: text)
}

private func body(_ request: URLRequest) -> [String: Any] {
    (request.httpBody.flatMap { try? JSONSerialization.jsonObject(with: $0) } as? [String: Any]) ?? [:]
}

private func sendKey(_ request: URLRequest) -> UUID? {
    (body(request)["send_key"] as? String).flatMap(UUID.init(uuidString:))
}

private func problem(_ status: Int, _ code: String, headers: [String: String] = [:]) -> FakeTransport.Result {
    .json(status, #"{"error":"\#(code)","message":"m"}"#, headers: headers)
}

private func settle() async { for _ in 0..<100 { await Task.yield() } }

private struct Harness {
    let transport = ScriptedTransport()
    let clock = ManualClock()
    let outbox: Outbox

    init() {
        outbox = Outbox(
            instance: UUID(), client: makeClient(transport), files: InMemoryFileStore(), clock: clock,
            makeID: IDSequence().next)
    }
}

@Suite struct OutboxTests {
    @Test func aLostAnswerIsRepeatedWithTheSameKeyAndOrderHolds() async throws {
        let harness = Harness()
        let (transport, clock, outbox) = (harness.transport, harness.clock, harness.outbox)
        await transport.on(threadRoute, .failure(.timedOut), try .scenario(200, "scenario-message-sent"))
        let deliveries = StreamRecorder(await outbox.deliveries())
        let first = try await outbox.enqueue(draft("First"))
        let second = try await outbox.enqueue(draft("Second"))
        await transport.waitForRequests(1, on: threadRoute)
        await clock.waitForSleeper(endingAt: .seconds(1))
        #expect(await outbox.items().map(\.state) == [.waiting, .queued])
        await clock.advance(by: .seconds(1))
        await transport.waitForRequests(3, on: threadRoute)
        let keys = await transport.requests(on: threadRoute).map(sendKey)
        #expect(keys == [first.id, first.id, second.id])
        _ = try await deliveries.next { $0.sendKey == second.id }
        #expect(await deliveries.values.map(\.sendKey) == [first.id, second.id])
        #expect(await outbox.items().isEmpty)
    }

    @Test func turnsSlowAfterFiveSeconds() async throws {
        let harness = Harness()
        let (transport, clock, outbox) = (harness.transport, harness.clock, harness.outbox)
        await transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await transport.hold(threadRoute)
        let changes = StreamRecorder(await outbox.changes())
        let deliveries = StreamRecorder(await outbox.deliveries())
        _ = try await outbox.enqueue(draft("Hello"))
        _ = try await changes.next { $0.first?.state == .sending }
        await clock.waitForSleeper(endingAt: .seconds(5))
        await clock.advance(by: .seconds(5))
        _ = try await changes.next { $0.first?.state == .slow }
        await transport.release(threadRoute)
        _ = try await deliveries.next { _ in true }
        #expect(await outbox.items().isEmpty)
    }

    @Test func retriesOnTheSchedule() async throws {
        let harness = Harness()
        let (transport, clock, outbox) = (harness.transport, harness.clock, harness.outbox)
        await transport.on(threadRoute, .failure(.notConnectedToInternet))
        _ = try await outbox.enqueue(draft("Hello"))
        var elapsed = Duration.zero
        for (index, offset) in [1, 3, 7, 15, 30, 60, 90].map({ Duration.seconds($0) }).enumerated() {
            await clock.waitForSleeper(endingAt: offset)
            #expect(await outbox.items().first?.state == .waiting)
            #expect(await outbox.items().first?.attemptCount == index + 1)
            await clock.advance(by: offset - elapsed)
            elapsed = offset
        }
    }

    @Test func waitsForRetryAfter() async throws {
        let harness = Harness()
        let (transport, clock, outbox) = (harness.transport, harness.clock, harness.outbox)
        await transport.on(
            threadRoute, problem(429, "rate_limited", headers: ["Retry-After": "12"]),
            try .scenario(200, "scenario-message-sent"))
        let deliveries = StreamRecorder(await outbox.deliveries())
        _ = try await outbox.enqueue(draft("Hello"))
        await clock.waitForSleeper(endingAt: .seconds(12))
        await clock.advance(by: .seconds(12))
        _ = try await deliveries.next { _ in true }
        #expect(await transport.requests(on: threadRoute).count == 2)
    }

    @Test(arguments: [
        (403, "forbidden"), (404, "not_found"), (409, "thread_closed"), (422, "validation_failed"),
        (413, "payload_too_large"),
    ])
    func refusesTheseAndKeepsTheText(status: Int, code: String) async throws {
        let harness = Harness()
        let (transport, outbox) = (harness.transport, harness.outbox)
        await transport.on(threadRoute, problem(status, code))
        let changes = StreamRecorder(await outbox.changes())
        _ = try await outbox.enqueue(draft("First"))
        _ = try await outbox.enqueue(draft("Second"))
        _ = try await changes.next { $0.first?.state == .refused(OutboxRefusal(code: code, message: "m")) }
        await settle()
        let items = await outbox.items()
        #expect(items.map(\.text) == ["First", "Second"])
        #expect(items.map(\.state) == [.refused(OutboxRefusal(code: code, message: "m")), .queued])
        #expect(await transport.requests(on: threadRoute).count == 1)
    }

    @Test func refusesOnProtocolTooOld() async throws {
        let harness = Harness()
        await harness.transport.on(
            threadRoute,
            .json(426, #"{"error":"protocol_too_old","message":"m","details":{"min_protocol":99}}"#))
        let changes = StreamRecorder(await harness.outbox.changes())
        _ = try await harness.outbox.enqueue(draft("Hello"))
        _ = try await changes.next {
            $0.first?.state == .refused(OutboxRefusal(code: "protocol_too_old", message: ""))
        }
    }

    @Test func pausesOnUnauthorizedAndKeepsItems() async throws {
        let harness = Harness()
        let (transport, outbox) = (harness.transport, harness.outbox)
        await transport.on(threadRoute, problem(401, "device_revoked"), try .scenario(200, "scenario-message-sent"))
        let changes = StreamRecorder(await outbox.changes())
        let deliveries = StreamRecorder(await outbox.deliveries())
        _ = try await outbox.enqueue(draft("Hello"))
        _ = try await changes.next { $0.first?.state == .sending }
        _ = try await changes.next { $0.first?.state == .queued }
        #expect(await outbox.pauseReason() == .unauthorized(code: "device_revoked"))
        await settle()
        #expect(await transport.requests(on: threadRoute).count == 1)
        #expect(await outbox.items().count == 1)
        await outbox.resume()
        _ = try await deliveries.next { _ in true }
        #expect(await outbox.pauseReason() == nil)
        #expect(await outbox.items().isEmpty)
    }

    @Test func networkReturnSendsAtOnce() async throws {
        let harness = Harness()
        let (transport, clock, outbox) = (harness.transport, harness.clock, harness.outbox)
        let offline = FakeTransport.Result.failure(.notConnectedToInternet)
        let sent = try FakeTransport.Result.scenario(200, "scenario-message-sent")
        await transport.on(threadRoute, offline, offline, offline, offline, offline, sent)
        let deliveries = StreamRecorder(await outbox.deliveries())
        _ = try await outbox.enqueue(draft("Hello"))
        var elapsed = Duration.zero
        for offset in [1, 3, 7, 15].map({ Duration.seconds($0) }) {
            await clock.waitForSleeper(endingAt: offset)
            await clock.advance(by: offset - elapsed)
            elapsed = offset
        }
        await clock.waitForSleeper(endingAt: .seconds(30))
        await outbox.networkReturned()
        _ = try await deliveries.next { _ in true }
        #expect(await transport.requests(on: threadRoute).count == 6)
    }

    @Test func cancelledAttemptWaitsForTheNextTrigger() async throws {
        let harness = Harness()
        let (transport, outbox) = (harness.transport, harness.outbox)
        await transport.on(threadRoute, .failure(.cancelled), try .scenario(200, "scenario-message-sent"))
        let deliveries = StreamRecorder(await outbox.deliveries())
        _ = try await outbox.enqueue(draft("Hello"))
        await settle()
        #expect(await transport.requests(on: threadRoute).count == 1)
        #expect(await outbox.items().first?.state == .queued)
        #expect(await outbox.items().first?.attemptCount == 1)
        let item = try #require(await outbox.items().first)
        await #expect(throws: OutboxEditProblem.mayAlreadyBeSent) { try await outbox.edit(item.id, text: "Changed") }
        await outbox.networkReturned()
        _ = try await deliveries.next { _ in true }
        #expect(await transport.requests(on: threadRoute).count == 2)
    }

    @Test func changesAndDeliveriesShareOneOrderedStream() async throws {
        let harness = Harness()
        let (transport, outbox) = (harness.transport, harness.outbox)
        await transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await transport.hold(threadRoute)
        let updates = StreamRecorder(await outbox.updates())
        let item = try await outbox.enqueue(draft("Ordered"))
        await transport.waitForRequests(1, on: threadRoute)
        await transport.release(threadRoute)
        _ = try await updates.next { if case .delivery = $0 { true } else { false } }
        _ = try await updates.next { if case .items(let items) = $0 { items.isEmpty } else { false } }
        let shown = await updates.values
        let deliveryIndex = try #require(shown.firstIndex { if case .delivery = $0 { true } else { false } })
        let firstWithItem = try #require(
            shown.firstIndex { if case .items(let list) = $0 { list.contains { $0.id == item.id } } else { false } })
        let emptyAfter = try #require(
            shown[firstWithItem...].firstIndex { if case .items(let list) = $0 { list.isEmpty } else { false } })
        #expect(deliveryIndex < emptyAfter)
    }

    @Test func editAndRemoveFollowTheRules() async throws {
        // queued
        let queued = Harness()
        await queued.outbox.pause(.serverTooOld)
        let queuedItem = try await queued.outbox.enqueue(draft("Old"))
        try await queued.outbox.edit(queuedItem.id, text: "New")
        #expect(await queued.outbox.items().first?.text == "New")
        let removed = try await queued.outbox.remove(queuedItem.id)
        #expect(removed.text == "New")
        #expect(await queued.outbox.items().isEmpty)
        await #expect(throws: OutboxEditProblem.notFound) { try await queued.outbox.edit(queuedItem.id, text: "x") }

        // sending
        let sending = Harness()
        await sending.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await sending.transport.hold(threadRoute)
        let sendingItem = try await sending.outbox.enqueue(draft("Held"))
        await sending.transport.waitForRequests(1, on: threadRoute)
        await #expect(throws: OutboxEditProblem.alreadySending) {
            try await sending.outbox.edit(sendingItem.id, text: "x")
        }
        await #expect(throws: OutboxEditProblem.alreadySending) { try await sending.outbox.remove(sendingItem.id) }
        await sending.transport.release(threadRoute)

        // waiting after one failed attempt
        let waiting = Harness()
        await waiting.transport.on(threadRoute, .failure(.timedOut))
        let waitingItem = try await waiting.outbox.enqueue(draft("Maybe"))
        await waiting.clock.waitForSleeper(endingAt: .seconds(1))
        await #expect(throws: OutboxEditProblem.mayAlreadyBeSent) {
            try await waiting.outbox.edit(waitingItem.id, text: "x")
        }
        _ = try await waiting.outbox.remove(waitingItem.id)
        #expect(await waiting.outbox.items().isEmpty)

        // refused
        let refused = Harness()
        await refused.transport.on(
            threadRoute, problem(422, "validation_failed"), try .scenario(200, "scenario-message-sent"))
        let changes = StreamRecorder(await refused.outbox.changes())
        let deliveries = StreamRecorder(await refused.outbox.deliveries())
        let refusedItem = try await refused.outbox.enqueue(draft("Bad"))
        _ = try await changes.next {
            if case .refused? = $0.first?.state { return true }
            return false
        }
        try await refused.outbox.edit(refusedItem.id, text: "Better")
        await refused.outbox.retry(refusedItem.id)
        _ = try await deliveries.next { _ in true }
        let requests = await refused.transport.requests(on: threadRoute)
        #expect(requests.map(sendKey) == [refusedItem.id, refusedItem.id])
        #expect(body(requests[1])["message"] as? String == "Better")
    }

    @Test func threadsDoNotBlockEachOther() async throws {
        let harness = Harness()
        let (transport, outbox) = (harness.transport, harness.outbox)
        await transport.on("POST threads/aaaa1111/messages", problem(422, "validation_failed"))
        await transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        let deliveries = StreamRecorder(await outbox.deliveries())
        _ = try await outbox.enqueue(draft("Blocked", thread: "aaaa1111"))
        let other = try await outbox.enqueue(draft("Free"))
        _ = try await deliveries.next { $0.sendKey == other.id }
        #expect(await outbox.items().map(\.text) == ["Blocked"])
    }

    @Test func clearNamesTheNewThread() async throws {
        let harness = Harness()
        await harness.transport.on(threadRoute, try .scenario(200, "scenario-message-clear"))
        let deliveries = StreamRecorder(await harness.outbox.deliveries())
        _ = try await harness.outbox.enqueue(draft("/clear"))
        let delivery = try await deliveries.next { _ in true }
        #expect(delivery.threadID == "n3w7hr3d")
        #expect(delivery.messageUUID == nil)
        #expect(delivery.status == .sent)
        #expect(await harness.outbox.items().isEmpty)
    }

    @Test func aNewThreadIsCreatedAndDelivered() async throws {
        let harness = Harness()
        await harness.transport.on("POST threads", try .scenario(201, "scenario-thread-store"))
        let deliveries = StreamRecorder(await harness.outbox.deliveries())
        let item = try await harness.outbox.enqueue(
            OutboxDraft(target: .newThread(NewThreadDraft(repo: "example/api", mode: "task")), text: "Do it"))
        let delivery = try await deliveries.next { _ in true }
        #expect(delivery.threadID == "k7m2q9rt")
        #expect(delivery.status == .sent)
        #expect(delivery.sendKey == item.id)
        let request = try #require(await harness.transport.requests(on: "POST threads").first)
        #expect(sendKey(request) == item.id)
        #expect(body(request)["description"] as? String == "Do it")
        #expect(await harness.outbox.items().isEmpty)
    }

    @Test func aQueuedAnswerLeavesTheOutbox() async throws {
        let harness = Harness()
        await harness.transport.on(threadRoute, try .scenario(200, "scenario-message-queued"))
        let deliveries = StreamRecorder(await harness.outbox.deliveries())
        _ = try await harness.outbox.enqueue(draft("Later"))
        let delivery = try await deliveries.next { _ in true }
        #expect(delivery.status == .queued)
        #expect(delivery.messageUUID == "00000000-0000-4000-8000-0000000000a2")
        #expect(await harness.outbox.items().isEmpty)
    }

    @Test func theRetryPolicyMapsEveryFailure() {
        func decide(_ failure: APIFailure, _ attempt: Int = 1) -> OutboxDecision {
            OutboxRetryPolicy.decision(for: failure, attempt: attempt)
        }
        #expect(decide(.network(.cancelled)) == .stayQueued)
        #expect(decide(.network(.timedOut), 6) == .retry(after: .seconds(30)))
        #expect(decide(.notYak, 5) == .retry(after: .seconds(15)))
        #expect(
            decide(.server(APIError(status: 503, code: "x", message: "", details: [:]))) == .retry(after: .seconds(1)))
        let tooLong = APIError(status: 429, code: "x", message: "", details: [:], retryAfterSeconds: 3600)
        #expect(decide(.server(tooLong)) == .retry(after: .seconds(300)))
        #expect(decide(.encodingFailed) == .refuse(OutboxRefusal(code: "encoding_failed", message: "")))
        #expect(decide(.serverTooOld(current: 1)) == .pause(.serverTooOld))
    }
}
