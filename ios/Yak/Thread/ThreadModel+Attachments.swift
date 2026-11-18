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
    static func make(
        item: UUID, summary: UploadSummary, stalled: Set<UUID>, isOffline: Bool, hasAttachments: Bool = false
    ) -> UploadLineState? {
        if summary.failed.contains(item) { return .failedInBackground }
        guard let progress = summary.progress[item] else {
            return isOffline && hasAttachments ? .waitingForNetwork : nil
        }
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
            item: item.id, summary: uploadWatch.summary, stalled: uploadWatch.stalled, isOffline: isOffline,
            hasAttachments: !item.attachments.isEmpty)
    }
}

/// The loader of a thread's attachments and the rule that refreshes expired links at most once a minute.
@MainActor
final class AttachmentLoading {
    var loader: AttachmentImageLoader?
    var hasNotedOpened = false
    private var lastRefresh: Date?

    /// True when a refresh may run now; at most one every 60 seconds.
    func claimRefresh(at now: Date) -> Bool {
        if let lastRefresh, now.timeIntervalSince(lastRefresh) < 60 { return false }
        lastRefresh = now
        return true
    }
}

extension ThreadModel {
    private func attachmentLoader() async -> AttachmentImageLoader? {
        if let existing = attachmentLoading.loader { return existing }
        guard let cache = await session.instanceCache() else { return nil }
        let maximumBytes = await session.me()?.attachments.maxFileBytes ?? 10_485_760
        let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask)[0]
        let made = AttachmentImageLoader(
            cache: cache, fetcher: URLSessionAttachmentFetcher(), maximumBytes: maximumBytes, caches: caches)
        attachmentLoading.loader = made
        return made
    }

    func loadThumbnail(_ row: MessageAttachmentRow, pixelSize: Int) async -> AttachmentLoad {
        guard let loader = await attachmentLoader() else { return .failed }
        return await loader.thumbnail(thread: threadID, row: row, pixelSize: pixelSize)
    }

    func loadFile(_ row: MessageAttachmentRow) async -> AttachmentFile {
        guard let loader = await attachmentLoader() else { return .failed }
        return await loader.file(row: row)
    }

    /// Fetches the thread again so its links are new; false when one ran in the last 60 seconds.
    func refreshShowForExpiredLinks() async -> Bool {
        let now = session.clock.now()
        guard attachmentLoading.claimRefresh(at: now) else { return false }
        await engine?.refreshShow()
        return true
    }

    /// Tells the share sheet which thread was opened last, once per model, when its show first arrives.
    func noteOpenedForSharing(_ snapshot: ConversationSnapshot) {
        guard !attachmentLoading.hasNotedOpened, let show = snapshot.show else { return }
        attachmentLoading.hasNotedOpened = true
        Task { await session.noteOpenedThread(show) }
    }
}
