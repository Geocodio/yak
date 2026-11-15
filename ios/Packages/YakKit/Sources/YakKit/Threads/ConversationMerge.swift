import Foundation

/// One row of the conversation: the person's messages, the model's events, and what the app has not
/// seen the server's row for yet.
public enum ConversationEntry: Equatable, Sendable {
    case person(ThreadMessage, isPending: Bool)
    case event(ConversationItem)
    case unsent(OutboxItem)
    /// Confirmed by the server, its row not loaded yet.
    case delivered(OutboxDelivery)

    /// The message uuid or id; the item key; `outbox:<send key, lowercase>` for an unsent item and its
    /// delivery alike, so a list keeps its row when one becomes the other.
    public var id: String {
        switch self {
        case .person(let message, _): message.uuid ?? message.id
        case .event(let item): item.key
        case .unsent(let item): "outbox:\(item.id.uuidString.lowercased())"
        case .delivered(let delivery): "outbox:\(delivery.sendKey.uuidString.lowercased())"
        }
    }
}

/// Remembers every uuid ever read, so a replay can never turn a read message back into a pending one.
public struct ReadTracker: Equatable, Sendable {
    private var known: Set<String> = []

    public init() {}

    public mutating func track(_ uuids: some Sequence<String>) -> Set<String> {
        known.formUnion(uuids)
        return known
    }
}

public struct WaitingRow: Equatable, Sendable {
    public let isQueued: Bool
    public let since: String?

    public init(isQueued: Bool, since: String? = nil) {
        self.isQueued = isQueued
        self.since = since
    }
}

/// A port of the web client's `mergeConversation.ts`, plus the unsent and delivered items.
public enum ConversationMerge {
    public static func merge(
        messages: [ThreadMessage], readUUIDs: Set<String>, items: [ConversationItem],
        readSequences: [String: Int], messageStates: [String: String], unsent: [OutboxItem],
        delivered: [OutboxDelivery]
    ) -> [ConversationEntry] {
        var uniqueMessages: [String: ThreadMessage] = [:]
        for message in messages { uniqueMessages[message.uuid ?? message.id] = message }
        let visible = uniqueMessages.values
            .filter { message in
                message.state != .cancelled && message.uuid.flatMap { messageStates[$0] } != "cancelled"
            }
            .sorted { $0.sentAt < $1.sentAt || ($0.sentAt == $1.sentAt && $0.id < $1.id) }

        func isRead(_ message: ThreadMessage) -> Bool {
            if message.state == .read { return true }
            guard let uuid = message.uuid else { return false }
            return readUUIDs.contains(uuid) || ["started", "completed"].contains(messageStates[uuid] ?? "")
        }
        func anchor(_ message: ThreadMessage) -> Int? {
            message.readAfterSequence ?? message.uuid.flatMap { readSequences[$0] }
        }

        let history = visible.filter { $0.state == .read && anchor($0) == nil }
        let justRead = visible.filter { isRead($0) && $0.state != .read && anchor($0) == nil }
        let pending = visible.filter { !isRead($0) }

        // A message never passes one sent after it: its place is the smallest anchor from itself onward.
        let anchoredInSendOrder = visible.filter { isRead($0) && anchor($0) != nil }
        var places: [(message: ThreadMessage, place: Int)] = []
        var smallest = Int.max
        for message in anchoredInSendOrder.reversed() {
            smallest = min(smallest, anchor(message) ?? Int.max)
            places.append((message, smallest))
        }
        places.reverse()

        var uniqueItems: [String: ConversationItem] = [:]
        for item in items { uniqueItems[item.key] = item }
        let ordered = uniqueItems.values.sorted {
            $0.sequence < $1.sequence || ($0.sequence == $1.sequence && $0.key < $1.key)
        }

        var entries: [ConversationEntry] = history.map { .person($0, isPending: false) }
        var next = 0
        for item in ordered {
            while next < places.count, places[next].place < item.sequence {
                entries.append(.person(places[next].message, isPending: false))
                next += 1
            }
            entries.append(.event(item))
        }
        entries += places[next...].map { .person($0.message, isPending: false) }
        entries += justRead.map { .person($0, isPending: false) }
        entries += pending.map { .person($0, isPending: true) }

        let rowSendKeys = Set(messages.compactMap { $0.sendKey?.lowercased() })
        let rowUUIDs = Set(messages.compactMap(\.uuid))
        let deliveredKeys = Set(delivered.map(\.sendKey))
        entries +=
            delivered
            .filter { delivery in
                !rowSendKeys.contains(delivery.sendKey.uuidString.lowercased())
                    && !(delivery.messageUUID.map { rowUUIDs.contains($0) } ?? false)
            }
            .sorted { $0.sentAt < $1.sentAt }
            .map { .delivered($0) }
        entries +=
            unsent
            .filter { item in
                !rowSendKeys.contains(item.id.uuidString.lowercased()) && !deliveredKeys.contains(item.id)
            }
            .sorted { $0.createdAt < $1.createdAt }
            .map { .unsent($0) }
        return entries
    }

    /// The key of the last entry when it is a running `tools` group and the turn is active.
    public static func currentStepKey(in entries: [ConversationEntry], turn: TurnChange?) -> String? {
        guard turn?.state.isActive == true, case .event(let item)? = entries.last,
            item.kind == .tools, item.isRunning
        else { return nil }
        return item.key
    }

    public static func waitingRow(in entries: [ConversationEntry], turn: TurnChange?) -> WaitingRow? {
        guard let last = entries.last else { return nil }
        switch last {
        case .event(let item) where item.kind == .tools && item.isRunning: return nil
        case .person(let message, true): return WaitingRow(isQueued: true, since: message.sentAt)
        case .unsent(let item): return WaitingRow(isQueued: true, since: timestamp(item.createdAt))
        case .delivered(let delivery) where delivery.status == .queued:
            return WaitingRow(isQueued: true, since: timestamp(delivery.sentAt))
        default: return turn?.state.isActive == true ? WaitingRow(isQueued: false, since: nil) : nil
        }
    }

    private static func timestamp(_ date: Date) -> String {
        ISO8601DateFormatter().string(from: date)
    }
}
