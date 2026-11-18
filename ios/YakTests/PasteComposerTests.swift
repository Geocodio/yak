import Foundation
import Testing
import UIKit
import YakKit

@testable import Yak

private final class PasteBackend: ComposerBackend, @unchecked Sendable {
    let maximumFiles: Int
    private let lock = NSLock()
    private(set) var stored: [String] = []

    init(maximumFiles: Int = 8) { self.maximumFiles = maximumFiles }

    func draft(_ key: ComposerDraftKey) async -> ComposerDraft? { nil }
    func saveDraft(_ draft: ComposerDraft?, for key: ComposerDraftKey) async {}
    func send(_ draft: OutboxDraft) async throws(SendProblem) -> OutboxItem { throw .notSaved }
    func stop() async -> ThreadActionOutcome { .done }
    func storeAttachment(_ prepared: PreparedAttachment, reference: String, sendKey: UUID) async throws(FileStoreError)
        -> OutboxAttachment
    {
        lock.withLock { stored.append(reference) }
        return OutboxAttachment(
            reference: reference, fileName: prepared.fileName, contentType: prepared.contentType,
            path: "attachments/\(reference).jpg")
    }
    func attachmentData(_ attachment: OutboxAttachment) async -> Data? { nil }
    func attachmentExists(_ attachment: OutboxAttachment) async -> Bool { true }
    func discardAttachment(_ attachment: OutboxAttachment) async {}
    func limits() async -> Me.AttachmentLimits? {
        try? JSONDecoder().decode(
            Me.AttachmentLimits.self, from: Data(#"{"maxFiles":\#(maximumFiles),"maxFileBytes":10485760}"#.utf8))
    }
    func takeOutboxItem(_ id: UUID) async -> OutboxTakeOutcome { .unavailable }
}

private func imageProvider() -> NSItemProvider {
    let size = CGSize(width: 40, height: 30)
    let format = UIGraphicsImageRendererFormat()
    format.scale = 1
    let data = UIGraphicsImageRenderer(size: size, format: format).pngData { context in
        UIColor.systemTeal.setFill()
        context.fill(CGRect(origin: .zero, size: size))
    }
    let provider = NSItemProvider()
    provider.registerDataRepresentation(forTypeIdentifier: "public.png", visibility: .all) { completion in
        completion(data, nil)
        return nil
    }
    return provider
}

@MainActor
struct PasteComposerTests {
    @Test func pastedImagesGoThroughTheSamePath() async {
        let backend = PasteBackend()
        let composer = ComposerModel(key: .thread("d0e515ef"), backend: backend, clock: SystemClock())
        await composer.paste([imageProvider(), imageProvider()])
        #expect(composer.attachments.count == 2)
        #expect(composer.attachments.map(\.reference) == ["Image #1", "Image #2"])
        #expect(composer.problem == nil)
    }

    @Test func aFullDraftSaysSoAndStoresNothing() async {
        let backend = PasteBackend(maximumFiles: 1)
        let composer = ComposerModel(key: .thread("d0e515ef"), backend: backend, clock: SystemClock())
        await composer.paste([imageProvider(), imageProvider()])
        #expect(composer.attachments.count == 1)
        #expect(backend.stored.count == 1)
        #expect(composer.problem != nil)
        #expect(!composer.canPaste)
    }

    @Test func aProviderWithoutAnImageIsUnreadable() async {
        let backend = PasteBackend()
        let composer = ComposerModel(key: .thread("d0e515ef"), backend: backend, clock: SystemClock())
        await composer.paste([NSItemProvider(object: "text" as NSString)])
        #expect(composer.attachments.isEmpty)
        #expect(composer.problem != nil)
    }
}
