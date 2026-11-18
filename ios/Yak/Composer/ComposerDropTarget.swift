import Observation
import SwiftUI
import UniformTypeIdentifiers
import YakKit
import os

/// What a drop did, kept for the line above the field and the error haptic. It decides nothing about the composer: the
/// composer's own limits and `add` come in as closures.
@MainActor @Observable
final class ComposerDropModel {
    /// Whether the composer can take attachments now (not read-only, closed, on a computer or blocked).
    private let acceptsDropsNow: () -> Bool
    /// Attachments in the draft, counting files still being prepared.
    private let attached: () -> Int
    /// The server's limits; nil refuses the drop.
    private let limits: () -> (maxFiles: Int, maxFileBytes: Int)?
    private let add: (Data, String, String) async -> Void

    private(set) var line: String?
    /// Counts refusals, so the view plays the error haptic once for each.
    private(set) var refusalCount = 0

    init(
        acceptsDrops: @escaping () -> Bool, attached: @escaping () -> Int,
        limits: @escaping () -> (maxFiles: Int, maxFileBytes: Int)?,
        add: @escaping (Data, String, String) async -> Void
    ) {
        acceptsDropsNow = acceptsDrops
        self.attached = attached
        self.limits = limits
        self.add = add
    }

    var acceptsDrops: Bool { acceptsDropsNow() }

    func clearLine() { line = nil }

    /// `items[i]` was stat'ed before the call. `load(i, limit)` reads item i with a read that stops at `limit + 1`
    /// bytes and returns its data, file name and content type. Refused items are never read.
    func receive(
        _ items: [DroppedItem], names: [String],
        load: @escaping @Sendable (Int, Int) async throws -> (Data, String, String)
    ) async {
        guard acceptsDrops else { return }
        guard let limits = limits() else {
            refuse(String(localized: "drop.unavailable"))
            return
        }
        let looked = Array(items.prefix(DropIntake.maximumItemsLooked))
        let decisions = DropIntake.decide(
            looked, attached: attached(), maxFiles: limits.maxFiles, maxFileBytes: limits.maxFileBytes)
        var tooLargeNames: [String] = []
        var grewCount = 0
        var unreadable = 0
        for (index, decision) in decisions.enumerated() {
            if decision == .tooLarge { tooLargeNames.append(name(at: index, in: names)) }
            guard decision == .accept else { continue }
            let limit = DropIntake.readLimit(isImage: looked[index].isImage, maxFileBytes: limits.maxFileBytes)
            do {
                let (data, fileName, contentType) = try await load(index, limit)
                if data.count > limit {
                    grewCount += 1
                    tooLargeNames.append(name(at: index, in: names))
                } else {
                    await add(data, ComposerAttachments.safeFileName(fileName), contentType)
                }
            } catch {
                unreadable += 1
            }
        }
        let summary = DropIntake.summary(decisions, dropped: items.count)
        unreadable += summary.unreadable
        var lines: [String] = []
        if summary.tooMany > 0 {
            lines.append(String(localized: "drop.tooMany \(summary.tooMany) \(limits.maxFiles)"))
        }
        if tooLargeNames.count == 1, let only = tooLargeNames.first {
            lines.append(String(localized: "drop.tooLarge.one \(only) \(Self.size(limits.maxFileBytes))"))
        } else if tooLargeNames.count > 1 {
            lines.append(
                String(localized: "drop.tooLarge.many \(tooLargeNames.count) \(Self.size(limits.maxFileBytes))"))
        }
        if unreadable > 0 { lines.append(String(localized: "drop.unreadable \(unreadable)")) }
        if !lines.isEmpty { refuse(lines.joined(separator: " ")) }
    }

    private func refuse(_ text: String) {
        line = text
        refusalCount += 1
    }

    private func name(at index: Int, in names: [String]) -> String {
        names.indices.contains(index) ? DropIntake.lineName(names[index]) : ""
    }

    private static func size(_ bytes: Int) -> String {
        ByteCountFormatter.string(fromByteCount: Int64(max(0, bytes)), countStyle: .file)
    }
}

extension View {
    /// The composer accepts files and images dragged from other apps.
    func composerDropTarget(_ composer: ComposerModel) -> some View { modifier(ComposerDropTarget(composer: composer)) }
}

private struct ComposerDropTarget: ViewModifier {
    @State private var model: ComposerDropModel
    @State private var isTargeted = false

    init(composer: ComposerModel) {
        _model = State(
            initialValue: ComposerDropModel(
                acceptsDrops: { composer.bar.unavailable == nil },
                attached: { composer.attachments.count + composer.preparingCount },
                limits: { (composer.maximumFiles, composer.maximumFileBytes) },
                add: { data, name, type in await composer.add(data, fileName: name, contentType: type) }))
    }

