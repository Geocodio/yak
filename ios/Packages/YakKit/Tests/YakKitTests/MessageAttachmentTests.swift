import Foundation
import Testing

@testable import YakKit

struct MessageAttachmentTests {
    @Test func messagesDecodeTheirFiles() throws {
        let show: ThreadShow = try decoded(ThreadShow.self, "scenario-thread-attachments")
        let first = show.messages[0]
        #expect(first.attachmentList.map(\.id.value) == ["41", "42"])
        #expect(first.attachmentList[0].isImage && first.attachmentList[1].downloadUrl == nil)
        #expect(show.messages[1].attachmentList.count == 1)
        let plain: ThreadShow = try decoded(ThreadShow.self, "scenario-thread-show")
        #expect(plain.messages[0].attachments == nil && plain.messages[0].attachmentList.isEmpty)
        let original = plain.messages[0]
        #expect(first.id == original.id && first.uuid == original.uuid && first.sendKey == original.sendKey)
        #expect(first.text == original.text && first.authorName == original.authorName)
        #expect(first.authorInitials == original.authorInitials && first.model == original.model)
        #expect(first.state == original.state && first.mode == original.mode && first.intent == original.intent)
        #expect(first.sentAt == original.sentAt && first.readAfterSequence == original.readAfterSequence)
        #expect(first.bodyHtml == original.bodyHtml)
    }

    @Test func aCachedShowWithFilesRoundTrips() async throws {
        let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: ManualClock())
        let show: ThreadShow = try decoded(ThreadShow.self, "scenario-thread-attachments")
        await cache.saveThread(
            "d0e515ef", CachedThread(show: show, events: [], lastSequence: 0, firstLoadedAfter: 0))
        let loaded = try #require(await cache.thread("d0e515ef"))
        #expect(loaded.show.messages[0].attachments == show.messages[0].attachments)
        #expect(loaded.show.messages[0].attachmentList[0].url.contains("signature=abc"))
    }
}
