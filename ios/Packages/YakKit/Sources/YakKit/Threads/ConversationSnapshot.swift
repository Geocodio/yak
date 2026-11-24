import Foundation

/// Why a thread could not be loaded, each one a designed screen state.
public enum ThreadProblem: Equatable, Sendable {
    case notFound, offline, needsPairing, appTooOld, serverTooOld
    case server(code: String, message: String)

    public init(_ failure: APIFailure) {
        switch failure {
        case .server(let error):
            switch error.status {
            case 404: self = .notFound
            case 503: self = .offline
            default: self = .server(code: error.code, message: error.message)
            }
        case .network: self = .offline
        case .unauthorized: self = .needsPairing
        case .appTooOld: self = .appTooOld
        case .serverTooOld: self = .serverTooOld
        case .notYak: self = .server(code: "not_yak", message: "The answer did not come from a Yak server.")
        case .encodingFailed: self = .server(code: "encoding_failed", message: "The request could not be built.")
        }
    }

    /// The case and the error code, never the server's message text, so it is safe to log.
    var logName: String {
        if case .server(let code, _) = self { return "server(\(code))" }
        return "\(self)"
    }

    /// Whether asking again can change the outcome.
    public var isRetryable: Bool {
        switch self {
        case .offline, .server: true
        case .notFound, .needsPairing, .appTooOld, .serverTooOld: false
        }
    }
}

public enum ThreadLoadState: Equatable, Sendable {
    case loading, slow, ready
    case failed(ThreadProblem)
}

/// Everything the chat screen draws, as one value.
/// The server's sentence about a sent message, keyed by the send it answers.
public struct SendNotice: Equatable, Sendable, Identifiable {
    public let id: UUID
    public let text: String

    public init(id: UUID, text: String) {
        self.id = id
        self.text = text
    }
}

public struct ConversationSnapshot: Equatable, Sendable {
    public var load: ThreadLoadState
    public var show: ThreadShow?
    public var entries: [ConversationEntry]
    public var currentStepKey: String?
    public var waiting: WaitingRow?
    public var turn: TurnChange?
    public var thread: ThreadStateChange?
    public var connection: ConnectionState
    /// Shown from the cache, not confirmed by the server yet.
    public var isShowingCachedCopy: Bool
    /// The last catch-up or show request failed with `.offline`.
    public var isOffline: Bool
    /// A `/clear` delivery named a new thread.
    public var replacementThreadID: String?
    public var lastSequence: Int
    /// The newest request failure other than `.offline` while a conversation stays on screen: show a
    /// banner. It clears on the next success. Without anything to show, `load` is `.failed` instead.
    public var problem: ThreadProblem?
    /// The newest applied `diff.updated`; nil before one.
    public var lastDiffUpdateSequence: Int?
    /// An action waits out the server's `rate_limited` and has been waiting for more than five seconds.
    public var isActionSlow: Bool
    /// The newest sentence the server gave with a send answer (the reply to a stopped thread); show it once per `id`.
    public var lastSendNotice: SendNotice?

    public init(
        load: ThreadLoadState = .loading, show: ThreadShow? = nil, entries: [ConversationEntry] = [],
        currentStepKey: String? = nil, waiting: WaitingRow? = nil, turn: TurnChange? = nil,
        thread: ThreadStateChange? = nil, connection: ConnectionState = .offline,
        isShowingCachedCopy: Bool = false, isOffline: Bool = false, replacementThreadID: String? = nil,
        lastSequence: Int = 0,
        problem: ThreadProblem? = nil, lastDiffUpdateSequence: Int? = nil, isActionSlow: Bool = false,
        lastSendNotice: SendNotice? = nil
    ) {
        self.load = load
        self.show = show
        self.entries = entries
        self.currentStepKey = currentStepKey
        self.waiting = waiting
        self.turn = turn
        self.thread = thread
        self.connection = connection
        self.isShowingCachedCopy = isShowingCachedCopy
        self.isOffline = isOffline
        self.replacementThreadID = replacementThreadID
        self.lastSequence = lastSequence
        self.problem = problem
        self.lastDiffUpdateSequence = lastDiffUpdateSequence
        self.isActionSlow = isActionSlow
        self.lastSendNotice = lastSendNotice
    }
}