    func body(content: Content) -> some View {
        content
            .onDrop(of: [UTType.item], isTargeted: $isTargeted) { providers in
                guard model.acceptsDrops else { return false }
                Task { await stageAndReceive(providers, into: model) }
                return true
            }
            .overlay {
                if isTargeted && model.acceptsDrops {
                    RoundedRectangle(cornerRadius: 22).strokeBorder(Color.primary.opacity(0.6), lineWidth: 2)
                        .overlay { Text("drop.placeholder").font(.body.weight(.medium)).foregroundStyle(.primary) }
                        .background(Color(.systemBackground).opacity(0.85), in: .rect(cornerRadius: 22))
                        .allowsHitTesting(false)
                }
            }
            .overlay(alignment: .top) {
                if let line = model.line {
                    Text(line).font(.footnote).foregroundStyle(.secondary).padding(.bottom, 4)
                        .offset(y: -28).accessibilityIdentifier("composer.dropLine")
                        .task(id: line) {
                            do { try await Task.sleep(for: .seconds(6)) } catch { return }
                            model.clearLine()
                        }
                }
            }
            .sensoryFeedback(.error, trigger: model.refusalCount)
    }

    /// Copies each provider's file to a temporary one (skipping the copy of a file too large to take), stats it, and
    /// hands the sizes to the model. The bytes are read later, one file at a time, and the copies are deleted.
    private func stageAndReceive(_ providers: [NSItemProvider], into model: ComposerDropModel) async {
        let staged = await DroppedFileStager.stage(Array(providers.prefix(DropIntake.maximumItemsLooked)))
        defer { for file in staged { file.removeCopy() } }
        await model.receive(staged.map(\.item), names: staged.map(\.name)) { index, limit in
            try staged[index].read(limit: limit)
        }
    }
}

/// One dropped file, copied to a temporary location.
struct StagedFile: Sendable {
    let item: DroppedItem
    let name: String
    let contentType: String
    let url: URL?

    struct Unreadable: Error {}

    /// Reads at most `limit + 1` bytes, so one byte past the limit shows that the file grew after the stat.
    func read(limit: Int) throws -> (Data, String, String) {
        guard let url else { throw Unreadable() }
        let handle = try FileHandle(forReadingFrom: url)
        defer { try? handle.close() }
        let data = try handle.read(upToCount: limit + 1) ?? Data()
        return (data, name, contentType)
    }

    func removeCopy() {
        if let url { try? FileManager.default.removeItem(at: url) }
    }
}

@MainActor
enum DroppedFileStager {
    private nonisolated static let logger = Logger(subsystem: "Yak", category: "Drop")

    static func stage(_ providers: [NSItemProvider]) async -> [StagedFile] {
        var result: [StagedFile] = []
        for provider in providers { result.append(await stage(provider)) }
        return result
    }

    private static func stage(_ provider: NSItemProvider) async -> StagedFile {
        let identifier = provider.registeredTypeIdentifiers.first {
            guard let type = UTType($0) else { return false }
            return type.conforms(to: .data) || type.conforms(to: .image)
        }
        let type = identifier.flatMap { UTType($0) }
        let isImage = type?.conforms(to: .image) == true
        let contentType = type?.preferredMIMEType ?? "application/octet-stream"
        guard let identifier else {
            return StagedFile(
                item: DroppedItem(byteCount: nil, isImage: false), name: "", contentType: contentType, url: nil)
        }
        return await withCheckedContinuation { continuation in
            _ = provider.loadFileRepresentation(forTypeIdentifier: identifier) { url, error in
                guard let url, error == nil else {
                    logger.error("A dropped file could not be loaded")
                    continuation.resume(
                        returning: StagedFile(
                            item: DroppedItem(byteCount: nil, isImage: isImage), name: "", contentType: contentType,
                            url: nil))
                    return
                }
                let size = (try? url.resourceValues(forKeys: [.fileSizeKey]).fileSize).flatMap { $0 }
                let name = url.lastPathComponent
                let item = DroppedItem(byteCount: size, isImage: isImage)
                // The system's copy goes away when this closure returns, so a file that will be read is copied once.
                var copy: URL?
                if let size, size >= 0, size <= DropIntake.maximumImageReadBytes {
                    let target = FileManager.default.temporaryDirectory.appending(path: UUID().uuidString)
                    do {
                        try FileManager.default.copyItem(at: url, to: target)
                        copy = target
                    } catch {
                        logger.error("A dropped file could not be copied")
                    }
                }
                continuation.resume(
                    returning: StagedFile(
                        item: copy == nil ? DroppedItem(byteCount: nil, isImage: isImage) : item, name: name,
                        contentType: contentType, url: copy))
            }
        }
    }
}
