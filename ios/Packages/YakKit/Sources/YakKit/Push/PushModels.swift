import Foundation

/// What a notification or switch is about. Payload events (`account`, `test`) never have a switch.
public struct PushEvent: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let needsYou = PushEvent(rawValue: "needs_you")
    public static let turnFinished = PushEvent(rawValue: "turn_finished")
    public static let stackUsage = PushEvent(rawValue: "stack_usage")
    public static let pullRequest = PushEvent(rawValue: "pull_request")
    public static let account = PushEvent(rawValue: "account")
    public static let test = PushEvent(rawValue: "test")

    /// The four switches, in the order of the Settings board.
    public static let switches: [PushEvent] = [.needsYou, .turnFinished, .pullRequest, .stackUsage]
}

/// What a push points at.
public struct PushKind: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let thread = PushKind(rawValue: "thread")
    public static let task = PushKind(rawValue: "task")
    public static let account = PushKind(rawValue: "account")
}

public enum PushTokenKind: String, Codable, CaseIterable, Sendable {
    case alert
    case liveActivityStart = "live_activity_start"
}

public enum PushEnvironment: String, Codable, Sendable {
    case sandbox
    case production
}

public struct NotificationPreferences: Codable, Equatable, Sendable {
    public let events: [String: Bool]

    public init(events: [String: Bool]) {
        self.events = events
    }

    /// A key the server does not send reads as on.
    public func isOn(_ event: PushEvent) -> Bool {
        events[event.rawValue] ?? true
    }
}

public struct PushTestResult: Codable, Equatable, Sendable {
    public let status: String
}

public struct LiveActivityChannel: Codable, Equatable, Sendable {
    public let channel: String
}
