import Foundation
import Testing
import YakKit

@testable import Yak

private struct Fixture {
    let files = DiskFileStore(
        root: FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString))
    let instance = UUID()
    let key = UUID()
    let staging = "\(InstanceFolders.shareStaging)/test"

    func stage(_ names: [String]) throws {
        for name in names {
            try files.write(Data(name.utf8), to: "\(staging)/\(name)", protection: .untilFirstUnlock)
        }
    }

    func draft(_ names: [String]) -> OutboxDraft {
        OutboxDraft(
            target: .thread("d0e515ef"), text: "Look",
            attachments: names.map {
                OutboxAttachment(
                    reference: "Image #1", fileName: $0, contentType: "image/jpeg",
                    path: OutboxInbox.attachmentPath(instance: instance, item: key, fileName: $0))
            }, sendKey: key)
    }

    func sender(startsUploads: Bool) -> ShareSender {
        ShareSender(
            files: files, appBundleID: "com.example.yak", appGroup: nil, clock: SystemClock(),
            makeClient: { _, _ in
                guard startsUploads else { return nil }
                return APIClient(
                    baseURL: URL(string: "http://127.0.0.1:9")!, token: "test-token",
                    transport: URLSessionTransport(), appVersion: "1", systemDescription: "test")
            })
    }

    var incomingPath: String { "\(InstanceFolders.outbox(instance))/incoming/\(key.uuidString).json" }
    var ledger: UploadLedger { UploadLedger(instance: instance, files: files, clock: SystemClock()) }

    /// A folder where the inbox item belongs, so writing the item fails.
    func blockTheInbox() throws {
        try files.write(Data(), to: "\(incomingPath)/blocker", protection: .untilFirstUnlock)
    }
}

struct ShareSenderTests {
    @Test func sendWritesTheFilesTheRecordAndLastTheInboxItem() async throws {
        let fixture = Fixture()
        try fixture.stage(["1-a.jpg", "2-b.jpg"])
        let draft = fixture.draft(["1-a.jpg", "2-b.jpg"])
        try await fixture.sender(startsUploads: false)
            .send(draft, stagingFolder: fixture.staging, instance: fixture.instance, token: "test-token")
        for attachment in draft.attachments { #expect(try fixture.files.exists(attachment.path)) }
        #expect(try fixture.files.list(fixture.staging).isEmpty)
        #expect(await fixture.ledger.record(for: fixture.key)?.phase == .prepared)
        #expect(try fixture.files.exists(fixture.incomingPath))
    }

    @Test func aFailureBeforeTheUploadStartsPutsTheFilesBack() async throws {
        let fixture = Fixture()
        try fixture.stage(["1-a.jpg"])
        let draft = fixture.draft(["1-a.jpg", "2-b.jpg"])
        await #expect(throws: ShareSendProblem.notSaved) {
            try await fixture.sender(startsUploads: false)
                .send(draft, stagingFolder: fixture.staging, instance: fixture.instance, token: "test-token")
        }
        #expect(try fixture.files.list(fixture.staging) == ["1-a.jpg"])
        #expect(await fixture.ledger.record(for: fixture.key) == nil)
        #expect(!(try fixture.files.exists(draft.attachments[0].path)))
        // The same draft, with the key it already has, goes through once the missing file is back.
        try fixture.stage(["2-b.jpg"])
        try await fixture.sender(startsUploads: false)
            .send(draft, stagingFolder: fixture.staging, instance: fixture.instance, token: "test-token")
        #expect(try fixture.files.exists(fixture.incomingPath))
    }

    @Test func aFailedInboxWriteWithNothingStartedPutsTheFilesBack() async throws {
        let fixture = Fixture()
        try fixture.stage(["1-a.jpg"])
        try fixture.blockTheInbox()
        await #expect(throws: ShareSendProblem.notSaved) {
            try await fixture.sender(startsUploads: false)
                .send(
                    fixture.draft(["1-a.jpg"]), stagingFolder: fixture.staging, instance: fixture.instance,
                    token: "test-token")
        }
        #expect(try fixture.files.list(fixture.staging) == ["1-a.jpg"])
        #expect(await fixture.ledger.record(for: fixture.key) == nil)
    }

    @Test func aFailedInboxWriteAfterTheUploadStartedStillSucceeds() async throws {
        let fixture = Fixture()
        try fixture.stage(["1-a.jpg"])
        try fixture.blockTheInbox()
        try await fixture.sender(startsUploads: true)
            .send(
                fixture.draft(["1-a.jpg"]), stagingFolder: fixture.staging, instance: fixture.instance,
                token: "test-token")
        let record = await fixture.ledger.record(for: fixture.key)
        guard case .uploading? = record?.phase else {
            Issue.record("the upload did not start: \(String(describing: record?.phase))")
            return
        }
    }
}
