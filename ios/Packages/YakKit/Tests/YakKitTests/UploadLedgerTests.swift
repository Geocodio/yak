import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let bundleID = "com.example.yak"

private struct Setup {
    let instanceID = UUID()
    let files = InMemoryFileStore()
    let clock = ManualClock()
    let session: FakeUploadSession
    let client = APIClient(
        baseURL: URL(string: "https://yak.example.com")!, token: "yakdev_abc",
        transport: FakeTransport(.json(200, "{}")), appVersion: "1.0.0", systemDescription: "27.2.1; iPhone19,4")

    init() {
        session = FakeUploadSession(identifier: "placeholder")
    }

    func ledger() -> UploadLedger { UploadLedger(instance: instanceID, files: files, clock: clock) }

    func session(_ id: UploadSessionID) -> FakeUploadSession {
        FakeUploadSession(identifier: id.identifier(appBundleID: bundleID))
    }

    func item(target: OutboxTarget = .thread("d0e515ef"), attachments: [String] = ["photo.jpg"]) throws -> OutboxItem {
        let id = UUID()
        let stored = try attachments.map { name -> OutboxAttachment in
            let path = OutboxInbox.attachmentPath(instance: instanceID, item: id, fileName: name)
            try files.write(Data("bytes of \(name)".utf8), to: path, protection: .untilFirstUnlock)
            return OutboxAttachment(reference: "Image #1", fileName: name, contentType: "image/jpeg", path: path)
        }
        return OutboxInbox.item(
            for: OutboxDraft(target: target, text: "Look", attachments: stored), id: id, now: clock.now())
    }

    func recordPath(_ item: UUID) -> String { "\(InstanceFolders.uploads(instanceID))/\(item.uuidString).json" }
    func bodyPath(_ item: UUID) -> String { "\(InstanceFolders.uploads(instanceID))/\(item.uuidString).body" }
}

private func answer(
    _ ledger: UploadLedger, _ item: UUID, attempt: Int = 1, status: Int, body: String, session: String,
    headers: [String: String] = [:], task: Int = 1
) async {
    let description = UploadTaskDescription(item: item, attempt: attempt)
    await ledger.received(description, data: Data(body.utf8))
    await ledger.completed(
        description, session: session, task: task, status: status, headers: headers, failed: false)
}

@Test func aRecordSurvivesARelaunchAndAnAnswerLands() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let item = try setup.item()
    let first = setup.ledger()
    _ = try await first.prepare(item, origin: .app)
    let started = await first.start(item.id, session: session, client: setup.client)
    #expect(started?.phase == .uploading(session: session.identifier, task: 1))
    #expect(session.started.map(\.description.text) == ["\(item.id)/1"])
    #expect(session.started.first?.request.value(forHTTPHeaderField: "Authorization") == "Bearer yakdev_abc")
    let again = setup.ledger()
    await answer(
        again, item.id, status: 200, body: #"{"status":"sent","uuid":"m-1"}"#, session: session.identifier)
    #expect(await again.outcome(for: item.id) == .delivered(status: .sent, messageUUID: "m-1", threadID: nil))
    #expect(try setup.files.read(setup.bodyPath(item.id)) == nil)
}

@Test func theBodyFileIsTheRequestBody() async throws {
    let setup = Setup()
    let item = try setup.item(attachments: ["a.jpg", "b.jpg"])
    let ledger = setup.ledger()
    let record = try await ledger.prepare(item, origin: .share)
    let expected = Endpoint.sendMessage(
        "d0e515ef", text: "Look", model: nil, delivery: .steer, sendKey: item.id,
        attachments: [
            .file(name: "attachments[]", fileName: "a.jpg", contentType: "image/jpeg", data: Data("bytes of a.jpg".utf8)),
            .file(name: "attachments[]", fileName: "b.jpg", contentType: "image/jpeg", data: Data("bytes of b.jpg".utf8)),
        ], references: ["Image #1", "Image #1"]
    ).multipart?.encoded()
    #expect(try setup.files.read(record.bodyPath) == expected)
    #expect(record.path == "threads/d0e515ef/messages")
    #expect(record.attempts == 0)
    #expect(record.phase == .prepared)
}

