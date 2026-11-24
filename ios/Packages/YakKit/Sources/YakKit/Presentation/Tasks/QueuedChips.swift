/// One message that waits for its turn, as the chip above the composer shows it.
public struct QueuedChip: Equatable, Sendable, Identifiable {
    public let uuid: String
    public let text: String
    /// Sends after the turn (the Queued label), as opposed to steering the running turn.
    public let isQueued: Bool
    public let attachmentCount: Int
    /// The channel the message came from, as the server names it; nil for a message typed in the app or the page.
    public let sourceLabel: String?
    public let menu: [QueuedMessageMenuItem]

    public var id: String { uuid }
}

/// Which waiting messages the native chips show above the composer while the conversation is the web page, which
/// leaves them out of its own list.
public enum QueuedChips {
    /// The server's id for a message that is saved but not handed to a turn yet.
    public static let idPrefix = "queued-message-"
    /// More chips than this fold into one "N more" row.
    public static let collapsedLimit = 3

    /// One chip per waiting message, in the server's order. Messages that went out, other people's replies without a
    /// uuid to act on, and retry rows are not chips.
    public static func chips(from messages: [ThreadMessage]) -> [QueuedChip] {
        messages.compactMap { message in
            guard message.id.hasPrefix(idPrefix), message.state == .pending, !message.isRetry, let uuid = message.uuid
            else { return nil }
            let isQueued = message.mode == .queue
            return QueuedChip(
                uuid: uuid, text: message.text, isQueued: isQueued, attachmentCount: message.attachments?.count ?? 0,
                sourceLabel: message.via?.displayName,
                menu: QueuedMessageMenu.items(canEditText: message.canEditText, isQueued: isQueued))
        }
    }

    /// What the list draws: the first `collapsedLimit` chips and how many more fold behind them.
    public static func visible(_ chips: [QueuedChip], isExpanded: Bool) -> (shown: [QueuedChip], hiddenCount: Int) {
        guard !isExpanded, chips.count > collapsedLimit else { return (chips, 0) }
        return (Array(chips.prefix(collapsedLimit)), chips.count - collapsedLimit)
    }

    /// Whether the person may change the waiting messages: not in a read-only, closed or legacy thread.
    public static func canManage(composerState: ComposerState?) -> Bool {
        guard let composerState else { return false }
        return ![ComposerState.readonly, .disabledClosed, .legacy].contains(composerState)
    }
}
