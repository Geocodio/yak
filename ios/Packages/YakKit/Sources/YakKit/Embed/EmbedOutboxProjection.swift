import Foundation

/// What the page shows of the outbox: the unsent messages of one thread, in the order they were written.
public enum EmbedOutboxProjection {
    public static func message(threadID: String, items: [OutboxItem]) -> EmbedAppMessage {
        let rows =
            items
            .filter { $0.target == .thread(threadID) }
            .sorted { ($0.createdAt, $0.id.uuidString) < ($1.createdAt, $1.id.uuidString) }
            .map(row)
        return .outbox(threadID: threadID, items: rows)
    }

    static func row(_ item: OutboxItem) -> EmbedOutboxItem {
        let status: EmbedOutboxItem.Status
        var reason: String?
        switch item.state {
        case .queued: status = .queued
        case .sending: status = .sending
        case .slow: status = .slow
        case .waiting: status = .waiting
        case .refused(let refusal):
            status = .refused
            reason = refusal.message
        }
        return EmbedOutboxItem(
            key: item.id.uuidString.lowercased(), text: item.text, status: status, reason: reason,
            createdAt: item.createdAt, attachmentCount: item.attachments.count)
    }
}
