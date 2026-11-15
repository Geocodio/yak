import Foundation
import Testing

@testable import YakKit

private func draft(_ path: String = "src/Importer.php", text: String = "edited") -> FileEditDraft {
    FileEditDraft(
        scope: .repo, path: path, baseSHA256: "0a1b2c3d", text: text,
        savedAt: Date(timeIntervalSince1970: 1_800_000_000))
}

@Suite struct FileEditDraftTests {
    @Test func aDraftRoundTripsWithCompleteProtection() async {
        let id = UUID()
        let files = InMemoryFileStore()
        let cache = Cache(instance: id, files: files, clock: ManualClock())
        await cache.saveFileEditDraft(draft(), thread: "d0e515ef")
        #expect(await cache.fileEditDraft(thread: "d0e515ef", scope: .repo, path: "src/Importer.php") == draft())
        #expect(await cache.fileEditDraft(thread: "d0e515ef", scope: .sandbox, path: "src/Importer.php") == nil)
        #expect(files.protection(of: "\(InstanceFolders.cache(id))/file-drafts/d0e515ef.json") == .complete)
        await cache.removeFileEditDraft(thread: "d0e515ef", scope: .repo, path: "src/Importer.php")
        #expect(await cache.fileEditDraft(thread: "d0e515ef", scope: .repo, path: "src/Importer.php") == nil)
    }

    @Test func aTooLargeDraftIsNotStored() async {
        let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: ManualClock())
        await cache.saveFileEditDraft(
            draft(text: String(repeating: "a", count: FileEditDraft.maximumBytes + 1)), thread: "d0e515ef")
        #expect(await cache.fileEditDraft(thread: "d0e515ef", scope: .repo, path: "src/Importer.php") == nil)
        await cache.saveFileEditDraft(
            draft(text: String(repeating: "a", count: FileEditDraft.maximumBytes)), thread: "d0e515ef")
        #expect(await cache.fileEditDraft(thread: "d0e515ef", scope: .repo, path: "src/Importer.php") != nil)
    }

    @Test func draftsSurviveThreadEviction() async throws {
        let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: ManualClock())
        let show = try decoded(ThreadShow.self, "scenario-thread-show")
        await cache.saveFileEditDraft(draft(), thread: "thread-0")
        for index in 0...Cache.maximumThreads {
            await cache.saveThread(
                "thread-\(index)", CachedThread(show: show, events: [], lastSequence: 0, firstLoadedAfter: 0))
        }
        #expect(await cache.thread("thread-0") == nil)
        #expect(await cache.fileEditDraft(thread: "thread-0", scope: .repo, path: "src/Importer.php") != nil)
    }

    @Test func eraseAllRemovesDrafts() async {
        let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: ManualClock())
        await cache.saveFileEditDraft(draft(), thread: "d0e515ef")
        await cache.eraseAll()
        #expect(await cache.fileEditDraft(thread: "d0e515ef", scope: .repo, path: "src/Importer.php") == nil)
    }
}
