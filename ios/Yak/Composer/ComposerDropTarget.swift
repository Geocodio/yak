import Observation
import SwiftUI
import UniformTypeIdentifiers
import YakKit

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
    /// The whole drop gets this long; what is not looked at by then counts as unreadable.
    private let timeLimit: Duration

    private(set) var line: String?
    /// Counts refusals, so the view plays the error haptic once for each.
    private(set) var refusalCount = 0

    init(
        acceptsDrops: @escaping () -> Bool, attached: @escaping () -> Int,
        limits: @escaping () -> (maxFiles: Int, maxFileBytes: Int)?,
        add: @escaping (Data, String, String) async -> Void, timeLimit: Duration = .seconds(60)
    ) {
        self.timeLimit = timeLimit
        acceptsDropsNow = acceptsDrops
        self.attached = attached
        self.limits = limits
        self.add = add
    }

    var acceptsDrops: Bool { acceptsDropsNow() }

    func clearLine() { line = nil }

    /// Whether a bar state takes drops: the field must be there.
    static func accepts(_ bar: ComposerBarState) -> Bool { bar.unavailable == nil }

    /// Looks at the first 100 of `count` files one at a time. A file past the free places is never opened; `stat(i)`
    /// asks one file's size without copying it, and only an accepted file is read, with `load(i, limit)`, which stops
    /// at `limit + 1` bytes. A file that fails or times out is counted and the rest still attach. After `timeLimit`
    /// the rest of the drop is counted as unreadable.
    func receive(
        count: Int,
        stat: (Int) async -> (item: DroppedItem, name: String),
        load: (Int, Int) async throws -> (Data, String, String)
    ) async {
        guard acceptsDrops else { return }
        guard let limits = limits() else {
            refuse(String(localized: "drop.unavailable"))
            return
        }
        let looked = min(count, DropIntake.maximumItemsLooked)
        let places = (limits.maxFiles < 0 || limits.maxFileBytes < 0) ? 0 : limits.maxFiles - max(0, attached())
        let deadline = ContinuousClock.now.advanced(by: timeLimit)
        var attachedNow = 0
        var tooMany = max(0, count - looked)
        var tooLargeNames: [String] = []
        var unreadable = 0
        for index in 0..<looked {
            if ContinuousClock.now >= deadline {
                unreadable += looked - index
                break
            }
            if attachedNow >= places {
                tooMany += 1
                continue
            }
            let (item, name) = await stat(index)
            let decision = DropIntake.decide(
                [item], attached: attached() + attachedNow, maxFiles: limits.maxFiles,
                maxFileBytes: limits.maxFileBytes
            ).first
            switch decision {
            case .tooLarge?:
                tooLargeNames.append(self.name(name))
            case .accept?:
                let limit = DropIntake.readLimit(isImage: item.isImage, maxFileBytes: limits.maxFileBytes)
                do {
                    let (data, fileName, contentType) = try await load(index, limit)
                    if data.count > limit {
                        tooLargeNames.append(self.name(fileName))
                    } else {
                        await add(data, ComposerAttachments.safeFileName(fileName), contentType)
                        attachedNow += 1
                    }
                } catch {
                    unreadable += 1
                }
            case .tooMany?:
                tooMany += 1
            case .unreadable?, nil:
                unreadable += 1
            }
        }
        var lines: [String] = []
        if tooMany > 0 {
            lines.append(String(localized: "drop.tooMany \(tooMany) \(limits.maxFiles)"))
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

    private func name(_ fileName: String) -> String { DropIntake.lineName(fileName) }

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
                acceptsDrops: { ComposerDropModel.accepts(composer.bar) },
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

    /// Hands the providers to the model, which opens them one at a time where they live.
    private func stageAndReceive(_ providers: [NSItemProvider], into model: ComposerDropModel) async {
        let files = DroppedFiles(providers: Array(providers.prefix(DropIntake.maximumItemsLooked)))
        await model.receive(
            count: providers.count, stat: { await files.stat($0) }, load: { try await files.read($0, limit: $1) })
    }
}
