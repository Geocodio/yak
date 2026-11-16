import Foundation
import Testing

@testable import YakKit

/// Fails the listing of the inbox folder and passes everything else through.
private struct FailingInboxListStore: FileStore {
    let base: InMemoryFileStore
    func read(_ path: String) throws(FileStoreError) -> Data? { try base.read(path) }
    func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        try base.write(data, to: path, protection: protection)
    }
    func remove(_ path: String) throws(FileStoreError) { try base.remove(path) }
    func list(_ folder: String) throws(FileStoreError) -> [String] {
        if folder.hasSuffix("/incoming") { throw .unreadable }
        return try base.list(folder)
    }
}

private struct Setup {
    let files = InMemoryFileStore()
    let instance = UUID()
    let outbox: Outbox
    let cache: Cache

    init(outboxStore: (@Sendable (InMemoryFileStore) -> any FileStore)? = nil) {
        outbox = Outbox(
            instance: instance,
            client: APIClient(
                baseURL: URL(string: "https://example.test")!, token: "test-token", transport: ScriptedTransport(),
                appVersion: "1.0", systemDescription: "Test phone"),
            files: outboxStore?(files) ?? files, clock: ManualClock(), makeID: IDSequence().next)
        cache = Cache(instance: instance, files: files, clock: ManualClock())
    }

    func attachment(_ sendKey: UUID, name: String = "a.jpg") throws -> OutboxAttachment {
        let path = OutboxInbox.attachmentPath(instance: instance, item: sendKey, fileName: name)
        try files.write(Data("bytes".utf8), to: path, protection: .untilFirstUnlock)
        return OutboxAttachment(reference: "Image #1", fileName: name, contentType: "image/jpeg", path: path)
    }

    /// A saved thread draft with one stored attachment.
    func draftedAttachment() async throws -> OutboxAttachment {
        let key = UUID()
        let file = try attachment(key)
        await cache.saveComposerDraft(
            ComposerDraft(text: "a", sendKey: key, attachments: [file]), for: .thread("d0e515ef"))
        return file
    }

    func sweep() async {
        await outbox.sweepAttachmentFolders(keeping: await cache.composerAttachmentFolders())
    }
}

@Suite struct OutboxSweepTests {
    @Test func aLockedDeviceKeepsEverySavedDraftsFiles() async throws {
        let setup = Setup()
        await setup.outbox.load()
        let file = try await setup.draftedAttachment()
        setup.files.isLocked = true
        await setup.sweep()
        #expect(try setup.files.read(file.path) != nil)
    }

    @Test func anUnreadableInboxListingKeepsEveryFolder() async throws {
        let setup = Setup(outboxStore: { FailingInboxListStore(base: $0) })
        await setup.outbox.load()
        let orphan = try setup.attachment(UUID())
        await setup.outbox.sweepAttachmentFolders(keeping: [])
        #expect(try setup.files.read(orphan.path) != nil)
    }

    @Test func aDraftThatDoesNotDecodeKeepsEveryFolder() async throws {
        let setup = Setup()
        await setup.outbox.load()
        let orphan = try setup.attachment(UUID())
        try setup.files.write(
            Data("not json".utf8), to: "\(InstanceFolders.cache(setup.instance))/new-thread.json", protection: .complete)
        await setup.sweep()
        #expect(try setup.files.read(orphan.path) != nil)
    }

    @Test func aTrueOrphanIsRemovedWhenEverythingIsReadable() async throws {
        let setup = Setup()
        await setup.outbox.load()
        let kept = try await setup.draftedAttachment()
        let orphan = try setup.attachment(UUID())
        await setup.sweep()
        #expect(try setup.files.read(orphan.path) == nil)
        #expect(try setup.files.read(kept.path) != nil)
    }
}
