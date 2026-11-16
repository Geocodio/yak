import Foundation

/// Limits how often streaming text is parsed: at most one parse per `interval`.
public struct ParseThrottle: Sendable {
    public enum Decision: Equatable, Sendable {
        case now
        case later(Duration)
        case alreadyScheduled
    }

    /// At most 10 parses a second.
    public static let streamingInterval: Duration = .milliseconds(100)

    private let interval: Duration
    private var lastParse: Date?
    private var isScheduled = false

    public init(interval: Duration = ParseThrottle.streamingInterval) {
        self.interval = interval
    }

    public mutating func request(now: Date) -> Decision {
        guard let lastParse else { return .now }
        let elapsed = Duration.milliseconds((now.timeIntervalSince(lastParse) * 1000).rounded())
        if elapsed >= interval { return .now }
        if isScheduled { return .alreadyScheduled }
        isScheduled = true
        return .later(interval - elapsed)
    }

    public mutating func parsed(at date: Date) {
        lastParse = date
        isScheduled = false
    }
}
