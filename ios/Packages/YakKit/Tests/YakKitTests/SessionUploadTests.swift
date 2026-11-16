import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let bundleID = "com.example.yak"
private let threadID = "d0e515ef"

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct UploadRig {
    let session: InstanceSession
    let transport: ScriptedTransport
    let files: InMemoryFileStore
    let clock: ManualClock
    let instance: Instance
    let ledger: UploadLedger
    let uploadSession: FakeUploadSession
}

private func makeUploadRig(withUploads: Bool = true) async throws -> UploadRig {
    let clock = ManualClock()
    let files = InMemoryFileStore()
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { clock.now() })
    let instance = try await instances.add(
        url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test person", token: "test-token")
    let transport = ScriptedTransport()
    await transport.on("GET me", try .scenario(200, "scenario-me"))
    let privateAnswer = try FakeTransport.Result.scenario(200, "scenario-broadcasting-auth-private")
    let presenceAnswer = try FakeTransport.Result.scenario(200, "scenario-broadcasting-auth-presence")
    await transport.on(prefix: "POST broadcasting/auth") { request in
        String(decoding: request.httpBody ?? Data(), as: UTF8.self).contains("presence-")
            ? presenceAnswer : privateAnswer
    }
    let ledger = UploadLedger(instance: instance.id, files: files, clock: clock)
    let uploadSession = FakeUploadSession(
        identifier: UploadSessionID.app(instance: instance.id).identifier(appBundleID: bundleID))
    let link = UploadLink(ledger: ledger, session: uploadSession)
    let ids = IDSequence()
    let uploads: (@Sendable (UUID) -> UploadLink?)?
    if withUploads { uploads = { @Sendable _ in link } } else { uploads = nil }
    let environment = SessionEnvironment(
        http: transport, webSockets: FakeWebSocketServer(), files: files, clock: clock, makeID: { ids.next() },
        appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false,
        uploads: uploads)
    let session = InstanceSession(instance: instance, instances: instances, tokens: tokens, environment: environment)
    return UploadRig(
        session: session, transport: transport, files: files, clock: clock, instance: instance, ledger: ledger,
        uploadSession: uploadSession)
}

private func attachmentDraft(_ rig: UploadRig, item: UUID, text: String) throws -> OutboxDraft {
    let path = OutboxInbox.attachmentPath(instance: rig.instance.id, item: item, fileName: "photo.jpg")
    try rig.files.write(Data("bytes".utf8), to: path, protection: .untilFirstUnlock)
    return OutboxDraft(
        target: .thread(threadID), text: text,
        attachments: [
            OutboxAttachment(reference: "Image #1", fileName: "photo.jpg", contentType: "image/jpeg", path: path)
        ]
    )
}

private func waitUntil(
    sourceLocation: SourceLocation = #_sourceLocation, _ condition: @Sendable () async -> Bool
) async {
    let deadline = ContinuousClock.now + .seconds(10)
    while ContinuousClock.now < deadline {
        if await condition() { return }
        await Task.yield()
    }
    Issue.record("waitUntil gave up: the condition was not met", sourceLocation: sourceLocation)
}

@Suite struct SessionUploadTests {
    @Test func aRevokeHeldOutboxStillDropsAnsweredRecords() async throws {
        let rig = try await makeUploadRig()
        let id = UUID()
        let draft = try attachmentDraft(rig, item: id, text: "Gone")
        let item = OutboxInbox.item(for: draft, id: id, now: rig.clock.now())
        _ = try await rig.ledger.prepare(item, origin: .app)
        let client = APIClient(
            baseURL: rig.instance.url, token: "test-token", transport: rig.transport, appVersion: "1.0",
            systemDescription: "Test")
        _ = await rig.ledger.start(id, session: rig.uploadSession, client: client)
        let description = UploadTaskDescription(item: id, attempt: 1)
        await rig.ledger.received(description, data: Data(#"{"status":"sent","uuid":"m-1"}"#.utf8))
        await rig.ledger.completed(
            description, session: rig.uploadSession.identifier, task: 1, status: 200, headers: [:], failed: false)
        #expect(await rig.ledger.records().count == 1)

        // The list does not hold the item, and the instance is revoked at start: the held outbox settles the record.
        await rig.transport.on(
            "GET me", .json(401, #"{"error":"device_revoked","message":"This device was removed."}"#))
        await rig.session.start()
        await waitUntil { await rig.ledger.records().isEmpty }
        #expect(await rig.ledger.records().isEmpty)
        #expect(await rig.session.outbox()?.pauseReason() == .unauthorized(code: "device_revoked"))
        await rig.session.stop()
    }

    @Test func theSessionOffersSummariesAndRetriesFailedUploads() async throws {
        let rig = try await makeUploadRig()
        await rig.session.start()
        #expect(await rig.session.uploadSummaries() != nil)
        let id = UUID()
        let item = try await rig.session.send(try attachmentDraft(rig, item: id, text: "With file"))
        await waitUntil { rig.uploadSession.started.count == 1 }
        let description = UploadTaskDescription(item: item.id, attempt: 1)
        await rig.ledger.completed(
            description, session: rig.uploadSession.identifier, task: 1, status: nil, headers: [:], failed: true)
        await waitUntil { await rig.session.outbox()?.items().first?.state == .waiting }
        await rig.session.retryFailedUploads()
        await waitUntil { rig.uploadSession.started.count == 2 }
        #expect(rig.uploadSession.started.last?.description.attempt == 2)
        await rig.session.stop()
    }

    @Test func theSummariesAndTheOutboxShareOneLedger() async throws {
        let rig = try await makeUploadRig()
        await rig.session.start()
        let summaries = StreamRecorder(try #require(await rig.session.uploadSummaries()))
        let item = try await rig.session.send(try attachmentDraft(rig, item: UUID(), text: "With file"))
        await waitUntil { rig.uploadSession.started.count == 1 }
        await rig.ledger.completed(
            UploadTaskDescription(item: item.id, attempt: 1), session: rig.uploadSession.identifier, task: 1,
            status: nil, headers: [:], failed: true)
        _ = try await summaries.next { $0.failed.contains(item.id) }
        await rig.session.stop()
    }

    @Test func withoutALinkThereAreNoSummaries() async throws {
        let rig = try await makeUploadRig(withUploads: false)
        await rig.session.start()
        #expect(await rig.session.uploadSummaries() == nil)
        await rig.session.retryFailedUploads()
        await rig.session.stop()
    }
}
