import Foundation
import YakKit

/// Keeps parsed documents by item key. Finished items parse once; a streaming item parses at most ten times a
/// second with one trailing parse, so the last text always shows and a partial block never makes the view jump.
actor MarkdownCache<Renderer: MarkdownRendering> {
    static var capacity: Int { 400 }

    private struct Entry {
        var text: String
        var document: Renderer.Document
        var lastUsed: Int
    }

    private let renderer: Renderer
    private let clock: any YakClock
    private let onUpdate: @MainActor @Sendable (String) -> Void
    private var entries: [String: Entry] = [:]
    private var throttles: [String: ParseThrottle] = [:]
    private var pendingText: [String: String] = [:]
    private var trailingTasks: [String: Task<Void, Never>] = [:]
    private var tick = 0
    private var latestRequest: [String: Int] = [:]

    init(renderer: Renderer, clock: any YakClock, onUpdate: @escaping @MainActor @Sendable (String) -> Void) {
        self.renderer = renderer
        self.clock = clock
        self.onUpdate = onUpdate
    }

    /// The last parsed document of the item, nil until its first parse is done. While a streaming item waits for
    /// its next parse this is the previous document.
    func document(for item: ConversationItem) -> Renderer.Document? {
        guard var entry = entries[item.key] else { return nil }
        tick += 1
        entry.lastUsed = tick
        entries[item.key] = entry
        return entry.document
    }

    func update(with items: [ConversationItem]) async {
        for item in items {
            guard let text = item.text, !text.isEmpty else { continue }
            if entries[item.key]?.text == text {
                if !item.isStreaming { cancelTrailing(for: item.key) }
                continue
            }
            if item.isStreaming {
                await updateStreaming(key: item.key, text: text)
            } else {
                cancelTrailing(for: item.key)
                await parse(key: item.key, text: text)
            }
        }
    }

    func clear() {
        for task in trailingTasks.values { task.cancel() }
        trailingTasks = [:]
        entries = [:]
        throttles = [:]
        pendingText = [:]
        latestRequest = [:]
    }

    private func updateStreaming(key: String, text: String) async {
        var throttle = throttles[key] ?? ParseThrottle()
        let decision = throttle.request(now: clock.now(), textBytes: text.utf8.count)
        throttles[key] = throttle
        switch decision {
        case .now:
            pendingText[key] = nil
            await parse(key: key, text: text)
        case .later(let delay):
            pendingText[key] = text
            trailingTasks.removeValue(forKey: key)?.cancel()
            trailingTasks[key] = Task { [weak self, clock] in
                do { try await clock.sleep(for: delay) } catch { return }
                await self?.parseTrailing(key: key)
            }
        case .alreadyScheduled:
            pendingText[key] = text
        }
    }

    private func parseTrailing(key: String) async {
        trailingTasks[key] = nil
        guard let text = pendingText.removeValue(forKey: key) else { return }
        await parse(key: key, text: text)
    }

    private func cancelTrailing(for key: String) {
        trailingTasks.removeValue(forKey: key)?.cancel()
        pendingText[key] = nil
        throttles[key] = nil
    }

    private func parse(key: String, text: String) async {
        tick += 1
        let request = tick
        latestRequest[key] = request
        let document = await renderer.parse(text)
        // A newer parse of the same item started while this one ran; it stores its own result.
        guard latestRequest[key] == request else { return }
        tick += 1
        entries[key] = Entry(text: text, document: document, lastUsed: tick)
        var throttle = throttles[key] ?? ParseThrottle()
        throttle.parsed(at: clock.now())
        throttles[key] = throttle
        if entries.count > Self.capacity, let oldest = entries.min(by: { $0.value.lastUsed < $1.value.lastUsed })?.key {
            entries[oldest] = nil
            latestRequest[oldest] = nil
            cancelTrailing(for: oldest)
        }
        await onUpdate(key)
    }
}
