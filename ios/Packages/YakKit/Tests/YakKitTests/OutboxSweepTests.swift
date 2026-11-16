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

/// Fails writes to the sweep's candidate file while `failsCandidateWrites` is set, and the listing of the upload
/// records folder while `failsUploadListing` is set. Everything else passes through.
private final class FlakyStore: FileStore, @unchecked Sendable {
    let base: InMemoryFileStore
    private let lock = NSLock()
    private var flags = (candidates: false, uploads: false)
    init(base: InMemoryFileStore) { self.base = base }
    var failsCandidateWrites: Bool {
        get { lock.withLock { flags.candidates } }
        set { lock.withLock { flags.candidates = newValue } }
    }
    var failsUploadListing: Bool {
        get { lock.withLock { flags.uploads } }
        set { lock.withLock { flags.uploads = newValue } }
    }
    func read(_ path: String) throws(FileStoreError) -> Data? { try base.read(path) }
    func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        if failsCandidateWrites, path.hasSuffix("/sweep-candidates.json") { throw .unreadable }
        try base.write(data, to: path, protection: protection)
    }
    func remove(_ path: String) throws(FileStoreError) { try base.remove(path) }
    func list(_ folder: String) throws(FileStoreError) -> [String] {
        if failsUploadListing, folder.hasSuffix("/uploads") { throw .unreadable }
        return try base.list(folder)
    }
}

private struct Setup {
    let files: InMemoryFileStore
    let instance: UUID
    let outbox: Outbox
    let cache: Cache

    init(
        instance: UUID = UUID(), files: InMemoryFileStore = InMemoryFileStore(), uploads: UploadLink? = nil,
        outboxStore: (@Sendable (InMemoryFileStore) -> any FileStore)? = nil
    ) {
        self.instance = instance
        self.files = files
        outbox = Outbox(
            instance: instance,
            client: APIClient(
                baseURL: URL(string: "https://example.test")!, token: "test-token", transport: ScriptedTransport(),
                appVersion: "1.0", systemDescription: "Test phone"),
            files: outboxStore?(files) ?? files, clock: ManualClock(), makeID: IDSequence().next, uploads: uploads)
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
            Data("not json".utf8), to: "\(InstanceFolders.cache(setup.instance))/new-thread.json", protection: .complete
        )
        await setup.sweep()
        #expect(try setup.files.read(orphan.path) != nil)
    }

    @Test func aTrueOrphanIsRemovedWhenEverythingIsReadable() async throws {
        let setup = Setup()
        await setup.outbox.load()
        let kept = try await setup.draftedAttachment()
        let orphan = try setup.attachment(UUID())
        await setup.sweep()
        await setup.sweep()
        #expect(try setup.files.read(orphan.path) == nil)
        #expect(try setup.files.read(kept.path) != nil)
    }

    @Test func aFolderThatAppearsBetweenSweepsSurvivesTheFirstOne() async throws {
        let setup = Setup()
        await setup.outbox.load()
        await setup.sweep()
        let fresh = try setup.attachment(UUID())
        await setup.sweep()
        #expect(try setup.files.read(fresh.path) != nil)
        await setup.sweep()
        #expect(try setup.files.read(fresh.path) == nil)
    }

    @Test func aFolderThatBecomesHeldBetweenSweepsIsKept() async throws {
        let setup = Setup()
        await setup.outbox.load()
        let key = UUID()
        let file = try setup.attachment(key)
        await setup.sweep()
        await setup.cache.saveComposerDraft(
            ComposerDraft(text: "a", sendKey: key, attachments: [file]), for: .newThread)
        await setup.sweep()
        await setup.sweep()
        #expect(try setup.files.read(file.path) != nil)
    }

    @Test func aFolderWithAnUploadRecordIsKept() async throws {
        let setup = Setup()
        let session = FakeUploadSession(identifier: "test")
        let ledger = UploadLedger(instance: setup.instance, files: setup.files, clock: ManualClock())
        let outbox = Setup(
            instance: setup.instance, files: setup.files, uploads: UploadLink(ledger: ledger, session: session))
        await outbox.outbox.load()
        let id = UUID()
        let file = try setup.attachment(id)
        let item = OutboxInbox.item(
            for: OutboxDraft(target: .thread("d0e515ef"), text: "Hi", attachments: [file], sendKey: id), id: id,
            now: Date(timeIntervalSince1970: 0))
        _ = try await ledger.prepare(item, origin: .app)
        await outbox.sweep()
        await outbox.sweep()
        #expect(try setup.files.read(file.path) != nil)
    }

    @Test func aFailedCandidateWriteDoesNotLeaveAnOlderCandidateBehind() async throws {
        let setup = Setup()
        await setup.outbox.load()
        let key = UUID()
        let held = try setup.attachment(key)
        await setup.sweep()
        await setup.cache.saveComposerDraft(
            ComposerDraft(text: "a", sendKey: key, attachments: [held]), for: .thread("d0e515ef"))
        let newer = try setup.attachment(UUID())
        let store = FlakyStore(base: setup.files)
        let flaky = Setup(instance: setup.instance, files: setup.files, outboxStore: { _ in store })
        await flaky.outbox.load()
        store.failsCandidateWrites = true
        await flaky.sweep()
        store.failsCandidateWrites = false
        await setup.cache.saveComposerDraft(nil, for: .thread("d0e515ef"))
        await flaky.sweep()
        #expect(try setup.files.read(held.path) != nil)
        #expect(try setup.files.read(newer.path) != nil)
    }

    @Test func aCandidateFileThatDoesNotDecodeDeletesNothing() async throws {
        let setup = Setup()
        await setup.outbox.load()
        let orphan = try setup.attachment(UUID())
        try setup.files.write(
            Data("not json".utf8), to: "\(InstanceFolders.outbox(setup.instance))/sweep-candidates.json",
            protection: .untilFirstUnlock)
        await setup.sweep()
        #expect(try setup.files.read(orphan.path) != nil)
    }

    @Test func anUploadFolderThatCannotBeListedStopsTheSweep() async throws {
        let setup = Setup()
        let store = FlakyStore(base: setup.files)
        store.failsUploadListing = true
        let ledger = UploadLedger(instance: setup.instance, files: store, clock: ManualClock())
        let sweeping = Setup(
            instance: setup.instance, files: setup.files,
            uploads: UploadLink(ledger: ledger, session: FakeUploadSession(identifier: "test")))
        await sweeping.outbox.load()
        let orphan = try setup.attachment(UUID())
        await sweeping.sweep()
        await sweeping.sweep()
        #expect(try setup.files.read(orphan.path) != nil)
    }
}
