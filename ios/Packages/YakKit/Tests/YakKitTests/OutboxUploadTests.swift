import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let bundleID = "com.example.yak"
private let threadRoute = "POST threads/d0e515ef/messages"

private func makeClient(_ transport: ScriptedTransport) -> APIClient {
    APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test phone")
}

private func waitUntil(_ condition: @Sendable () async -> Bool) async {
    for _ in 0..<5_000 {
        if await condition() { return }
        await Task.yield()
    }
}

/// An outbox on a background upload link, with the files, the clock and the fake session it runs on.
private final class UploadWorld: @unchecked Sendable {
    let instance = UUID()
    let files = InMemoryFileStore()
    let clock = ManualClock()
    let transport = ScriptedTransport()
    let session: FakeUploadSession
    let ids = IDSequence()
    var ledger: UploadLedger
    var outbox: Outbox

    init(uploads: Bool = true) {
        session = FakeUploadSession(
            identifier: UploadSessionID.app(instance: instance).identifier(appBundleID: bundleID))
        ledger = UploadLedger(instance: instance, files: files, clock: clock)
        outbox = Outbox(
            instance: instance, client: makeClient(transport), files: files, clock: clock, makeID: ids.next,
            uploads: uploads ? UploadLink(ledger: ledger, session: session) : nil)
    }

    private var expectedItems = 0

    /// The attachment of the next item `enqueue` creates, stored where the outbox removes it from.
    func storedAttachment(_ name: String, id: UUID? = nil) throws -> OutboxAttachment {
        let id = id ?? UUID(uuidString: "00000000-0000-4000-8000-" + String(format: "%012d", expectedItems + 1))!
        let path = OutboxInbox.attachmentPath(instance: instance, item: id, fileName: name)
        try files.write(Data("bytes of \(name)".utf8), to: path, protection: .untilFirstUnlock)
        return OutboxAttachment(reference: "Image #1", fileName: name, contentType: "image/jpeg", path: path)
    }

    func draft(_ text: String, thread: String = "d0e515ef", files names: [String] = []) throws -> OutboxDraft {
        defer { expectedItems += 1 }
        return OutboxDraft(target: .thread(thread), text: text, attachments: try names.map { try storedAttachment($0) })
    }

    func settle() async { for _ in 0..<300 { await Task.yield() } }

    func answer(
        _ item: UUID, attempt: Int = 1, status: Int = 200, body: String = #"{"status":"sent","uuid":"m-1"}"#,
        headers: [String: String] = [:], failed: Bool = false, on ledger: UploadLedger? = nil
    ) async {
        let target = ledger ?? self.ledger
        let description = UploadTaskDescription(item: item, attempt: attempt)
        await target.received(description, data: Data(body.utf8))
        await target.completed(
            description, session: session.identifier, task: attempt, status: failed ? nil : status,
            headers: headers, failed: failed)
    }

    /// The app stops; a new process with a new ledger and outbox loads the same files.
    func relaunch(whileDown: (UploadLedger) async -> Void = { _ in }) async {
        await outbox.shutDown()
        ledger = UploadLedger(instance: instance, files: files, clock: clock)
        await whileDown(ledger)
        outbox = Outbox(
            instance: instance, client: makeClient(transport), files: files, clock: clock, makeID: ids.next,
            uploads: UploadLink(ledger: ledger, session: session))
    }

