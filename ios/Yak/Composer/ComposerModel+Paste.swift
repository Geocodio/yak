import Foundation
import UniformTypeIdentifiers

extension ComposerModel {
    /// The field is usable and the draft has room for another file.
    var canPaste: Bool { bar.unavailable == nil && attachments.count < maximumFiles }

    /// Adds the first image of each provider, one at a time and in order, through the same path as a photo from the
    /// library. A provider with no readable image adds nothing and says "This file could not be read."
    func paste(_ providers: [NSItemProvider]) async {
        for provider in providers {
            let type = provider.registeredContentTypes.first { $0.conforms(to: .image) }
            let data = await Self.imageData(of: provider, type: type)
            let name = provider.suggestedName.map { $0 + ".jpg" } ?? "Pasted image.jpg"
            await add(data ?? Data(), fileName: name, contentType: type?.preferredMIMEType ?? "image/jpeg")
        }
    }

    private static func imageData(of provider: NSItemProvider, type: UTType?) async -> Data? {
        guard let type else { return nil }
        return await withCheckedContinuation { continuation in
            _ = provider.loadDataRepresentation(forTypeIdentifier: type.identifier) { data, _ in
                continuation.resume(returning: data)
            }
        }
    }
}
