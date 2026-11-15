import Foundation
import Testing

@testable import YakKit

private func attachment(
    _ id: String, name: String = "a.png", size: Int = 10, isImage: Bool = true, reference: String? = nil,
    url: String = "https://yak.example.com/app-media/task-attachments/1?expires=1&signature=a"
) -> MessageAttachment {
    MessageAttachment(
        id: LenientID(id), name: name, reference: reference, url: url, mimeType: "image/png", size: size,
        isImage: isImage)
}

struct MessageAttachmentTests {
    private let instance = URL(string: "https://yak.example.com")!

    @Test func onlyTheInstancesOwnSignedLinksOpen() throws {
        #expect(
            MessageAttachmentRows.isAppSafe(
                URL(string: "https://yak.example.com/app-media/task-attachments/41?expires=1&signature=a")!,
                instance: instance, canonical: nil))
        for link in [
            "http://yak.example.com/app-media/task-attachments/41?signature=a",
            "https://yak.example.com.evil.test/app-media/x?signature=a",
            "https://yak.example.com:8443/app-media/x?signature=a",
            "https://yak.example.com/task-attachments/41?signature=a",
            "https://yak.example.com/app-media/task-attachments/41",
            "https://cdn.example.net/app-media/x?signature=a",
        ] {
            #expect(!MessageAttachmentRows.isAppSafe(URL(string: link)!, instance: instance, canonical: nil))
        }
        #expect(
            MessageAttachmentRows.isAppSafe(
                URL(string: "https://old.example.com/app-media/x?signature=a")!, instance: instance,
                canonical: URL(string: "https://old.example.com")))
        #expect(
            MessageAttachmentRows.isAppSafe(
                URL(string: "http://127.0.0.1:8080/app-media/x?signature=a")!,
                instance: URL(string: "http://127.0.0.1:8080")!, canonical: nil))
        #expect(
            MessageAttachmentRows.isAppSafe(
                URL(string: "https://YAK.example.com:443/app-media/x?signature=a")!, instance: instance,
                canonical: nil))
    }

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

    @Test func rowsAreBoundedAndOrdered() throws {
        let files = (0..<12).map { attachment("f\($0)", name: "f\($0).pdf", isImage: false) }
        let images = (0..<13).map { attachment("i\($0)") }
        let (rows, hidden) = MessageAttachmentRows.make(files + images, instance: instance, canonical: nil)
        #expect(rows.count == 20 && hidden == 5)
        #expect(rows.prefix(13).allSatisfy { $0.kind == .image } && rows[13].kind == .file)
        #expect(rows[0].id == "i0" && rows[13].id == "f0")

        let long = MessageAttachmentRows.make(
            [attachment("1", name: String(repeating: "x", count: 1000) + ".png")], instance: instance, canonical: nil)
        #expect(long.rows[0].title.count == 120 && long.rows[0].title.hasSuffix(".png"))
        #expect(
            MessageAttachmentRows.make([attachment("1", name: "")], instance: instance, canonical: nil).rows[0].title
                == "File")

        let negative = MessageAttachmentRows.make([attachment("1", size: -5)], instance: instance, canonical: nil)
        #expect(negative.rows[0].detail == ByteCountFormatter.string(fromByteCount: 0, countStyle: .file))
        let described = MessageAttachmentRows.make(
            [attachment("2", size: 1_200_000, isImage: false, reference: "File #2")], instance: instance, canonical: nil
        )
        #expect(described.rows[0].detail.hasPrefix("File #2, ") && described.rows[0].reference == "File #2")
    }

    @Test func unsafeLinksHaveNoOpenURL() throws {
        let show: ThreadShow = try decoded(ThreadShow.self, "scenario-thread-attachments")
        let safe = MessageAttachmentRows.make(show.messages[0].attachmentList, instance: instance, canonical: nil)
        let remote = MessageAttachmentRows.make(show.messages[1].attachmentList, instance: instance, canonical: nil)
        #expect(safe.rows.allSatisfy { $0.openURL != nil })
        #expect(remote.rows[0].openURL == nil)
    }

    @Test func thumbnailsLeaveWithTheThread() async throws {
        let files = InMemoryFileStore()
        let cache = Cache(instance: UUID(), files: files, clock: ManualClock())
        let bytes = Data("jpg".utf8)
        await cache.saveThumbnail(bytes, thread: "d0e515ef", attachment: "41")
        #expect(await cache.thumbnail(thread: "d0e515ef", attachment: "41") == bytes)
        files.isLocked = true
        #expect(await cache.thumbnail(thread: "d0e515ef", attachment: "41") == nil)
        files.isLocked = false
        await cache.removeThread("d0e515ef")
        #expect(await cache.thumbnail(thread: "d0e515ef", attachment: "41") == nil)

        for number in 0..<Cache.thumbnailsPerThread {
            await cache.saveThumbnail(bytes, thread: "t", attachment: "a\(number)")
        }
        await cache.saveThumbnail(bytes, thread: "t", attachment: "over")
        #expect(await cache.thumbnail(thread: "t", attachment: "over") == nil)
        await cache.saveThumbnail(Data("new".utf8), thread: "t", attachment: "a0")
        #expect(await cache.thumbnail(thread: "t", attachment: "a0") == Data("new".utf8))
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
