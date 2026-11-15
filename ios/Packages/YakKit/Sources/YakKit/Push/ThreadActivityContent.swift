/// The state a Live Activity shows. A value the app does not know decodes and reads as working.
public struct ThreadActivityState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let working = ThreadActivityState(rawValue: "working")
    public static let needsYou = ThreadActivityState(rawValue: "needs_you")
    public static let finished = ThreadActivityState(rawValue: "finished")
    public static let failed = ThreadActivityState(rawValue: "failed")
    public static let stopped = ThreadActivityState(rawValue: "stopped")
}

/// The Live Activity's content state. ActivityKit decodes it with a default JSONDecoder, so `turnStartedAt` stays a String.
public struct ThreadActivityContentState: Codable, Hashable, Sendable {
    public var state: ThreadActivityState
    public var step: String
    public var turnStartedAt: String
    public var isPrivate: Bool

    public init(state: ThreadActivityState, step: String, turnStartedAt: String, isPrivate: Bool) {
        self.state = state
        self.step = step
        self.turnStartedAt = turnStartedAt
        self.isPrivate = isPrivate
    }
}
