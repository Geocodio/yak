/// The colour class of the dot before the thread's title. It is never the rust accent.
public enum ThreadStatusDot: Equatable, Sendable {
    case running, attention, neutral

    /// Blocked or waiting is `attention`, working is `running`, everything else is `neutral`. The live turn wins over
    /// the runner state the server last sent.
    public static func make(_ snapshot: ConversationSnapshot?) -> ThreadStatusDot {
        guard let snapshot else { return .neutral }
        if snapshot.show?.thread.blockedReason != nil { return .attention }
        if let state = snapshot.turn?.state {
            if state == .waitingForAccount || state == .waitingForSandbox { return .attention }
            if state.isActive { return .running }
        }
        if let runner = snapshot.show?.thread.runnerState, runner == "busy" || runner == "running" {
            return snapshot.turn == nil ? .running : .neutral
        }
        return .neutral
    }
}

/// What the conversation area shows for a load state.
public enum ThreadLoadPresentation: Equatable, Sendable {
    /// The conversation itself.
    case conversation
    /// Nothing for the first 300 ms, then skeleton bubbles.
    case loading
    case slow
    case notFound
    case offlineWithoutCopy
    case serverError(message: String)
    /// Pairing and version problems belong to the session's status screen.
    case sessionOwns

    public static func make(_ load: ThreadLoadState) -> ThreadLoadPresentation {
        switch load {
        case .ready: .conversation
        case .loading: .loading
        case .slow: .slow
        case .failed(let problem):
            switch problem {
            case .notFound: .notFound
            case .offline: .offlineWithoutCopy
            case .server(_, let message): .serverError(message: message)
            case .needsPairing, .appTooOld, .serverTooOld: .sessionOwns
            }
        }
    }
}

extension ConversationSnapshot {
    /// A question is open: the dot on Chat in the system strip.
    public var hasOpenQuestion: Bool { show?.question != nil }
}