    func bodyText(_ started: FakeUploadSession.Started) throws -> String {
        String(decoding: try #require(try files.read(started.bodyPath)), as: UTF8.self)
    }
}

@Suite struct OutboxUploadTests {
    @Test func oneSendKeyFromEveryPathAndEveryAnswerLands() async throws {
        let world = UploadWorld()
        let item = try await world.outbox.enqueue(world.draft("See photo", files: ["photo.jpg"]))
        await waitUntil { world.session.started.count == 1 }
        let body = try world.bodyText(try #require(world.session.started.first))
        #expect(body.contains(item.id.uuidString))
        #expect(await world.outbox.items().first?.state == .sending)
        #expect(await world.transport.requests(on: threadRoute).isEmpty)

        await world.relaunch { ledger in await world.answer(item.id, on: ledger) }
        let deliveries = StreamRecorder(await world.outbox.deliveries())
        await world.outbox.load()
        let delivery = try await deliveries.next { _ in true }
        #expect(delivery.messageUUID == "m-1" && delivery.sendKey == item.id && delivery.threadID == "d0e515ef")
        #expect(await world.outbox.items().isEmpty)
        #expect(await world.ledger.records().isEmpty)
        #expect(try world.files.list("instances/\(world.instance)/outbox/files").isEmpty)
        #expect(world.session.started.count == 1)
    }

    @Test func anAnswerWhileRunningDeliversAndTakesTheRecord() async throws {
        let world = UploadWorld()
        await world.outbox.load()
        let deliveries = StreamRecorder(await world.outbox.deliveries())
        let item = try await world.outbox.enqueue(world.draft("Now", files: ["a.jpg"]))
        await waitUntil { world.session.started.count == 1 }
        await world.answer(item.id)
        let delivery = try await deliveries.next { _ in true }
        #expect(delivery.messageUUID == "m-1")
        await waitUntil { await world.ledger.records().isEmpty }
        #expect(await world.ledger.records().isEmpty)
        #expect(await world.outbox.items().isEmpty)
    }

    @Test func aTransportFailureWaitsWithoutATimer() async throws {
        let world = UploadWorld()
        await world.outbox.load()
        let item = try await world.outbox.enqueue(world.draft("Wait", files: ["a.jpg"]))
        await waitUntil { world.session.started.count == 1 }
        await world.answer(item.id, failed: true)
        await waitUntil { await world.outbox.items().first?.state == .waiting }
        #expect(await world.outbox.items().first?.state == .waiting)
        await world.clock.advance(by: .seconds(600))
        await world.settle()
        await world.outbox.networkReturned()
        await world.settle()
        #expect(world.session.started.count == 1)
        #expect(await world.outbox.items().first?.state == .waiting)

        await world.outbox.retryFailedUploads()
        await waitUntil { world.session.started.count == 2 }
        let started = world.session.started
        #expect(started.map(\.description.attempt) == [1, 2])
        #expect(started[0].bodyPath == started[1].bodyPath)
        #expect(try world.bodyText(started[1]).contains(item.id.uuidString))
    }

    @Test func strayAnswersChangeNothing() async throws {
        let world = UploadWorld()
        await world.outbox.load()
        let item = try await world.outbox.enqueue(world.draft("Stray", files: ["a.jpg"]))
        await waitUntil { world.session.started.count == 1 }
        await world.answer(item.id, failed: true)
        await waitUntil { await world.outbox.items().first?.state == .waiting }
        await world.outbox.retryFailedUploads()
        await waitUntil { world.session.started.count == 2 }
        await world.settle()
        // an unknown item, and the answer of attempt 1 after attempt 2 started
        await world.answer(UUID())
        await world.answer(item.id, attempt: 1)
        await world.settle()
        #expect(await world.outbox.items().map(\.id) == [item.id])
        #expect(
            await world.ledger.record(for: item.id)?.phase == .uploading(session: world.session.identifier, task: 2))
        // the real answer lands once; a second one for the same attempt changes nothing
        let deliveries = StreamRecorder(await world.outbox.deliveries())
        await world.answer(item.id, attempt: 2)
        _ = try await deliveries.next { _ in true }
        await world.answer(item.id, attempt: 2)
        await world.settle()
        #expect(await deliveries.values.count == 1)
        #expect(await world.outbox.items().isEmpty)
    }

    @Test func aFailedUploadHoldsLaterMessagesUntilRetry() async throws {
        let world = UploadWorld()
        await world.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await world.transport.on("POST threads/other/messages", try .scenario(200, "scenario-message-sent"))
        await world.outbox.load()
        let held = try await world.outbox.enqueue(world.draft("With file", files: ["a.jpg"]))
        let later = try await world.outbox.enqueue(world.draft("Text only"))
        await waitUntil { world.session.started.count == 1 }
        await world.answer(held.id, failed: true)
        await waitUntil { await world.outbox.items().first?.state == .waiting }
        let other = try await world.outbox.enqueue(world.draft("Elsewhere", thread: "other"))
        await world.transport.waitForRequests(1, on: "POST threads/other/messages")
        await world.clock.advance(by: .seconds(600))
        await world.settle()
        #expect(await world.transport.requests(on: threadRoute).isEmpty)
        let states = await world.outbox.items().reduce(into: [UUID: OutboxState]()) { $0[$1.id] = $1.state }
        #expect(states[held.id] == .waiting && states[later.id] == .queued)
        _ = other

        let deliveries = StreamRecorder(await world.outbox.deliveries())
        await world.outbox.retry(held.id)
        await waitUntil { world.session.started.count == 2 }
        await world.answer(held.id, attempt: 2)
        _ = try await deliveries.next { $0.sendKey == held.id }
        await world.transport.waitForRequests(1, on: threadRoute)
        _ = try await deliveries.next { $0.sendKey == later.id }
        #expect(await world.outbox.items().isEmpty)
    }

    @Test func aHeldOutboxStillDropsAnsweredRecords() async throws {
        let world = UploadWorld()
        let item = try await world.outbox.enqueue(world.draft("Gone", files: ["a.jpg"]))
        await waitUntil { world.session.started.count == 1 }
        await world.relaunch { ledger in
            await world.answer(item.id, on: ledger)
        }
        // the item was delivered from another path: the list no longer holds it
        try world.files.remove("instances/\(world.instance)/outbox/items.json")
        await world.outbox.pause(.unauthorized(code: "device_revoked"))
        await world.outbox.load()
        await waitUntil { await world.ledger.records().isEmpty }
        #expect(await world.ledger.records().isEmpty)
    }

