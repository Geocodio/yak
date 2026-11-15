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

private func draft(_ text: String, attachments: [OutboxAttachment] = []) -> OutboxDraft {
    OutboxDraft(target: .thread("d0e515ef"), text: text, attachments: attachments)
}

private func settle() async { for _ in 0..<100 { await Task.yield() } }

private struct Setup {
    let files: InMemoryFileStore
    let instance: UUID
    let transport = ScriptedTransport()
    let clock = ManualClock()
    let outbox: Outbox

    init(files: InMemoryFileStore = InMemoryFileStore(), instance: UUID = UUID()) {
        self.files = files
        self.instance = instance
        outbox = Outbox(
            instance: instance, client: makeClient(transport), files: files, clock: clock, makeID: IDSequence().next)
    }

    var itemsPath: String { "\(InstanceFolders.outbox(instance))/items.json" }
    var incomingFolder: String { "\(InstanceFolders.outbox(instance))/incoming" }
}

@Suite struct OutboxPersistenceTests {
    @Test func itemsSurviveARestart() async throws {
        let first = Setup()
        await first.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await first.transport.hold(threadRoute)
        let saved = try await first.outbox.enqueue(draft("Survive"))
        await first.transport.waitForRequests(1, on: threadRoute)

        let second = Setup(files: first.files, instance: first.instance)
        await second.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        let deliveries = StreamRecorder(await second.outbox.deliveries())
        await second.outbox.load()
        _ = await deliveries.next { _ in true }
        let request = try #require(await second.transport.requests(on: threadRoute).first)
        let body = try #require(
            request.httpBody.flatMap { try? JSONSerialization.jsonObject(with: $0) } as? [String: Any])
        #expect(body["send_key"] as? String == saved.id.uuidString)
        #expect(body["message"] as? String == "Survive")
        await first.transport.release(threadRoute)
    }

    @Test func aSendingItemIsSavedAsQueued() async throws {
        let setup = Setup()
        await setup.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await setup.transport.hold(threadRoute)
        _ = try await setup.outbox.enqueue(draft("Hold"))
        await setup.transport.waitForRequests(1, on: threadRoute)
        #expect(await setup.outbox.items().first?.state == .sending)
        let data = try #require(try setup.files.read(setup.itemsPath))
        let saved = try JSONDecoder().decode([OutboxItem].self, from: data)
        #expect(saved.map(\.state) == [.queued])
        #expect(saved.first?.attemptCount == 1)
        await setup.transport.release(threadRoute)
    }

    @Test func theShareExtensionAddsAnItem() async throws {
        let setup = Setup()
        await setup.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await setup.transport.hold(threadRoute)
        let existing = try await setup.outbox.enqueue(draft("Existing"))
        let incomingID = UUID()
        try OutboxInbox.add(
            draft("Shared"), instance: setup.instance, files: setup.files, id: incomingID,
            now: Date(timeIntervalSince1970: 4_000_000_000))
        await setup.outbox.importIncoming()
        #expect(await setup.outbox.items().map(\.id) == [existing.id, incomingID])
        #expect(try setup.files.list(setup.incomingFolder) == [])
        await setup.outbox.importIncoming()
        #expect(await setup.outbox.items().count == 2)
        await setup.transport.release(threadRoute)
        await setup.transport.waitForRequests(2, on: threadRoute)
    }

    @Test func aBrokenInboxFileIsDropped() async throws {
        let setup = Setup()
        try setup.files.write(Data("{".utf8), to: "\(setup.incomingFolder)/x.json", protection: .untilFirstUnlock)
        await setup.outbox.importIncoming()
        #expect(await setup.outbox.items().isEmpty)
        #expect(try setup.files.list(setup.incomingFolder) == [])
    }

    @Test func attachmentsGoAsMultipartInOrder() async throws {
        let setup = Setup()
        let item = UUID()
        func stored(_ name: String, _ reference: String, _ content: String) throws -> OutboxAttachment {
            let path = OutboxInbox.attachmentPath(instance: setup.instance, item: item, fileName: name)
            try setup.files.write(Data(content.utf8), to: path, protection: .untilFirstUnlock)
            return OutboxAttachment(reference: reference, fileName: name, contentType: "image/png", path: path)
        }
        let attachments = [try stored("a.png", "Image #1", "AAAA"), try stored("b.txt", "File #2", "BBBB")]
        await setup.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        let deliveries = StreamRecorder(await setup.outbox.deliveries())
        _ = try await setup.outbox.enqueue(draft("With files", attachments: attachments))
        _ = await deliveries.next { _ in true }
        let request = try #require(await setup.transport.requests(on: threadRoute).first)
        let text = String(decoding: try #require(request.httpBody), as: UTF8.self)
        let first = try #require(text.range(of: "filename=\"a.png\""))
        let second = try #require(text.range(of: "filename=\"b.txt\""))
        #expect(first.lowerBound < second.lowerBound)
        #expect(text.contains("AAAA") && text.contains("BBBB"))
        let firstRef = try #require(text.range(of: "Image #1"))
        let secondRef = try #require(text.range(of: "File #2"))
        #expect(firstRef.lowerBound < secondRef.lowerBound)
        #expect(request.value(forHTTPHeaderField: "Content-Type")?.hasPrefix("multipart/form-data") == true)
    }

    @Test func aMissingAttachmentRefusesTheItem() async throws {
        let setup = Setup()
        let missing = OutboxAttachment(
            reference: "Image #1", fileName: "gone.png", contentType: "image/png",
            path: OutboxInbox.attachmentPath(instance: setup.instance, item: UUID(), fileName: "gone.png"))
        let changes = StreamRecorder(await setup.outbox.changes())
        _ = try await setup.outbox.enqueue(draft("Lost file", attachments: [missing]))
        let refused = await changes.next {
            if case .refused(let refusal)? = $0.first?.state { return refusal.code == "attachment_missing" }
            return false
        }
        #expect(refused.first?.text == "Lost file")
        #expect(await setup.transport.requests(on: threadRoute).isEmpty)
    }

    @Test func deliveryRemovesTheAttachmentFiles() async throws {
        let setup = Setup()
        let id = UUID()
        let path = OutboxInbox.attachmentPath(instance: setup.instance, item: id, fileName: "a.png")
        try setup.files.write(Data("A".utf8), to: path, protection: .untilFirstUnlock)
        let attachment = OutboxAttachment(
            reference: "Image #1", fileName: "a.png", contentType: "image/png", path: path)
        try OutboxInbox.add(
            draft("Shared", attachments: [attachment]), instance: setup.instance, files: setup.files, id: id,
            now: Date(timeIntervalSince1970: 1))
        await setup.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        let deliveries = StreamRecorder(await setup.outbox.deliveries())
        await setup.outbox.load()
        _ = await deliveries.next { _ in true }
        await settle()
        #expect(try setup.files.list("\(InstanceFolders.outbox(setup.instance))/files/\(id.uuidString)") == [])
    }

    @Test func eraseAllRemovesTheOutboxFolder() async throws {
        let setup = Setup()
        await setup.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await setup.transport.hold(threadRoute)
        _ = try await setup.outbox.enqueue(draft("Erase me"))
        try OutboxInbox.add(
            draft("Shared"), instance: setup.instance, files: setup.files, id: UUID(), now: Date())
        await setup.outbox.eraseAll()
        #expect(try setup.files.list(InstanceFolders.outbox(setup.instance)) == [])
        #expect(await setup.outbox.items().isEmpty)
        await setup.transport.release(threadRoute)
    }

    @Test func everyFileIsReadableWhileLocked() async throws {
        let setup = Setup()
        await setup.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await setup.transport.hold(threadRoute)
        _ = try await setup.outbox.enqueue(draft("Locked"))
        let id = UUID()
        try OutboxInbox.add(draft("Shared"), instance: setup.instance, files: setup.files, id: id, now: Date())
        #expect(setup.files.protection(of: setup.itemsPath) == .untilFirstUnlock)
        #expect(setup.files.protection(of: "\(setup.incomingFolder)/\(id.uuidString).json") == .untilFirstUnlock)
        await setup.transport.release(threadRoute)
    }
}
