import Foundation
import ImageIO
import Testing
import UIKit
import YakKit

@testable import Yak

private final class ScriptedFetcher: AttachmentFetching, @unchecked Sendable {
    private let lock = NSLock()
    private var requests: [URLRequest] = []
    private var chunksRead = 0
    let status: Int
    let announcedLength: Int64
    let body: Data

    init(status: Int = 200, announcedLength: Int64 = -1, body: Data) {
        self.status = status
        self.announcedLength = announcedLength
        self.body = body
    }

    var requestCount: Int { lock.withLock { requests.count } }
    var lastRequest: URLRequest? { lock.withLock { requests.last } }
    var chunkCount: Int { lock.withLock { chunksRead } }

    func fetch(_ request: URLRequest, limit: Int) async throws -> (
        HTTPURLResponse, AsyncThrowingStream<Data, any Error>
    ) {
        lock.withLock { requests.append(request) }
        let response = HTTPURLResponse(
            url: request.url!, statusCode: status, httpVersion: nil,
            headerFields: announcedLength >= 0 ? ["Content-Length": String(announcedLength)] : nil)!
        let body = body
        nonisolated(unsafe) var offset = 0
        let stream = AsyncThrowingStream<Data, any Error>(unfolding: { [self] in
            guard offset < body.count else { return nil }
            let end = min(offset + AttachmentImageLoader.chunkSize, body.count)
            defer { offset = end }
            lock.withLock { chunksRead += 1 }
            return body[offset..<end]
        })
        return (response, stream)
    }
}

private func jpeg() -> Data {
    let size = CGSize(width: 400, height: 300)
    let format = UIGraphicsImageRendererFormat()
    format.scale = 1
    return UIGraphicsImageRenderer(size: size, format: format).jpegData(withCompressionQuality: 0.8) { context in
        UIColor.systemTeal.setFill()
        context.fill(CGRect(origin: .zero, size: size))
    }
}

private func row(id: String = "41", title: String = "screenshot.png", safe: Bool = true) -> MessageAttachmentRow {
    MessageAttachmentRow(
        id: id, kind: .image, title: title, detail: "12 KB", reference: "Image #1",
        openURL: safe ? URL(string: "https://yak.example.com/app-media/\(id)?signature=abc&expires=1")! : nil)
}

private func makeLoader(_ fetcher: ScriptedFetcher, maximumBytes: Int = 1_048_576) -> (
    AttachmentImageLoader, Cache, URL
) {
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: SystemClock())
    let caches = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
    return (
        AttachmentImageLoader(cache: cache, fetcher: fetcher, maximumBytes: maximumBytes, caches: caches), cache,
        caches
    )
}

struct AttachmentImageLoaderTests {
    @Test func aCachedThumbnailNeedsNoRequest() async {
        let fetcher = ScriptedFetcher(body: Data())
        let (loader, cache, _) = makeLoader(fetcher)
        await cache.saveThumbnail(Data([1, 2, 3]), thread: "t", attachment: "41")
        #expect(await loader.thumbnail(thread: "t", row: row(), pixelSize: 100) == .image(Data([1, 2, 3])))
        #expect(fetcher.requestCount == 0)
    }

    @Test func aDownloadSendsNoTokenAndMakesAThumbnail() async {
        let fetcher = ScriptedFetcher(body: jpeg())
        let (loader, cache, _) = makeLoader(fetcher)
        let result = await loader.thumbnail(thread: "t", row: row(), pixelSize: 100)
        guard case .image = result else {
            Issue.record("no image")
            return
        }
        #expect(fetcher.lastRequest?.value(forHTTPHeaderField: "Authorization") == nil)
        #expect(await cache.thumbnail(thread: "t", attachment: "41") != nil)
    }

    @Test func aDownloadIsBoundedWhileTheBytesArrive() async {
        let fetcher = ScriptedFetcher(body: Data(count: 5 * 1_048_576))
        let (loader, cache, _) = makeLoader(fetcher)
        #expect(await loader.thumbnail(thread: "t", row: row(), pixelSize: 100) == .failed)
        #expect(fetcher.chunkCount <= 17)
        #expect(await cache.thumbnail(thread: "t", attachment: "41") == nil)
    }

    @Test func anAnnouncedLengthAboveTheLimitIsRefusedBeforeReading() async {
        let fetcher = ScriptedFetcher(announcedLength: 5 * 1_048_576, body: Data(count: 5 * 1_048_576))
        let (loader, _, _) = makeLoader(fetcher)
        #expect(await loader.thumbnail(thread: "t", row: row(), pixelSize: 100) == .failed)
        #expect(fetcher.chunkCount == 0)
    }

