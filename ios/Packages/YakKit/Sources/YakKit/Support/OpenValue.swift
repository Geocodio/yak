/// A server enum that keeps any string it does not know, so a new server value never fails a decode.
public protocol OpenValue: RawRepresentable, Codable, Hashable, Sendable where RawValue == String {
    init(rawValue: String)
}

extension OpenValue {
    public init(from decoder: any Decoder) throws {
        self.init(rawValue: try decoder.singleValueContainer().decode(String.self))
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(rawValue)
    }
}

public struct TurnState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let pending = TurnState(rawValue: "pending")
    public static let waitingForAccount = TurnState(rawValue: "waiting_for_account")
    public static let waitingForSandbox = TurnState(rawValue: "waiting_for_sandbox")
    public static let running = TurnState(rawValue: "running")
    public static let completed = TurnState(rawValue: "completed")
    public static let failed = TurnState(rawValue: "failed")
    public static let stoppedByGuard = TurnState(rawValue: "stopped_by_guard")
    public static let interrupted = TurnState(rawValue: "interrupted")

    /// True while the turn has not finished.
    public var isActive: Bool {
        self == .pending || self == .waitingForAccount || self == .waitingForSandbox || self == .running
    }
}

public struct ThreadStatus: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let open = ThreadStatus(rawValue: "open")
    public static let closed = ThreadStatus(rawValue: "closed")
}

public struct ThreadRowStatus: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let running = ThreadRowStatus(rawValue: "running")
    public static let blocked = ThreadRowStatus(rawValue: "blocked")
    public static let paused = ThreadRowStatus(rawValue: "paused")
    public static let stopped = ThreadRowStatus(rawValue: "stopped")
    public static let closed = ThreadRowStatus(rawValue: "closed")
    public static let waitingForRepository = ThreadRowStatus(rawValue: "waiting_for_repository")
}

public struct NeedsYouReason: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let question = NeedsYouReason(rawValue: "question")
    public static let blocked = NeedsYouReason(rawValue: "blocked")
    public static let unseen = NeedsYouReason(rawValue: "unseen")
    public static let coOwner = NeedsYouReason(rawValue: "co_owner")
}

public struct ThreadRowGroup: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let today = ThreadRowGroup(rawValue: "today")
    public static let thisWeek = ThreadRowGroup(rawValue: "this_week")
    public static let older = ThreadRowGroup(rawValue: "older")
}

public struct ComposerState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let idle = ComposerState(rawValue: "idle")
    public static let working = ComposerState(rawValue: "working")
    public static let disabledClosed = ComposerState(rawValue: "disabled_closed")
    public static let disabledComputer = ComposerState(rawValue: "disabled_computer")
    public static let disabledBlocked = ComposerState(rawValue: "disabled_blocked")
    public static let readonly = ComposerState(rawValue: "readonly")
}

public struct MessageState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let pending = MessageState(rawValue: "pending")
    public static let read = MessageState(rawValue: "read")
    public static let cancelled = MessageState(rawValue: "cancelled")
}

public struct MessageMode: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let steer = MessageMode(rawValue: "steer")
    public static let queue = MessageMode(rawValue: "queue")
}

public struct SendStatus: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let sent = SendStatus(rawValue: "sent")
    public static let queued = SendStatus(rawValue: "queued")
}
