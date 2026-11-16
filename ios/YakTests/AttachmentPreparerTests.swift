import Foundation
import ImageIO
import Testing
import UIKit

@testable import Yak

private func noiseImageData(width: Int, height: Int) -> Data {
    var bytes = [UInt8](repeating: 0, count: width * height * 4)
    _ = SecRandomCopyBytes(kSecRandomDefault, bytes.count, &bytes)
    let image: CGImage? = bytes.withUnsafeMutableBytes { buffer in
        CGContext(
            data: buffer.baseAddress, width: width, height: height, bitsPerComponent: 8, bytesPerRow: width * 4,
            space: CGColorSpaceCreateDeviceRGB(), bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue
        )?.makeImage()
    }
    return image.flatMap { UIImage(cgImage: $0).pngData() } ?? Data()
}

private func longEdge(of data: Data) -> Int? {
    guard let source = CGImageSourceCreateWithData(data as CFData, nil),
        let properties = CGImageSourceCopyPropertiesAtIndex(source, 0, nil) as? [CFString: Any],
        let width = properties[kCGImagePropertyPixelWidth] as? Int,
        let height = properties[kCGImagePropertyPixelHeight] as? Int
    else { return nil }
    return max(width, height)
}

private func pointScale() -> UIGraphicsImageRendererFormat {
    let format = UIGraphicsImageRendererFormat()
    format.scale = 1
    return format
}

private func drawnImageData() -> Data {
    let size = CGSize(width: 120, height: 80)
    return UIGraphicsImageRenderer(size: size, format: pointScale()).pngData { context in
        UIColor.systemOrange.setFill()
        context.fill(CGRect(origin: .zero, size: size))
    }
}

struct AttachmentPreparerTests {
    @Test func aSmallImageIsReencodedAsJPEG() async throws {
        let prepared = try await AttachmentPreparer.prepare(
            drawnImageData(), fileName: "IMG_0001.PNG", contentType: "image/png", maximumBytes: 1_000_000)
        #expect(prepared.isImage && prepared.contentType == "image/jpeg" && prepared.fileName == "IMG_0001.jpg")
        #expect(prepared.data.starts(with: [0xFF, 0xD8]))
        #expect(longEdge(of: prepared.data) == 120)
    }

    @Test func aLargeImageIsDownscaledUntilItFits() async throws {
        let prepared = try await AttachmentPreparer.prepare(
            noiseImageData(width: 6000, height: 4000), fileName: "big.png", contentType: "image/png",
            maximumBytes: 1_500_000)
        #expect(prepared.data.count <= 1_500_000)
        #expect(try #require(longEdge(of: prepared.data)) <= 3072)
    }

    @Test func anImageThatCannotFitIsRefused() async {
        await #expect(throws: AttachmentProblem.tooLarge(maximumBytes: 1000)) {
            try await AttachmentPreparer.prepare(
                noiseImageData(width: 400, height: 400), fileName: "n.png", contentType: "image/png",
                maximumBytes: 1000)
        }
    }

    @Test func aFileIsKeptAsItIsOrRefused() async throws {
        let bytes = Data(repeating: 7, count: 2000)
        let prepared = try await AttachmentPreparer.prepare(
            bytes, fileName: "notes.txt", contentType: "text/plain", maximumBytes: 2000)
        #expect(
            prepared
                == PreparedAttachment(data: bytes, fileName: "notes.txt", contentType: "text/plain", isImage: false))
    }

    @Test func aLargeFileIsRefused() async {
        await #expect(throws: AttachmentProblem.tooLarge(maximumBytes: 1000)) {
            try await AttachmentPreparer.prepare(
                Data(repeating: 1, count: 1001), fileName: "a.zip", contentType: "application/zip", maximumBytes: 1000)
        }
    }

    @Test func garbageIsUnreadable() async {
        await #expect(throws: AttachmentProblem.unreadable) {
            try await AttachmentPreparer.prepare(
                Data("not an image".utf8), fileName: "x.png", contentType: "image/png", maximumBytes: 1_000_000)
        }
    }

    @Test func aThumbnailIsSmall() async throws {
        let thumbnail = try #require(await AttachmentPreparer.thumbnail(of: noiseImageData(width: 1200, height: 800)))
        #expect(try #require(longEdge(of: thumbnail)) <= 240)
    }
}