    @Test func anExpiredLinkSaysSo() async {
        let fetcher = ScriptedFetcher(status: 403, body: Data())
        let (loader, _, _) = makeLoader(fetcher)
        #expect(await loader.thumbnail(thread: "t", row: row(), pixelSize: 100) == .expired)
    }

    @Test func garbageImagesFail() async {
        let fetcher = ScriptedFetcher(body: Data("not an image".utf8))
        let (loader, _, _) = makeLoader(fetcher)
        #expect(await loader.thumbnail(thread: "t", row: row(), pixelSize: 100) == .failed)
    }

    @Test func notAppSafeRowsNeverLoad() async {
        let fetcher = ScriptedFetcher(body: jpeg())
        let (loader, _, _) = makeLoader(fetcher)
        #expect(await loader.thumbnail(thread: "t", row: row(safe: false), pixelSize: 100) == .notAvailable)
        #expect(await loader.file(row: row(safe: false)) == .notAvailable)
        #expect(fetcher.requestCount == 0)
    }

    @Test func quickLookFilesKeepTheirNameAndAnotherAttachmentIsLeftAlone() async throws {
        let fetcher = ScriptedFetcher(body: Data("pdf".utf8))
        let (loader, _, caches) = makeLoader(fetcher)
        guard case .ready(let first) = await loader.file(row: row(id: "42", title: "report.pdf")) else {
            Issue.record("not ready")
            return
        }
        #expect(first.path.hasSuffix("quicklook/42/report.pdf"))
        #expect(try Data(contentsOf: first) == Data("pdf".utf8))
        let long = String(repeating: "a", count: 300) + "/../x.pdf"
        guard case .ready(let second) = await loader.file(row: row(id: "43", title: long)) else {
            Issue.record("not ready")
            return
        }
        // A file Quick Look may still be showing stays; only the same attachment's folder is replaced.
        #expect(FileManager.default.fileExists(atPath: first.path))
        #expect(second.deletingLastPathComponent().path.hasSuffix("quicklook/43"))
        #expect(second.pathExtension == "pdf")
        #expect(second.lastPathComponent.count <= 120)
        #expect(second.path.hasPrefix(caches.path))
    }
}

@Suite struct AttachmentChunkDelegateTests {
    private final class Cancels: @unchecked Sendable {
        private let lock = NSLock()
        private var value = 0
        var count: Int { lock.withLock { value } }
        func hit() { lock.withLock { value += 1 } }
    }

    private func response(status: Int = 200, length: Int? = nil) -> HTTPURLResponse {
        HTTPURLResponse(
            url: URL(string: "https://yak.example.com/app-media/1")!, statusCode: status, httpVersion: nil,
            headerFields: length.map { ["Content-Length": String($0)] })!
    }

    @Test func aBodyOverTheLimitWithoutALengthIsCancelledAtTheLimit() async throws {
        let cancels = Cancels()
        let delegate = AttachmentChunkDelegate(limit: 100, cancel: { cancels.hit() })
        #expect(delegate.receive(response: response()))
        delegate.receive(data: Data(count: 60))
        delegate.receive(data: Data(count: 60))
        delegate.receive(data: Data(count: 60))
        #expect(cancels.count == 1)
        var buffered = 0
        do {
            for try await chunk in delegate.chunks { buffered += chunk.count }
            Issue.record("the stream ended as if the body were whole")
        } catch {
            #expect((error as? URLError)?.code == .dataLengthExceedsMaximum)
        }
        #expect(buffered <= 100)
    }

    @Test func anAnnouncedLengthOverTheLimitIsCancelledBeforeAnyBody() async throws {
        let cancels = Cancels()
        let delegate = AttachmentChunkDelegate(limit: 100, cancel: { cancels.hit() })
        #expect(!delegate.receive(response: response(length: 101)))
        #expect(cancels.count == 1)
        var buffered = 0
        for try await chunk in delegate.chunks { buffered += chunk.count }
        #expect(buffered == 0)
    }

    @Test func aFailureStatusIsCancelledBeforeAnyBody() {
        let cancels = Cancels()
        let delegate = AttachmentChunkDelegate(limit: 100, cancel: { cancels.hit() })
        #expect(!delegate.receive(response: response(status: 500)))
        #expect(cancels.count == 1)
    }

    @Test func aConsumerThatStopsEarlyCancelsTheTask() async throws {
        let cancels = Cancels()
        func readOneChunkThenStop() async throws {
            let delegate = AttachmentChunkDelegate(limit: 1000, cancel: { cancels.hit() })
            #expect(delegate.receive(response: response()))
            delegate.receive(data: Data(count: 10))
            for try await _ in delegate.chunks { break }
        }
        try await readOneChunkThenStop()
        #expect(cancels.count == 1)
    }
}
