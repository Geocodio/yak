import Foundation
import Observation
import YakKit

/// What the unsent row says about its background upload.
enum UploadLineState: Equatable {
    case uploading(percent: Int)
    case stalled(percent: Int)
    case waitingForNetwork
    case failedInBackground

    /// Nil when the item has no upload to talk about; the row then shows the outbox's own line.
    static func make(item: UUID, summary: UploadSummary, stalled: Set<UUID>, isOffline: Bool) -> UploadLineState? {
        if summary.failed.contains(item) { return .failedInBackground }
        guard let progress = summary.progress[item] else { return nil }
        let percent = min(max(Int(progress * 100), 0), 100)
        if isOffline { return .waitingForNetwork }
        return stalled.contains(item) ? .stalled(percent: percent) : .uploading(percent: percent)
    }
}

/// The upload progress of one instance while a thread is open, and which items have stood still for 8 seconds.
@MainActor @Observable
final class UploadWatch {
    static let stallAfter: Duration = .seconds(8)

    private(set) var summary = UploadSummary()
    private(set) var stalled: Set<UUID> = []
    @ObservationIgnored private var task: Task<Void, Never>?
    @ObservationIgnored private var timers: [UUID: Task<Void, Never>] = [:]
    @ObservationIgnored private var clock: (any YakClock)?

    /// Reads `summaries` until `stop()`. A second call replaces the first.
    func start(summaries: AsyncStream<UploadSummary>?, clock: any YakClock) {
        stop()
        guard let summaries else { return }
        self.clock = clock
        task = Task { [weak self] in
            for await next in summaries {
                guard let self, !Task.isCancelled else { return }
                self.apply(next)
            }
        }
    }

    func stop() {
        task?.cancel()
        task = nil
        for timer in timers.values { timer.cancel() }
        timers = [:]
        stalled = []
        summary = UploadSummary()
    }

    private func apply(_ next: UploadSummary) {
        for (item, value) in next.progress where summary.progress[item] != value {
            stalled.remove(item)
            watch(item, at: value)
        }
        for item in Array(timers.keys) where next.progress[item] == nil {
            timers.removeValue(forKey: item)?.cancel()
            stalled.remove(item)
        }
        summary = next
    }

    private func watch(_ item: UUID, at value: Double) {
        timers.removeValue(forKey: item)?.cancel()
        guard let clock else { return }
        timers[item] = Task { [weak self] in
            do { try await clock.sleep(for: Self.stallAfter) } catch { return }
            guard let self, self.summary.progress[item] == value else { return }
            self.stalled.insert(item)
        }
    }
}

extension ThreadModel {
    /// This thread's uploads, from the ledger while the thread is open.
    var uploads: UploadSummary { uploadWatch.summary }

    func uploadLine(for item: OutboxItem) -> UploadLineState? {
        UploadLineState.make(
            item: item.id, summary: uploadWatch.summary, stalled: uploadWatch.stalled, isOffline: isOffline)
    }
}