@Test func aNewThreadRecordPostsToThreads() async throws {
    let setup = Setup()
    let draft = NewThreadDraft(repo: "example/web", mode: "fix", repositories: [], claudeAccountId: nil, model: nil)
    let item = try setup.item(target: .newThread(draft))
    let ledger = setup.ledger()
    let session = setup.session(.app(instance: setup.instanceID))
    let record = try await ledger.prepare(item, origin: .app)
    #expect(record.path == "threads")
    _ = await ledger.start(item.id, session: session, client: setup.client)
    await answer(ledger, item.id, status: 201, body: #"{"id":"t-9"}"#, session: session.identifier)
    #expect(await ledger.outcome(for: item.id) == .delivered(status: .sent, messageUUID: nil, threadID: "t-9"))
}

@Test func anItemWithoutFilesIsNotPrepared() async throws {
    let setup = Setup()
    let item = try setup.item(attachments: [])
    await #expect(throws: MultipartFileProblem.self) { try await setup.ledger().prepare(item, origin: .app) }
    #expect(await setup.ledger().records().isEmpty)
}

@Test func aMissingAttachmentLeavesNoRecord() async throws {
    let setup = Setup()
    let item = try setup.item()
    try setup.files.remove(item.attachments[0].path)
    await #expect(throws: MultipartFileProblem.attachmentMissing(fileName: "photo.jpg")) {
        try await setup.ledger().prepare(item, origin: .app)
    }
    #expect(await setup.ledger().records().isEmpty)
    #expect(try setup.files.read(setup.bodyPath(item.id)) == nil)
}

@Test func aLostTaskIsFailedAndAnAnswerStillWins() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    _ = await ledger.start(item.id, session: session, client: setup.client)
    await ledger.reconcile(session: session.identifier, live: [])
    #expect(await ledger.record(for: item.id)?.phase == .failed(.lost))
    #expect(await ledger.outcome(for: item.id) == nil)
    await answer(
        ledger, item.id, status: 200, body: #"{"status":"queued"}"#, session: session.identifier)
    #expect(await ledger.outcome(for: item.id) == .delivered(status: .queued, messageUUID: nil, threadID: nil))
}

@Test func reconcileKeepsLiveTasksAndOtherSessions() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    _ = await ledger.start(item.id, session: session, client: setup.client)
    await ledger.reconcile(session: "another", live: [])
    await ledger.reconcile(session: session.identifier, live: [UploadTaskDescription(item: item.id, attempt: 1)])
    #expect(await ledger.record(for: item.id)?.phase == .uploading(session: session.identifier, task: 1))
}

@Test func strayCompletionsChangeNothing() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    _ = await ledger.start(item.id, session: session, client: setup.client)
    await answer(ledger, UUID(), status: 200, body: "{}", session: session.identifier)
    #expect(await ledger.record(for: item.id)?.phase == .uploading(session: session.identifier, task: 1))
    _ = await ledger.start(item.id, session: session, client: setup.client)
    #expect(await ledger.record(for: item.id)?.attempts == 2)
    await answer(ledger, item.id, attempt: 1, status: 200, body: "{}", session: session.identifier)
    #expect(await ledger.record(for: item.id)?.phase == .uploading(session: session.identifier, task: 2))
    await answer(
        ledger, item.id, attempt: 2, status: 200, body: #"{"status":"sent"}"#, session: session.identifier, task: 2)
    let answered = await ledger.record(for: item.id)
    await answer(ledger, item.id, attempt: 2, status: 500, body: "x", session: session.identifier, task: 2)
    #expect(await ledger.record(for: item.id) == answered)
}

