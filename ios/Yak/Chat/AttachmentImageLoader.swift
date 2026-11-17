import Foundation
import ImageIO
import UniformTypeIdentifiers
import YakKit

#if canImport(os)
    import os
#endif

/// A streamed download: the response, then the body in chunks. Production wraps `URLSession.bytes(for:)` on an
/// ephemeral session with no cookies and no cache, read in 64 KB chunks; tests script it and count chunks read.
protocol AttachmentFetching: Sendable {
    func fetch(_ request: URLRequest) async throws -> (HTTPURLResponse, AsyncThrowingStream<Data, any Error>)
}

struct URLSessionAttachmentFetcher: AttachmentFetching {
    func fetch(_ request: URLRequest) async throws -> (HTTPURLResponse, AsyncThrowingStream<Data, any Error>) {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.httpCookieStorage = nil
        configuration.httpShouldSetCookies = false
        configuration.urlCache = nil
        let (bytes, response) = try await URLSession(configuration: configuration).bytes(for: request)
        guard let http = response as? HTTPURLResponse else { throw URLError(.badServerResponse) }
        let stream = AsyncThrowingStream<Data, any Error> { continuation in
            let reading = Task {
                do {
                    var chunk = Data()
                    for try await byte in bytes {
                        chunk.append(byte)
                        if chunk.count >= AttachmentImageLoader.chunkSize {
                            continuation.yield(chunk)
                            chunk = Data()
                        }
                    }
                    if !chunk.isEmpty { continuation.yield(chunk) }
                    continuation.finish()
                } catch {
                    continuation.finish(throwing: error)
                }
            }
            continuation.onTermination = { _ in reading.cancel() }
        }
        return (http, stream)
    }
}

enum AttachmentLoad: Equatable {
    case image(Data)
    case failed, expired, offline, notAvailable
}
enum AttachmentFile: Equatable {
    case ready(URL)
    case failed, expired, offline, notAvailable
}

/// Downloads a signed link with no cookies and no token, bounded by size while the bytes arrive, and makes
/// thumbnails off the main actor.
actor AttachmentImageLoader {
    static let hardLimit = 26_214_400
    static let chunkSize = 65_536
    static let nameLimit = 120

    private let cache: Cache
    private let fetcher: any AttachmentFetching
    private let limit: Int
    private let caches: URL

    init(cache: Cache, fetcher: any AttachmentFetching, maximumBytes: Int, caches: URL) {
        self.cache = cache
        self.fetcher = fetcher
        self.limit = max(0, min(maximumBytes, Self.hardLimit))
        self.caches = caches
    }

    private enum Download {
        case finished(Data)
        case failed, expired, offline
    }

    private func request(_ link: URL) -> URLRequest {
        var request = URLRequest(url: link, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 15)
        request.httpShouldHandleCookies = false
        return request
    }

    /// Streams the body through `consume`, which gets every chunk; stops as soon as the limit is passed.
    private func stream(_ link: URL, consume: (Data) throws -> Void) async -> Download? {
        do {
            let (response, chunks) = try await fetcher.fetch(request(link))
            if response.statusCode == 403 { return .expired }
            guard (200..<300).contains(response.statusCode) else { return .failed }
            if response.expectedContentLength > Int64(limit) { return .failed }
            var total = 0
            for try await chunk in chunks {
                total += chunk.count
                if total > limit { return .failed }
                try consume(chunk)
            }
            return nil
        } catch {
            if let urlError = error as? URLError,
                [.notConnectedToInternet, .networkConnectionLost, .dataNotAllowed, .cannotConnectToHost]
                    .contains(urlError.code)
            {
                return .offline
            }
            return .failed
        }
    }

    private func download(_ link: URL) async -> Download {
        var body = Data()
        if let problem = await stream(link, consume: { body.append($0) }) { return problem }
        return .finished(body)
    }

    func thumbnail(thread: String, row: MessageAttachmentRow, pixelSize: Int) async -> AttachmentLoad {
        if let cached = await cache.thumbnail(thread: thread, attachment: row.id) { return .image(cached) }
        guard let link = row.openURL else { return .notAvailable }
        switch await download(link) {
        case .expired: return .expired
        case .offline: return .offline
        case .failed: return .failed
        case .finished(let data):
            guard let thumbnail = Self.makeThumbnail(data, pixelSize: pixelSize) else { return .failed }
            await cache.saveThumbnail(thumbnail, thread: thread, attachment: row.id)
            return .image(thumbnail)
        }
    }

    /// A temporary file for Quick Look, named like the attachment; the previous one is removed first.
    func file(row: MessageAttachmentRow) async -> AttachmentFile {
        guard let link = row.openURL else { return .notAvailable }
        let manager = FileManager.default
        let root = caches.appendingPathComponent("quicklook")
        let folder = root.appendingPathComponent(Self.folderName(row.id))
        try? manager.removeItem(at: folder)
        let location = folder.appendingPathComponent(Self.fileName(row.title))
        do { try manager.createDirectory(at: folder, withIntermediateDirectories: true) } catch { return .failed }
        guard manager.createFile(atPath: location.path, contents: nil),
            let handle = try? FileHandle(forWritingTo: location)
        else { return .failed }
        let problem = await stream(link) { try handle.write(contentsOf: $0) }
        try? handle.close()
        switch problem {
        case nil: return .ready(location)
        case .expired?:
            try? manager.removeItem(at: folder)
            return .expired
        case .offline?:
            try? manager.removeItem(at: folder)
            return .offline
        default:
            try? manager.removeItem(at: folder)
            return .failed
        }
    }

    static func folderName(_ id: String) -> String { ComposerAttachments.safeFileName(id) }

    /// The attachment's name through `safeFileName`, cut to 120 characters keeping the extension.
    static func fileName(_ name: String) -> String {
        let safe = ComposerAttachments.safeFileName(name)
        guard safe.count > nameLimit else { return safe }
        let fileExtension = (safe as NSString).pathExtension
        guard !fileExtension.isEmpty, fileExtension.count < 20 else { return String(safe.prefix(nameLimit)) }
        return String(safe.prefix(nameLimit - fileExtension.count - 1)) + "." + fileExtension
    }

    private static func makeThumbnail(_ data: Data, pixelSize: Int) -> Data? {
        guard
            let source = CGImageSourceCreateWithData(
                data as CFData, [kCGImageSourceShouldCache: false] as CFDictionary),
            let image = CGImageSourceCreateThumbnailAtIndex(
                source, 0,
                [
                    kCGImageSourceCreateThumbnailFromImageAlways: true,
                    kCGImageSourceCreateThumbnailWithTransform: true,
                    kCGImageSourceThumbnailMaxPixelSize: pixelSize,
                ] as CFDictionary)
        else { return nil }
        let output = NSMutableData()
        guard let destination = CGImageDestinationCreateWithData(output, UTType.jpeg.identifier as CFString, 1, nil)
        else { return nil }
        CGImageDestinationAddImage(
            destination, image, [kCGImageDestinationLossyCompressionQuality: 0.8] as CFDictionary)
        return CGImageDestinationFinalize(destination) ? output as Data : nil
    }
}