    @Test func aShareUploadIsWaitedFor() async throws {
        let world = UploadWorld()
        let id = UUID()
        let attachment = try world.storedAttachment("share.jpg", id: id)
        let item = OutboxInbox.item(
            for: OutboxDraft(target: .thread("d0e515ef"), text: "From share", attachments: [attachment]), id: id,
            now: world.clock.now())
        let shareSession = FakeUploadSession(
            identifier: UploadSessionID.share(invocation: UUID()).identifier(appBundleID: bundleID))
        _ = try await world.ledger.prepare(item, origin: .share)
        _ = await world.ledger.start(id, session: shareSession, client: makeClient(world.transport))
        try OutboxInbox.add(
            OutboxDraft(target: .thread("d0e515ef"), text: "From share", attachments: [attachment]),
            instance: world.instance, files: world.files, id: id, now: world.clock.now())
        await world.clock.advance(by: .seconds(600))
        await world.outbox.load()
        await world.settle()
        #expect(world.session.started.isEmpty)
        #expect(await world.outbox.items().first?.state == .sending)
        await world.clock.advance(by: .seconds(3_100))
        await world.outbox.retryFailedUploads()
        await waitUntil { world.session.started.count == 1 }
        #expect(world.session.started.first?.description == UploadTaskDescription(item: id, attempt: 2))
    }

    @Test func answersFollowThePolicy() async throws {
        let world = UploadWorld()
        await world.outbox.load()
        let item = try await world.outbox.enqueue(world.draft("Policy", files: ["a.jpg"]))
        await waitUntil { world.session.started.count == 1 }
        await world.answer(item.id, status: 503, body: "{}")
        await waitUntil { await world.outbox.items().first?.state == .waiting }
        await world.clock.waitForSleeper(endingAt: .seconds(1))
        await world.clock.advance(by: .seconds(1))
        await waitUntil { world.session.started.count == 2 }
        #expect(world.session.started.last?.description.attempt == 2)

        await world.answer(
            item.id, attempt: 2, status: 422, body: #"{"error":"validation_failed","message":"Too big"}"#)
        await waitUntil {
            if case .refused? = await world.outbox.items().first?.state { return true }
            return false
        }
        #expect(
            await world.outbox.items().first?.state
                == .refused(OutboxRefusal(code: "validation_failed", message: "Too big")))
        await waitUntil { await world.ledger.records().isEmpty }
        #expect(await world.ledger.records().isEmpty)

        let revoked = try await world.outbox.enqueue(world.draft("Revoked", thread: "other", files: ["b.jpg"]))
        await waitUntil { world.session.started.count == 3 }
        await world.answer(
            revoked.id, status: 401, body: #"{"error":"device_revoked","message":"Revoked"}"#)
        await waitUntil { await world.outbox.pauseReason() != nil }
        #expect(await world.outbox.pauseReason() == .unauthorized(code: "device_revoked"))
    }

    @Test func aMissingAttachmentRefuses() async throws {
        let world = UploadWorld()
        await world.outbox.load()
        let draft = try world.draft("Missing", files: ["a.jpg"])
        try world.files.remove(draft.attachments[0].path)
        _ = try await world.outbox.enqueue(draft)
        await waitUntil {
            if case .refused? = await world.outbox.items().first?.state { return true }
            return false
        }
        guard case .refused(let refusal)? = await world.outbox.items().first?.state else {
            Issue.record("not refused")
            return
        }
        #expect(refusal.code == "attachment_missing")
        #expect(world.session.started.isEmpty)
    }

    @Test func itemsWithoutFilesStayInTheForeground() async throws {
        let world = UploadWorld()
        await world.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await world.outbox.load()
        _ = try await world.outbox.enqueue(world.draft("Plain"))
        await world.transport.waitForRequests(1, on: threadRoute)
        #expect(world.session.started.isEmpty)
    }

    @Test func removeAndEraseTakeTheRecord() async throws {
        let world = UploadWorld()
        await world.outbox.load()
        let first = try await world.outbox.enqueue(world.draft("One", files: ["a.jpg"]))
        await waitUntil { world.session.started.count == 1 }
        await world.answer(first.id, failed: true)
        await waitUntil { await world.outbox.items().first?.state == .waiting }
        _ = try await world.outbox.remove(first.id)
        await waitUntil { await world.ledger.records().isEmpty }
        #expect(await world.ledger.records().isEmpty)

        let second = try await world.outbox.enqueue(world.draft("Two", files: ["b.jpg"]))
        await waitUntil { world.session.started.count == 2 }
        await waitUntil {
            await world.ledger.record(for: second.id)?.phase == .uploading(session: world.session.identifier, task: 2)
        }
        await world.outbox.eraseAll()
        #expect(await world.ledger.records().isEmpty)
        #expect(await world.outbox.items().isEmpty)
    }

    @Test func withoutUploadsNothingChanges() async throws {
        let world = UploadWorld(uploads: false)
        await world.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await world.outbox.load()
        _ = try await world.outbox.enqueue(world.draft("Inline", files: ["a.jpg"]))
        await world.transport.waitForRequests(1, on: threadRoute)
        #expect(world.session.started.isEmpty)
        let request = try #require(await world.transport.requests(on: threadRoute).first)
        #expect(request.value(forHTTPHeaderField: "Content-Type")?.hasPrefix("multipart/form-data") == true)
    }
}