@Test func aTransportFailureKeepsTheBodyForARetry() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    _ = await ledger.start(item.id, session: session, client: setup.client)
    await ledger.completed(
        UploadTaskDescription(item: item.id, attempt: 1), session: session.identifier, task: 1, status: nil,
        headers: [:], failed: true)
    #expect(await ledger.record(for: item.id)?.phase == .failed(.transport))
    #expect(try setup.files.read(setup.bodyPath(item.id)) != nil)
    #expect(await ledger.retryable().map(\.itemID) == [item.id])
}

@Test func sameTaskNumbersOnTwoShareSessionsStayApart() async throws {
    let first = Setup(), second = Setup()
    let firstItem = try first.item(), secondItem = try second.item()
    let ledgerOne = first.ledger(), ledgerTwo = second.ledger()
    let sessionOne = first.session(.share(invocation: UUID()))
    let sessionTwo = second.session(.share(invocation: UUID()))
    _ = try await ledgerOne.prepare(firstItem, origin: .share)
    _ = try await ledgerTwo.prepare(secondItem, origin: .share)
    _ = await ledgerOne.start(firstItem.id, session: sessionOne, client: first.client)
    _ = await ledgerTwo.start(secondItem.id, session: sessionTwo, client: second.client)
    let description = UploadTaskDescription(item: firstItem.id, attempt: 1)
    #expect(await ledgerOne.owns(description))
    #expect(await ledgerTwo.owns(description) == false)
    await answer(
        ledgerOne, firstItem.id, status: 200, body: #"{"status":"sent","uuid":"one"}"#, session: sessionOne.identifier)
    await answer(
        ledgerTwo, secondItem.id, status: 200, body: #"{"status":"sent","uuid":"two"}"#, session: sessionTwo.identifier)
    #expect(await ledgerOne.outcome(for: firstItem.id) == .delivered(status: .sent, messageUUID: "one", threadID: nil))
    #expect(await ledgerTwo.outcome(for: secondItem.id) == .delivered(status: .sent, messageUUID: "two", threadID: nil))
}

@Test func aRecordWrittenByAnotherLedgerIsSeen() async throws {
    let setup = Setup()
    let session = setup.session(.share(invocation: UUID()))
    let item = try setup.item()
    let reader = setup.ledger()
    #expect(await reader.record(for: item.id) == nil)
    let writer = setup.ledger()
    _ = try await writer.prepare(item, origin: .share)
    _ = await writer.start(item.id, session: session, client: setup.client)
    #expect(await reader.record(for: item.id)?.origin == .share)
    #expect(await reader.records().count == 1)
    await setup.clock.advance(by: .seconds(3601))
    #expect(await reader.retryable().map(\.itemID) == [item.id])
}

@Test func inboxItemsMatchTheRecord() throws {
    let setup = Setup()
    let item = try setup.item()
    let draft = OutboxDraft(
        target: item.target, text: item.text, model: "m", delivery: .steer, attachments: item.attachments,
        intent: .fixPreview)
    let id = UUID()
    let now = setup.clock.now()
    let built = OutboxInbox.item(for: draft, id: id, now: now)
    try OutboxInbox.add(draft, instance: setup.instanceID, files: setup.files, id: id, now: now)
    let stored = try #require(
        try setup.files.read("\(InstanceFolders.outbox(setup.instanceID))/incoming/\(id.uuidString).json"))
    #expect(try JSONDecoder().decode(OutboxItem.self, from: stored) == built)
    #expect(built.id == id && built.text == "Look" && built.attachments == item.attachments)
}

@Test func taskDescriptionsParseStrictly() {
    let item = UUID()
    let description = UploadTaskDescription("\(item.uuidString)/1")
    #expect(description == UploadTaskDescription(item: item, attempt: 1))
    #expect(description?.text == "\(item.uuidString)/1")
    #expect(UploadTaskDescription("\(item.uuidString)/10000") != nil)
    for text in [
        item.uuidString, "\(item.uuidString)/0", "\(item.uuidString)/x", "\(item.uuidString)/1/2",
        "\(item.uuidString)/10001", "\(item.uuidString)/01", "\(item.uuidString)/+1", "\(item.uuidString)/-1",
        "not-a-uuid/1", "",
    ] {
        #expect(UploadTaskDescription(text) == nil, "\(text)")
    }
}

@Test func retryableRecords() async throws {
    let setup = Setup()
    let ledger = setup.ledger()
    let session = setup.session(.share(invocation: UUID()))
    let failed = try setup.item(), sharing = try setup.item(), prepared = try setup.item()
    _ = try await ledger.prepare(failed, origin: .app)
    _ = await ledger.start(failed.id, session: session, client: setup.client)
    await ledger.completed(
        UploadTaskDescription(item: failed.id, attempt: 1), session: session.identifier, task: 1, status: nil,
        headers: [:], failed: true)
    _ = try await ledger.prepare(sharing, origin: .share)
    _ = await ledger.start(sharing.id, session: session, client: setup.client)
    _ = try await ledger.prepare(prepared, origin: .app)
    await setup.clock.advance(by: .seconds(60))
    #expect(Set(await ledger.retryable().map(\.itemID)) == [failed.id])
    await setup.clock.advance(by: .seconds(60 * 2))
    #expect(Set(await ledger.retryable().map(\.itemID)) == [failed.id, prepared.id])
    await setup.clock.advance(by: .seconds(60 * 56))
    #expect(Set(await ledger.retryable().map(\.itemID)) == [failed.id, prepared.id])
    await setup.clock.advance(by: .seconds(60 * 2))
    #expect(Set(await ledger.retryable().map(\.itemID)) == [failed.id, prepared.id, sharing.id])
}

@Test func answersAreCutAtTheLimit() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    _ = await ledger.start(item.id, session: session, client: setup.client)
    await answer(
        ledger, item.id, status: 200, body: String(repeating: "x", count: 70_000), session: session.identifier)
    guard case .answered(_, let body, _, _)? = await ledger.record(for: item.id)?.phase else {
        Issue.record("not answered")
        return
    }
    #expect(body.count == 65_536)
    #expect(await ledger.outcome(for: item.id) == .decision(.retry(after: .seconds(1))))
}

