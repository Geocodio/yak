/// The pull request a thread has opened.
public struct ThreadPullRequestLink: Codable, Equatable, Sendable {
    public let number: Int
    public let url: String

    public init(number: Int, url: String) {
        self.number = number
        self.url = url
    }
}

public struct PullRequestOpeningState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let opening = PullRequestOpeningState(rawValue: "opening")
    public static let held = PullRequestOpeningState(rawValue: "held")
    public static let failed = PullRequestOpeningState(rawValue: "failed")
}

/// An opening that is running, waiting for a co-owner, or has failed. Absent when none is.
public struct PullRequestOpening: Codable, Equatable, Sendable {
    public let state: PullRequestOpeningState
    public let error: String?
    public let at: String?

    public init(state: PullRequestOpeningState, error: String? = nil, at: String? = nil) {
        self.state = state
        self.error = error
        self.at = at
    }
}
