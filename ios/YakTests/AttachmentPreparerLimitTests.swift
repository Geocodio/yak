import Foundation
import ImageIO
import Testing
import UIKit

@testable import Yak

private func temporaryFile(_ name: String, data: Data) throws -> URL {
    let folder = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
    try FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true)
    let url = folder.appendingPathComponent(name)
    try data.write(to: url)
    return url
}

struct AttachmentPreparerLimitTests {
    @Test func aHugeImageIsNeverDecodedAboveTheLimit() async throws {
        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        let size = CGSize(width: 8000, height: 6000)
        let png = UIGraphicsImageRenderer(size: size, format: format).pngData { context in
            UIColor.systemTeal.setFill()
            context.fill(CGRect(origin: .zero, size: size))
        }
        let url = try temporaryFile("big.png", data: png)
        let prepared = try await AttachmentPreparer.prepare(
            fileAt: url, fileName: "big.png", contentType: "image/png", maximumBytes: 10_000_000)
        let source = try #require(CGImageSourceCreateWithData(prepared.data as CFData, nil))
        let properties = try #require(CGImageSourceCopyPropertiesAtIndex(source, 0, nil) as? [CFString: Any])
        let width = try #require(properties[kCGImagePropertyPixelWidth] as? Int)
        let height = try #require(properties[kCGImagePropertyPixelHeight] as? Int)
        #expect(max(width, height) <= AttachmentPreparer.longestDecodedEdge)
        #expect(prepared.contentType == "image/jpeg")
    }

    @Test func theFileEntryPointRefusesALargeFileBeforeReadingIt() async throws {
        let url = try temporaryFile("huge.bin", data: Data())
        let handle = try FileHandle(forWritingTo: url)
        try handle.truncate(atOffset: 200 * 1024 * 1024)
        try handle.close()
        await #expect(throws: AttachmentProblem.tooLarge(maximumBytes: 10_000_000)) {
            _ = try await AttachmentPreparer.prepare(
                fileAt: url, fileName: "huge.bin", contentType: "application/octet-stream", maximumBytes: 10_000_000)
        }
    }

    @Test func aMissingFileIsUnreadable() async {
        let url = FileManager.default.temporaryDirectory.appendingPathComponent("missing-\(UUID().uuidString).pdf")
        await #expect(throws: AttachmentProblem.unreadable) {
            _ = try await AttachmentPreparer.prepare(
                fileAt: url, fileName: "a.pdf", contentType: "application/pdf", maximumBytes: 1000)
        }
    }
}
