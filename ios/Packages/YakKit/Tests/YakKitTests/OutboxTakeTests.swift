import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let threadRoute = "POST threads/d0e515ef/messages"

private struct Setup {
    let files = InMemoryFileStore()
    let instance = UUID()
    let transport = ScriptedTransport()
    let outbox: Outbox

    init() {
        outbox = Outbox(
            instance: instance,
            client: APIClient(
                baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
                appVersion: "1.0", systemDescription: "Test phone"),
            files: files, clock: ManualClock(), makeID: IDSequence().next)
    }

    /// Stores one file the way the composer does, under the send key's folder.
    func attachment(_ sendKey: UUID, name: String = "a.jpg") throws -> OutboxAttachment {
        let path = OutboxInbox.attachmentPath(instance: instance, item: sendKey, fileName: name)
        try files.write(Data("bytes".utf8), to: path, protection: .untilFirstUnlock)
        return OutboxAttachment(reference: "Image #1", fileName: name, contentType: "image/jpeg", path: path)
    }

    func refusedItem(text: String = "Please") async throws -> OutboxItem {
        await transport.on(
            threadRoute, .json(409, #"{"error":"thread_closed","message":"Closed"}"#, headers: [:]))
        let key = UUID()
        let item = try await outbox.enqueue(
            OutboxDraft(target: .thread("d0e515ef"), text: text, attachments: [try attachment(key)], sendKey: key))
        let changes = StreamRecorder(await outbox.changes())
        _ = try await changes.next { items in
            if case .refused? = items.first?.state { return true }
            return false
        }
        return item
    }
}

@Suite struct OutboxTakeTests {
    @Test func takingARefusedItemKeepsItsFilesAndLeavesNothingBehind() async throws {
        let setup = Setup()
        let item = try await setup.refusedItem()
        let taken = try await setup.outbox.take(item.id)
        #expect(taken.text == "Please" && taken.attachments.count == 1)
        #expect(await setup.outbox.items().isEmpty)
        #expect(try setup.files.read(taken.attachments[0].path) != nil)
    }

    @Test func aTakenItemCanBeSentAgainUnderTheSameKeyWithItsFiles() async throws {
        let setup = Setup()
        let item = try await setup.refusedItem()
        let taken = try await setup.outbox.take(item.id)
        let again = try await setup.outbox.enqueue(
            OutboxDraft(
                target: taken.target, text: taken.text, attachments: taken.attachments, sendKey: taken.id))
        #expect(again.id == item.id)
        #expect(await setup.outbox.items().map(\.id) == [item.id])
        #expect(try setup.files.read(taken.attachments[0].path) != nil)
    }

    @Test func anItemThatIsSendingOrMayBeOnTheServerStays() async throws {
        let setup = Setup()
        await setup.transport.on(threadRoute, try .scenario(200, "scenario-message-sent"))
        await setup.transport.hold(threadRoute)
        let item = try await setup.outbox.enqueue(OutboxDraft(target: .thread("d0e515ef"), text: "Hi"))
        await setup.transport.waitForRequests(1, on: threadRoute)
        await #expect(throws: OutboxEditProblem.alreadySending) { try await setup.outbox.take(item.id) }
        #expect(await setup.outbox.items().count == 1)
    }

    @Test func aSweepRemovesOnlyFoldersNothingHolds() async throws {
        let setup = Setup()
        await setup.outbox.load()
        let orphan = UUID()
        let drafted = UUID()
        let queued = try await setup.refusedItem()
        let orphanFile = try setup.attachment(orphan)
        let draftedFile = try setup.attachment(drafted)
        await setup.outbox.sweepAttachmentFolders(keeping: [drafted.uuidString])
        #expect(try setup.files.read(orphanFile.path) == nil)
        #expect(try setup.files.read(draftedFile.path) != nil)
        #expect(try setup.files.read(queued.attachments[0].path) != nil)
    }

    @Test func theCacheNamesTheFoldersOfEverySavedDraft() async throws {
        let setup = Setup()
        let cache = Cache(instance: setup.instance, files: setup.files, clock: ManualClock())
        let threadKey = UUID()
        let newKey = UUID()
        await cache.saveComposerDraft(
            ComposerDraft(text: "a", sendKey: threadKey, attachments: [try setup.attachment(threadKey)]),
            for: .thread("d0e515ef"))
        await cache.saveComposerDraft(ComposerDraft(text: "b", sendKey: newKey), for: .newThread)
        #expect(await cache.composerAttachmentFolders() == [threadKey.uuidString, newKey.uuidString])
    }
}
