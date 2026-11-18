import Foundation

/// One row of the conversation: the person's messages, the model's events, and what the app has not
/// seen the server's row for yet.
public enum ConversationEntry: Equatable, Sendable {
    case person(ThreadMessage, isPending: Bool)
    case event(ConversationItem)
    case unsent(OutboxItem)
    /// Confirmed by the server, its row not loaded yet.
    case delivered(OutboxDelivery)
    /// A one-line event of the work, such as "CI passed".
    case milestone(ThreadMilestone)
    /// A retry run: it has no text, the row only says that Yak tried again.
    case retry(ThreadMessage)
    /// An answer of a thread made from an old task, server rendered.
    case legacyAnswer(LegacyAnswer)

    /// The message uuid or id; the item key; `outbox:<send key, lowercase>` for an unsent item and its
    /// delivery alike, so a list keeps its row when one becomes the other.
    public var id: String {
        switch self {
        case .person(let message, _): message.uuid ?? message.id
        case .event(let item): item.key
        case .unsent(let item): "outbox:\(item.id.uuidString.lowercased())"
        case .delivered(let delivery): "outbox:\(delivery.sendKey.uuidString.lowercased())"
        case .milestone(let milestone): "milestone:\(milestone.id)"
        case .retry(let message): message.uuid ?? message.id
        case .legacyAnswer(let answer): "legacy-answer:\(answer.runTaskId)"
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
    /// - Parameters:
    ///   - milestones: Placed after the events of their run. One whose `afterSequence` is not after the
    ///     first loaded event stays out until its page loads, so it cannot jump to the top.
    ///   - firstLoadedAfter: The sequence the loaded events start after; 0 when the whole history is loaded.
    ///   - isLegacy: A thread made from an old task: messages and `legacyAnswers` run by time, and each
    ///     milestone follows the answer whose `runTaskId` equals its `runId`.
    public static func merge(
        messages: [ThreadMessage], readUUIDs: Set<String>, items: [ConversationItem],
        readSequences: [String: Int], messageStates: [String: String], unsent: [OutboxItem],
        delivered: [OutboxDelivery], milestones: [ThreadMilestone] = [], firstLoadedAfter: Int = 0,
        isLegacy: Bool = false, legacyAnswers: [LegacyAnswer] = []
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
        func row(_ message: ThreadMessage, isPending: Bool) -> ConversationEntry {
            message.isRetry ? .retry(message) : .person(message, isPending: isPending)
        }

        var entries: [ConversationEntry]
        if isLegacy {
            entries = legacyEntries(
                messages: visible, answers: legacyAnswers, milestones: milestones, isRead: isRead)
        } else {
            var shown: [String: ThreadMilestone] = [:]
            for milestone in milestones where firstLoadedAfter == 0 || milestone.afterSequence > firstLoadedAfter {
                shown[milestone.id] = milestone
            }
            // Equal sequences keep the server's order.
            let placed = milestones.compactMap { shown.removeValue(forKey: $0.id) }.enumerated()
                .sorted { $0.element.afterSequence < $1.element.afterSequence || ($0.element.afterSequence == $1.element.afterSequence && $0.offset < $1.offset) }
                .map(\.element)

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

            entries = history.map { row($0, isPending: false) }
            var nextMilestone = 0
            // A milestone comes after the events up to its sequence and before whatever follows them.
            func appendMilestones(through sequence: Int) {
                while nextMilestone < placed.count, placed[nextMilestone].afterSequence <= sequence {
                    entries.append(.milestone(placed[nextMilestone]))
                    nextMilestone += 1
                }
            }
            var next = 0
            for item in ordered {
                while next < places.count, places[next].place < item.sequence {
                    appendMilestones(through: places[next].place)
                    entries.append(row(places[next].message, isPending: false))
                    next += 1
                }
                appendMilestones(through: item.sequence - 1)
                entries.append(.event(item))
            }
            for later in places[next...] {
                appendMilestones(through: later.place)
                entries.append(row(later.message, isPending: false))
            }
            appendMilestones(through: .max)
            entries += justRead.map { row($0, isPending: false) }
            entries += pending.map { row($0, isPending: true) }
        }

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

    /// Messages and answers in time order; each answer is followed by the milestones of its run, and the
    /// milestones no answer owns come last.
    private static func legacyEntries(
        messages: [ThreadMessage], answers: [LegacyAnswer], milestones: [ThreadMilestone],
        isRead: (ThreadMessage) -> Bool
    ) -> [ConversationEntry] {
        enum Item {
            case message(ThreadMessage)
            case answer(LegacyAnswer)

            var time: String {
                switch self {
                case .message(let message): message.sentAt
                case .answer(let answer): answer.at
                }
            }
        }
        func before(_ left: String, _ right: String) -> Bool {
            if let first = RelativeAge.parse(left), let second = RelativeAge.parse(right) { return first < second }
            return left < right
        }
        let timeline = (messages.map(Item.message) + answers.map(Item.answer)).enumerated()
            .sorted { before($0.element.time, $1.element.time) || (!before($1.element.time, $0.element.time) && $0.offset < $1.offset) }
            .map(\.element)
        var entries: [ConversationEntry] = []
        var placed: Set<String> = []
        for item in timeline {
            switch item {
            case .message(let message):
                entries.append(message.isRetry ? .retry(message) : .person(message, isPending: !isRead(message)))
            case .answer(let answer):
                entries.append(.legacyAnswer(answer))
                for milestone in milestones where milestone.runId == answer.runTaskId && placed.insert(milestone.id).inserted {
                    entries.append(.milestone(milestone))
                }
            }
        }
        for milestone in milestones where placed.insert(milestone.id).inserted { entries.append(.milestone(milestone)) }
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
