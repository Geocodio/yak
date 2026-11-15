/// A chunk of streaming text that is not stored; the stored `entry` replaces it.
public struct StreamDelta: Decodable, Equatable, Sendable {
    public let blockKey: String
    public let kind: String
    public let text: String
}

public struct TurnChange: Decodable, Equatable, Sendable {
    public let turnId: LenientID
    public let state: TurnState
}

public struct ThreadStateChange: Decodable, Equatable, Sendable {
    public let status: ThreadStatus
    public let sandboxState: String
    public let runnerState: String
    public let blockedReason: String?
    public let pullRequestOpening: String?
}

public struct MessageStateChange: Decodable, Equatable, Sendable {
    public let uuid: String
    public let state: String
}

/// The latest state of one sub-thread. Optional fields use `decodeIfPresent` through the synthesized
/// decoder, so the server can add or leave out fields.
public struct ChildCard: Codable, Equatable, Sendable {
    public let childThreadId: LenientID
    public let name: String
    public let kind: String
    public let model: String?
    public let state: String
    public let step: String?
    public let resultSummary: String?
    public let branch: String?
    public let messagesPaused: Bool
    public let question: String?
    public let filesChanged: Int?
    public let additions: Int?
    public let deletions: Int?
    public let decision: String?
}

/// The latest state of one repository Claude added to the thread.
public struct RepositoryCard: Codable, Equatable, Sendable {
    public let id: LenientID?
    public let repo: String
    public let role: String
    public let state: String
    public let path: String?
    public let reason: String?
    public let error: String?
}

public struct QuestionChange: Decodable, Equatable, Sendable {
    public let open: Bool
}
