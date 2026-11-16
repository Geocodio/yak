import Foundation

public enum UploadOrigin: String, Codable, Sendable { case app, share }

public enum UploadFailure: String, Codable, Sendable {
    /// The session reported an error.
    case transport
    /// The session no longer has the task and no answer came.
    case lost
    /// Prepared, but no task was started in time.
    case notStarted
}

public enum UploadPhase: Codable, Equatable, Sendable {
    case prepared
    /// `task` is kept for logs and reconcile only; answers are matched by `UploadTaskDescription`.
    case uploading(session: String, task: Int)
    /// The server answered. `body` is at most `UploadLedger.answerLimit` bytes.
    case answered(status: Int, body: Data, protocolHeader: String?, retryAfter: String?)
    case failed(UploadFailure)
}

public struct UploadRecord: Codable, Equatable, Sendable {
    public let itemID: UUID
    public let origin: UploadOrigin
    /// Relative to `/api/app/v1/`.
    public let path: String
    public let contentType: String
    /// Relative to the app group.
    public let bodyPath: String
    public var phase: UploadPhase
    public var attempts: Int
    public var updatedAt: Date

    public init(
        itemID: UUID, origin: UploadOrigin, path: String, contentType: String, bodyPath: String,
        phase: UploadPhase = .prepared, attempts: Int = 0, updatedAt: Date
    ) {
        self.itemID = itemID
        self.origin = origin
        self.path = path
        self.contentType = contentType
        self.bodyPath = bodyPath
        self.phase = phase
        self.attempts = attempts
        self.updatedAt = updatedAt
    }
}

public enum UploadOutcome: Equatable, Sendable {
    case delivered(status: SendStatus, messageUUID: String?, threadID: String?)
    case decision(OutboxDecision)
}

public struct UploadSummary: Equatable, Sendable {
    /// 0...1 per item while bytes go out.
    public var progress: [UUID: Double]
    /// Items whose last attempt failed in transport or was lost; they go again on launch, foreground, refresh or Retry.
    public var failed: Set<UUID>

    public init(progress: [UUID: Double] = [:], failed: Set<UUID> = []) {
        self.progress = progress
        self.failed = failed
    }
}
