import Foundation
import ImageIO
import UniformTypeIdentifiers

enum AttachmentProblem: Error, Equatable {
    case tooMany(maximum: Int)
    case tooLarge(maximumBytes: Int)
    case unreadable
}

struct PreparedAttachment: Equatable, Sendable {
    let data: Data
    let fileName: String
    let contentType: String
    let isImage: Bool
}

/// Runs off the main actor. Images are re-encoded as JPEG, downscaled until they fit; other files must fit as they are.
enum AttachmentPreparer {
    /// Long edges tried in turn, in pixels, after the image at its own size did not fit.
    static let longEdges = [4096, 3072, 2048, 1536, 1024]
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
        let longestEdge = pixelLongEdge(of: source)
        var candidates = [Int.max]
        candidates += longEdges.filter { longestEdge == nil || $0 < longestEdge! }
        for edge in candidates {
            guard let encoded = jpeg(from: source, longEdge: edge == .max ? nil : edge) else { throw .unreadable }
            if encoded.count <= maximumBytes {
                return PreparedAttachment(
                    data: encoded, fileName: jpegName(fileName), contentType: "image/jpeg", isImage: true)
            }
        }
        throw .tooLarge(maximumBytes: maximumBytes)
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

    /// With a long edge the image is decoded at that size, so a large photo is never held at full size.
    private static func jpeg(from source: CGImageSource, longEdge: Int?) -> Data? {
        let image: CGImage?
        if let longEdge {
            image = CGImageSourceCreateThumbnailAtIndex(
                source, 0,
                [
                    kCGImageSourceCreateThumbnailFromImageAlways: true,
                    kCGImageSourceCreateThumbnailWithTransform: true,
                    kCGImageSourceThumbnailMaxPixelSize: longEdge,
                ] as CFDictionary)
        } else {
            image = CGImageSourceCreateImageAtIndex(source, 0, nil)
        }
        guard let image else { return nil }
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