@Test func refusalsAndPausesComeFromThePolicy() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let ledger = setup.ledger()
    let cases: [(Int, String, [String: String], UploadOutcome)] = [
        (
            422, #"{"error":"validation_failed","message":"Too long"}"#, [:],
            .decision(.refuse(OutboxRefusal(code: "validation_failed", message: "Too long")))
        ),
        (
            401, #"{"error":"device_revoked","message":"Revoked"}"#, [:],
            .decision(.pause(.unauthorized(code: "device_revoked")))
        ),
        (503, "", [:], .decision(.retry(after: .seconds(1)))),
        (
            429, #"{"error":"rate_limited","message":"Slow down"}"#, ["retry-after": "30"],
            .decision(.retry(after: .seconds(30)))
        ),
    ]
    for (status, body, headers, expected) in cases {
        let item = try setup.item()
        _ = try await ledger.prepare(item, origin: .app)
        _ = await ledger.start(item.id, session: session, client: setup.client)
        await answer(ledger, item.id, status: status, body: body, session: session.identifier, headers: headers)
        #expect(await ledger.outcome(for: item.id) == expected, "status \(status)")
    }
}

@Test func theProtocolHeaderOfAnAnswerIsApplied() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    _ = await ledger.start(item.id, session: session, client: setup.client)
    await answer(
        ledger, item.id, status: 200, body: "{}", session: session.identifier, headers: ["x-yak-protocol": "0"])
    #expect(await ledger.outcome(for: item.id) == .decision(.pause(.serverTooOld)))
}

