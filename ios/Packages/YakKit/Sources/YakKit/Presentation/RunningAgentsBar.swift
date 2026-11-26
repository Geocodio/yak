import Foundation

/// Why "Stop all" did not stop the agents, each one a sentence the bar shows beside Try again.
public enum AgentsStopFailure: String, Equatable, Sendable, CaseIterable {
    /// No connection, or the request timed out.
    case offline
    /// 401: the device is not signed in any more.
    case signedOut
    /// 403: the person may read the thread but not drive it.
    case forbidden
    /// 404: the thread or its agents are not there (the server has agents switched off).
    case unavailable
    /// 429, once the wait the server asked for was too long to take silently.
    case tooManyRequests
    /// A 5xx answer, or anything else the app cannot say better.
    case serverProblem

    public init(_ outcome: ThreadActionOutcome) {
        switch outcome {
        case .failed(.offline): self = .offline
        case .failed(.needsPairing): self = .signedOut
        case .failed(.notFound): self = .unavailable
        case .failed: self = .serverProblem
        case .refused(let code, _, _, _):
            switch code {
            case "forbidden": self = .forbidden
            case "not_found": self = .unavailable
            case "rate_limited": self = .tooManyRequests
            default: self = .serverProblem
            }
        case .done, .alreadySent: self = .serverProblem
        }
    }

    /// Whether Try again can change the outcome.
    public var isRetryable: Bool { self != .forbidden && self != .unavailable && self != .signedOut }
}

/// How many agents of the open thread run and what "Stop all" is doing about them. The thread show feeds it first;
/// once the page has reported for this page load, the page's numbers win until the page reloads. Pure state: the
/// bar's view and its model only ask it.
public struct RunningAgentsBar: Equatable, Sendable {
    public enum Phase: Equatable, Sendable {
        case running
        /// "Stop all" is on its way to the server.
        case requesting
        /// The server took the request; the bar stays until a report says no agent runs.
        case stopping
        case failed(AgentsStopFailure)
    }

    public private(set) var count = 0
    /// The fragment of the newest running agent's card on the page; nil when the page has not named one.
    public private(set) var newest: String?
    public private(set) var phase: Phase = .running
    /// The page has reported for the current page load.
    public private(set) var hasPageReported = false
    /// Counts the entries into `.stopping`, whatever reported them, so the owner starts its timeout on each one.
    public private(set) var stoppingSerial = 0

    public init() {}

    /// The agents of a thread show. Ignored once the page has reported, and from an older server that sends none.
    public mutating func showReported(_ agents: RunningAgents?) {
        guard !hasPageReported, let agents else { return }
        update(count: agents.runningCount, newest: nil)
        if count > 0, phase == .running, agents.isStopping { enterStopping() }
    }

    /// The page's `agents` message.
    public mutating func pageReported(running: Int, newest: String?) {
        hasPageReported = true
        update(count: max(0, running), newest: newest)
    }

    /// The page loaded again, or showed another thread: the show decides until the page reports.
    public mutating func pageReset() {
        hasPageReported = false
        newest = nil
    }

    private mutating func update(count newCount: Int, newest newName: String?) {
        let previous = count
        count = newCount
        newest = newCount > 0 ? newName : nil
        if newCount == 0 {
            phase = .running
        } else if newCount > previous, phase == .stopping {
            // Agents that started after the stop ran are not stopping.
            phase = .running
        }
        // A report that moves the count says things are not as they were when the stop failed.
        if newCount != previous, case .failed = phase { phase = .running }
    }

    private mutating func enterStopping() {
        phase = .stopping
        stoppingSerial += 1
    }

    /// The device went online: a failure that only said there was no connection is gone.
    public mutating func connectivityChanged(isOffline: Bool) {
        if !isOffline, phase == .failed(.offline) { phase = .running }
    }

    /// Whether a "Stop all" may start now.
    public var canRequestStop: Bool {
        guard count > 0 else { return false }
        switch phase {
        case .running, .failed: return true
        case .requesting, .stopping: return false
        }
    }

    /// The person confirmed. False when a request already runs or nothing needs stopping, so a second tap sends nothing.
    public mutating func beginStop() -> Bool {
        guard canRequestStop else { return false }
        phase = .requesting
        return true
    }

    /// The request ended. A success keeps the bar, now saying "Stopping", until a report says zero; the answer's own
    /// count is taken at once when it says nothing runs.
    public mutating func finishStop(_ result: AgentsStopResult) {
        guard phase == .requesting else { return }
        switch result.outcome {
        case .done:
            enterStopping()
            if let agents = result.agents, agents.runningCount == 0 { update(count: 0, newest: nil) }
        default:
            phase = .failed(AgentsStopFailure(result.outcome))
        }
    }

    /// The agents did not go within the time the model waits: the bar offers "Stop all" again.
    public mutating func stoppingTimedOut() {
        if phase == .stopping { phase = .running }
    }

    /// Whether VoiceOver is told about a change of the count: only a bar that was showing, and only a real change.
    public static func announces(from old: Int, to new: Int) -> Bool { old > 0 && old != new }
}

/// What the bar draws, from its state and from what the screen knows about the person and the thread.
public struct RunningAgentsBarLine: Equatable, Sendable {
    public let count: Int
    public let isStopping: Bool
    public let isRequesting: Bool
    public let failure: AgentsStopFailure?
    /// The page named a card to scroll to.
    public let newest: String?
    /// "Stop all" is drawn: only for a person who may drive the thread.
    public let showsStop: Bool
    public let stopIsEnabled: Bool
    /// Stopping needs the server and the device is offline; the bar says so.
    public let needsConnection: Bool

    public var showsRetry: Bool { failure?.isRetryable == true }

    /// Nil hides the bar: no agent runs, or the thread is closed.
    public static func make(
        bar: RunningAgentsBar, canDrive: Bool, isClosed: Bool, isOffline: Bool
    ) -> RunningAgentsBarLine? {
        guard bar.count > 0, !isClosed else { return nil }
        let failure: AgentsStopFailure? = if case .failed(let reason) = bar.phase { reason } else { nil }
        let showsStop = canDrive && failure != .forbidden
        return RunningAgentsBarLine(
            count: bar.count, isStopping: bar.phase == .stopping, isRequesting: bar.phase == .requesting,
            failure: failure, newest: bar.newest, showsStop: showsStop,
            stopIsEnabled: showsStop && bar.canRequestStop && !isOffline,
            needsConnection: showsStop && isOffline && bar.canRequestStop && failure == nil)
    }
}
