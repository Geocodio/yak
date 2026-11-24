import Foundation

/// One unsent message with files that has an upload to talk about, as the chip above the composer shows it.
public struct UploadChip: Equatable, Sendable, Identifiable {
    public enum State: Equatable, Sendable {
        case uploading(percent: Int)
        /// The connection is gone; the upload goes on by itself when it returns.
        case waitingForNetwork
        /// The last attempt failed in transport or was lost; Retry sends it again, and it also goes again later.
        case failed
    }

    public let id: UUID
    public let text: String
    public let state: State
}

public enum UploadChips {
    /// The background uploads of the thread's unsent messages: failed ones first named as failed, then the ones whose
    /// bytes are going out. A message with nothing to say about its upload has no chip.
    public static func make(items: [OutboxItem], threadID: String, summary: UploadSummary, isOffline: Bool)
        -> [UploadChip]
    {
        items.compactMap { item in
            guard item.target == .thread(threadID), !item.attachments.isEmpty else { return nil }
            if summary.failed.contains(item.id) { return UploadChip(id: item.id, text: item.text, state: .failed) }
            guard let progress = summary.progress[item.id] else { return nil }
            let percent = min(max(Int(progress * 100), 0), 100)
            return UploadChip(
                id: item.id, text: item.text, state: isOffline ? .waitingForNetwork : .uploading(percent: percent))
        }
    }
}
