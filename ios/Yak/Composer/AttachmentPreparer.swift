import Foundation
import ImageIO
import UniformTypeIdentifiers

enum AttachmentProblem: Error, Equatable {
    case tooMany(maximum: Int)
    case tooLarge(maximumBytes: Int)
    case unreadable
    /// The device could not keep the file (disk full, data protection).
    case notStored
}

struct PreparedAttachment: Equatable, Sendable {
    let data: Data
    let fileName: String
    let contentType: String
    let isImage: Bool
}

/// Runs off the main actor. Images are re-encoded as JPEG, downscaled until they fit; other files must fit as they are.
enum AttachmentPreparer {
    /// Long edges tried in turn, in pixels, after the image at its own size (up to 4096) did not fit.
    static let longEdges = [3072, 2048, 1536, 1024]
    private static let jpegQuality = 0.8

    @concurrent
    static func prepare(
        _ data: Data, fileName: String, contentType: String, maximumBytes: Int
    ) async throws(AttachmentProblem) -> PreparedAttachment {
        guard !data.isEmpty else { throw .unreadable }
        guard contentType.hasPrefix("image/") else {
            guard data.count <= maximumBytes else { throw .tooLarge(maximumBytes: maximumBytes) }
            return PreparedAttachment(data: data, fileName: fileName, contentType: contentType, isImage: false)
        }
        guard let source = CGImageSourceCreateWithData(data as CFData, nil), CGImageSourceGetCount(source) > 0 else {
            throw .unreadable
        }
        return try encodeImage(source, fileName: fileName, maximumBytes: maximumBytes)
    }

    /// The first pass is at the image's own size, never above `longestDecodedEdge`; then each smaller long edge.
    private static func encodeImage(
        _ source: CGImageSource, fileName: String, maximumBytes: Int
    ) throws(AttachmentProblem) -> PreparedAttachment {
        let first = min(pixelLongEdge(of: source) ?? longestDecodedEdge, longestDecodedEdge)
        for edge in [first] + longEdges.filter({ $0 < first }) {
            guard let encoded = jpeg(from: source, longEdge: edge) else { throw .unreadable }
            if encoded.count <= maximumBytes {
                return PreparedAttachment(
                    data: encoded, fileName: jpegName(fileName), contentType: "image/jpeg", isImage: true)
            }
        }
        throw .tooLarge(maximumBytes: maximumBytes)
    }

    // MARK: Files and large images

    /// No image is decoded above this long edge, so a 48-megapixel photo never needs 200 MB in an extension.
    static let longestDecodedEdge = 4096

    /// Reads from a file. A non-image over the limit is refused from its size alone, before any of it is read.
    @concurrent
    static func prepare(
        fileAt url: URL, fileName: String, contentType: String, maximumBytes: Int
    ) async throws(AttachmentProblem) -> PreparedAttachment {
        let isScoped = url.startAccessingSecurityScopedResource()
        defer { if isScoped { url.stopAccessingSecurityScopedResource() } }
        guard contentType.hasPrefix("image/") else {
            guard let size = try? url.resourceValues(forKeys: [.fileSizeKey]).fileSize else { throw .unreadable }
            guard size > 0 else { throw .unreadable }
            guard size <= maximumBytes else { throw .tooLarge(maximumBytes: maximumBytes) }
            guard let data = try? Data(contentsOf: url, options: .mappedIfSafe) else { throw .unreadable }
            return PreparedAttachment(data: data, fileName: fileName, contentType: contentType, isImage: false)
        }
        guard let source = CGImageSourceCreateWithURL(url as CFURL, nil), CGImageSourceGetCount(source) > 0 else {
            throw .unreadable
        }
        return try encodeImage(source, fileName: fileName, maximumBytes: maximumBytes)
    }

    /// Reads a picked file, refusing one over the limit before any of it is read.
    @concurrent
    static func read(_ url: URL, maximumBytes: Int) async throws(AttachmentProblem) -> Data {
        let isScoped = url.startAccessingSecurityScopedResource()
        defer { if isScoped { url.stopAccessingSecurityScopedResource() } }
        if let size = try? url.resourceValues(forKeys: [.fileSizeKey]).fileSize, size > maximumBytes {
            throw .tooLarge(maximumBytes: maximumBytes)
        }
        guard let data = try? Data(contentsOf: url, options: .mappedIfSafe) else { throw .unreadable }
        return data
    }

    /// A small JPEG for the attachment strip, or nil when the bytes are not an image.
    @concurrent
    static func thumbnail(of data: Data, longEdge: Int = 240) async -> Data? {
        guard let source = CGImageSourceCreateWithData(data as CFData, nil) else { return nil }
        return jpeg(from: source, longEdge: longEdge)
    }

    private static func pixelLongEdge(of source: CGImageSource) -> Int? {
        guard let properties = CGImageSourceCopyPropertiesAtIndex(source, 0, nil) as? [CFString: Any],
            let width = properties[kCGImagePropertyPixelWidth] as? Int,
            let height = properties[kCGImagePropertyPixelHeight] as? Int
        else { return nil }
        return max(width, height)
    }

    /// The image is decoded upright at that long edge (the EXIF orientation is applied to the pixels), so a large
    /// photo is never held at full size. A nil edge decodes at the image's own size.
    private static func jpeg(from source: CGImageSource, longEdge: Int?) -> Data? {
        let edge = longEdge ?? pixelLongEdge(of: source) ?? longestDecodedEdge
        guard
            let image = CGImageSourceCreateThumbnailAtIndex(
                source, 0,
                [
                    kCGImageSourceCreateThumbnailFromImageAlways: true,
                    kCGImageSourceCreateThumbnailWithTransform: true,
                    kCGImageSourceThumbnailMaxPixelSize: edge,
                ] as CFDictionary)
        else { return nil }
        let output = NSMutableData()
        guard let destination = CGImageDestinationCreateWithData(output, UTType.jpeg.identifier as CFString, 1, nil)
        else { return nil }
        CGImageDestinationAddImage(
            destination, image, [kCGImageDestinationLossyCompressionQuality: jpegQuality] as CFDictionary)
        return CGImageDestinationFinalize(destination) ? output as Data : nil
    }

    private static func jpegName(_ fileName: String) -> String {
        let stem = (fileName as NSString).deletingPathExtension
        return (stem.isEmpty ? "Photo" : stem) + ".jpg"
    }
}