@Test func hostileRecordFilesAreDropped() async throws {
    let setup = Setup()
    let ledger = setup.ledger()
    let other = try setup.item(), huge = UUID(), garbage = UUID(), foreign = UUID()
    let mine = try await ledger.prepare(other, origin: .app)
    try setup.files.write(Data(repeating: 0x20, count: 200_000), to: setup.recordPath(huge), protection: .complete)
    try setup.files.write(Data(), to: setup.bodyPath(huge), protection: .complete)
    try setup.files.write(Data("not json".utf8), to: setup.recordPath(garbage), protection: .complete)
    try setup.files.write(Data(), to: setup.bodyPath(garbage), protection: .complete)
    try setup.files.write(
        JSONEncoder().encode(mine), to: setup.recordPath(foreign), protection: .untilFirstUnlock)
    try setup.files.write(Data(), to: setup.bodyPath(foreign), protection: .complete)
    #expect(await ledger.record(for: huge) == nil)
    #expect(await ledger.record(for: garbage) == nil)
    #expect(await ledger.record(for: foreign) == nil)
    #expect(await ledger.records().map(\.itemID) == [other.id])
    for id in [huge, garbage, foreign] {
        #expect(try setup.files.read(setup.recordPath(id)) == nil)
        #expect(try setup.files.read(setup.bodyPath(id)) == nil)
    }
}

@Test func sessionIdentifiersParseStrictly() {
    let id = UUID()
    for value in [UploadSessionID.app(instance: id), .share(invocation: id)] {
        let text = value.identifier(appBundleID: bundleID)
        #expect(UploadSessionID(text, appBundleID: bundleID) == value)
    }
    #expect(UploadSessionID.app(instance: id).identifier(appBundleID: bundleID) == "\(bundleID).upload.\(id)")
    #expect(UploadSessionID.share(invocation: id).identifier(appBundleID: bundleID) == "\(bundleID).share.upload.\(id)")
    for text in [
        "\(bundleID).upload.not-a-uuid", "\(bundleID).share.upload", "\(bundleID).share.upload.\(id).extra",
        "\(bundleID).upload.\(id) ", "com.example.other.upload.\(id)", "",
    ] {
        #expect(UploadSessionID(text, appBundleID: bundleID) == nil, "\(text)")
    }
}

@Test func progressIsThrottled() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    _ = await ledger.start(item.id, session: session, client: setup.client)
    let description = UploadTaskDescription(item: item.id, attempt: 1)
    var iterator = await ledger.summaries().makeAsyncIterator()
    #expect(await iterator.next() == UploadSummary())
    for sentBytes in 1...100 { await ledger.sent(description, bytes: Int64(sentBytes), of: 100) }
    await setup.clock.waitForSleepers(1)
    await setup.clock.advance(by: .milliseconds(250))
    #expect(await iterator.next() == UploadSummary(progress: [item.id: 1.0]))
    await answer(ledger, item.id, status: 200, body: #"{"status":"sent"}"#, session: session.identifier)
    #expect(await iterator.next() == UploadSummary())
}

@Test func aFailureIsPublishedAtOnce() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    _ = await ledger.start(item.id, session: session, client: setup.client)
    var iterator = await ledger.summaries().makeAsyncIterator()
    _ = await iterator.next()
    await ledger.reconcile(session: session.identifier, live: [])
    #expect(await iterator.next() == UploadSummary(failed: [item.id]))
}

@Test func completionsAreAnnounced() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    _ = await ledger.start(item.id, session: session, client: setup.client)
    var iterator = await ledger.completions().makeAsyncIterator()
    await answer(ledger, item.id, status: 200, body: "{}", session: session.identifier)
    #expect(await iterator.next() == item.id)
}

@Test func aStartProblemIsNotStarted() async throws {
    let setup = Setup()
    let session = setup.session(.app(instance: setup.instanceID))
    session.startProblem = .noSession
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    let record = await ledger.start(item.id, session: session, client: setup.client)
    #expect(record?.phase == .failed(.notStarted))
    #expect(record?.attempts == 0)
    #expect(await ledger.retryable().map(\.itemID) == [item.id])
}

@Test func removeDeletesTheRecordAndTheBody() async throws {
    let setup = Setup()
    let item = try setup.item()
    let ledger = setup.ledger()
    _ = try await ledger.prepare(item, origin: .app)
    await ledger.remove(item.id)
    #expect(await ledger.records().isEmpty)
    #expect(try setup.files.read(setup.bodyPath(item.id)) == nil)
}
