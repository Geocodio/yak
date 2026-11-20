import Foundation

/// One unsent message as the page shows it. `key` is the message's `send_key`, in lowercase like the server's.
public struct EmbedOutboxItem: Equatable, Sendable {
    public enum Status: String, Equatable, Sendable { case queued, sending, slow, waiting, refused }

    public let key: String
    public let text: String
    public let status: Status
    /// The server's sentence for a refused message; nil otherwise.
    public let reason: String?
    public let createdAt: Date
    public let attachmentCount: Int

    public init(
        key: String, text: String, status: Status, reason: String?, createdAt: Date, attachmentCount: Int
    ) {
        self.key = key
        self.text = text
        self.status = status
        self.reason = reason
        self.createdAt = createdAt
        self.attachmentCount = attachmentCount
    }
}

/// A message the app sends to the embedded thread page, version 1.
public enum EmbedAppMessage: Equatable, Sendable {
    /// Switch to another thread, optionally scrolling to a fragment.
    case open(threadID: String, fragment: String?)
    /// A fragment such as `turn-812` or `card-question`.
    case focus(fragment: String)
    case scrollToBottom
    /// The full list for one thread; the page replaces what it shows.
    case outbox(threadID: String, items: [EmbedOutboxItem])
    case refresh
    case connectivity(isOnline: Bool)

    /// A fragment the page may be told to scroll to: letters, digits, "-" and "_", 1 to 64 characters.
    public static func isValidFragment(_ text: String) -> Bool {
        !text.isEmpty && text.utf8.count <= 64
            && text.utf8.allSatisfy {
                ($0 >= 0x30 && $0 <= 0x39) || ($0 >= 0x41 && $0 <= 0x5A) || ($0 >= 0x61 && $0 <= 0x7A)
                    || $0 == UInt8(ascii: "-") || $0 == UInt8(ascii: "_")
            }
    }

    /// The message as a dictionary of strings, numbers, booleans, nulls, arrays and dictionaries, the form
    /// `callAsyncJavaScript` takes as an argument. Nil when a thread id or a fragment would not pass the page's checks,
    /// so nothing is sent.
    public func dictionary() -> [String: Any]? {
        var result: [String: Any] = ["v": EmbedPageMessage.version]
        switch self {
        case .open(let threadID, let fragment):
            guard ThreadIdentifier.isValid(threadID), fragment.map(Self.isValidFragment) ?? true else { return nil }
            result["type"] = "open"
            result["threadId"] = threadID
            if let fragment { result["fragment"] = fragment }
        case .focus(let fragment):
            guard Self.isValidFragment(fragment) else { return nil }
            result["type"] = "focus"
            result["fragment"] = fragment
        case .scrollToBottom:
            result["type"] = "scrollToBottom"
        case .outbox(let threadID, let items):
            guard ThreadIdentifier.isValid(threadID) else { return nil }
            result["type"] = "outbox"
            result["threadId"] = threadID
            result["items"] = items.map { item -> [String: Any] in
                [
                    "key": item.key, "text": item.text, "status": item.status.rawValue,
                    "reason": item.reason.map { $0 as Any } ?? NSNull(),
                    "createdAt": item.createdAt.formatted(.iso8601), "attachmentCount": item.attachmentCount,
                ]
            }
        case .refresh:
            result["type"] = "refresh"
        case .connectivity(let isOnline):
            result["type"] = "connectivity"
            result["online"] = isOnline
        }
        return result
    }
}
