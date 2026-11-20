import Foundation

/// What the web conversation of a thread is doing, as pure logic. The view feeds it events (a mint result, what the
/// web view's navigation did, messages from the page, connectivity, time) and carries out the effects it returns.
public struct WebConversationMachine: Equatable, Sendable {
    public enum LoadingStep: Equatable, Sendable {
        /// Asking the server for a code.
        case minting
        /// The redeem request is loading in the web view.
        case redeeming
        /// The page loaded; waiting for its `ready` message.
        case waitingForReady
    }

    public enum Failure: Equatable, Sendable {
        case network
        /// A 5xx, or another status the page should not answer with.
        case server(Int)
        /// No `ready` within `Timing.failAfter`.
        case timeout
        /// The session ended and a new one was already made within `Timing.reMintInterval`.
        case sessionExpired
        case forbidden
        /// The thread is gone or hidden; the view removes its offline copy.
        case notFound
        /// The phone is no longer signed in; the session's own handling takes over.
        case deviceUnauthorized
        /// The server refused to mint a code.
        case mintRejected

        public var isRetryable: Bool {
            switch self {
            case .notFound, .deviceUnauthorized: false
            default: true
            }
        }
    }

    public enum Phase: Equatable, Sendable {
        case loading(LoadingStep)
        case ready
        /// No `ready` after `Timing.slowAfter`; still waiting.
        case slow
        case failed(Failure)
        /// The page said the session expired; a new code is being minted.
        case signedOut
        /// Showing the last copy because the network is not there.
        case offline
        /// The server has no `me.web`.
        case unsupported
    }

    public enum MintFailure: Equatable, Sendable {
        case network
        case unauthorized
        case rejected
        case server(Int)
    }

    public enum NavigationOutcome: Equatable, Sendable {
        /// The main frame committed with a success status.
        case committed
        case http(Int)
        case networkError
    }

    public enum Event: Equatable, Sendable {
        case begin
        case mintSucceeded
        case mintFailed(MintFailure)
        case navigation(NavigationOutcome)
        case page(EmbedPageMessage)
        case connectivity(isOnline: Bool)
        case offlineCopyChanged(isAvailable: Bool)
        /// Time passed; the view sends it at `nextDeadline`.
        case tick
        /// The person asked to try again.
        case retry
    }

    public enum Effect: Equatable, Sendable {
        /// Ask the server for a code, then send `mintSucceeded` or `mintFailed`.
        case mint
        /// Load the redeem request in the web view.
        case redeem
    }

    public struct Timing: Equatable, Sendable {
        public var slowAfter: Duration
        public var failAfter: Duration
        /// A session that ends again within this long after a re-mint is not minted for again.
        public var reMintInterval: Duration

        public init(
            slowAfter: Duration = .seconds(8), failAfter: Duration = .seconds(30),
            reMintInterval: Duration = .seconds(30)
        ) {
            self.slowAfter = slowAfter
            self.failAfter = failAfter
            self.reMintInterval = reMintInterval
        }
    }

    public private(set) var phase: Phase
    public let timing: Timing
    private var hasOfflineCopy: Bool
    private var isOnline = true
    private var waitingSince: Date?
    private var lastReMintAt: Date?

    public init(supportsEmbeddedThread: Bool, hasOfflineCopy: Bool = false, timing: Timing = Timing()) {
        phase = supportsEmbeddedThread ? .loading(.minting) : .unsupported
        self.hasOfflineCopy = hasOfflineCopy
        self.timing = timing
    }

    /// When the view should send `tick` next; nil when time changes nothing.
    public var nextDeadline: Date? {
        guard let waitingSince else { return nil }
        switch phase {
        case .loading(.waitingForReady): return waitingSince.addingTimeInterval(timing.slowAfter.seconds)
        case .slow: return waitingSince.addingTimeInterval(timing.failAfter.seconds)
        default: return nil
        }
    }

    public mutating func handle(_ event: Event, at now: Date) -> [Effect] {
        switch event {
        case .begin:
            guard phase != .unsupported else { return [] }
            return startMinting()
        case .mintSucceeded:
            guard isMintInFlight else { return [] }
            phase = .loading(.redeeming)
            return [.redeem]
        case .mintFailed(let failure):
            guard isMintInFlight else { return [] }
            switch failure {
            case .network: failOrShowCopy()
            case .unauthorized: phase = .failed(.deviceUnauthorized)
            case .rejected: phase = .failed(.mintRejected)
            case .server(let status): phase = .failed(.server(status))
            }
            return []
        case .navigation(let outcome):
            return navigated(outcome, at: now)
        case .page(let message):
            return received(message, at: now)
        case .connectivity(let isOnline):
            self.isOnline = isOnline
            guard isOnline, phase == .offline || phase == .failed(.network) else { return [] }
            return startMinting()
        case .offlineCopyChanged(let isAvailable):
            hasOfflineCopy = isAvailable
            return []
        case .tick:
            guard let waitingSince else { return [] }
            let waited = now.timeIntervalSince(waitingSince)
            switch phase {
            case .loading(.waitingForReady), .slow:
                if waited >= timing.failAfter.seconds {
                    phase = .failed(.timeout)
                } else if waited >= timing.slowAfter.seconds {
                    phase = .slow
                }
            default: break
            }
            return []
        case .retry:
            switch phase {
            case .failed(let failure):
                guard failure.isRetryable else { return [] }
                lastReMintAt = nil
                return startMinting()
            case .offline, .signedOut:
                lastReMintAt = nil
                return startMinting()
            default: return []
            }
        }
    }

    // MARK: Transitions

    private var isMintInFlight: Bool { phase == .loading(.minting) || phase == .signedOut }

    private mutating func startMinting() -> [Effect] {
        phase = .loading(.minting)
        waitingSince = nil
        return [.mint]
    }

    private mutating func failOrShowCopy() {
        phase = hasOfflineCopy ? .offline : .failed(.network)
        waitingSince = nil
    }

    /// The session ended, as a 401 on a navigation or an `auth` message from the page. One new code is minted, and none
    /// again within `Timing.reMintInterval`, so a server that keeps refusing the session cannot start a loop.
    private mutating func sessionExpired(at now: Date, fromPage: Bool) -> [Effect] {
        if let lastReMintAt, now.timeIntervalSince(lastReMintAt) < timing.reMintInterval.seconds {
            phase = .failed(.sessionExpired)
            waitingSince = nil
            return []
        }
        lastReMintAt = now
        waitingSince = nil
        phase = fromPage ? .signedOut : .loading(.minting)
        return [.mint]
    }

    private var isLoadingPage: Bool {
        switch phase {
        case .loading(.redeeming), .loading(.waitingForReady), .slow: true
        default: false
        }
    }

    private mutating func navigated(_ outcome: NavigationOutcome, at now: Date) -> [Effect] {
        switch outcome {
        case .committed:
            guard phase == .loading(.redeeming) else { return [] }
            phase = .loading(.waitingForReady)
            waitingSince = now
            return []
        case .networkError:
            guard isLoadingPage || phase == .ready else { return [] }
            failOrShowCopy()
            return []
        case .http(let status):
            guard isLoadingPage || phase == .ready, status >= 400 else { return [] }
            switch status {
            case 401: return sessionExpired(at: now, fromPage: false)
            case 403: phase = .failed(.forbidden)
            case 404: phase = .failed(.notFound)
            default: phase = .failed(.server(status))
            }
            waitingSince = nil
            return []
        }
    }

    /// `auth` can arrive at any time (a background fetch or a live reload that got 401 or 419); it is ignored while a
    /// new code is being minted or redeemed and while nothing is shown. `error` is acted on only before the page
    /// is ready, because a ready page shows its own errors.
    private mutating func received(_ message: EmbedPageMessage, at now: Date) -> [Effect] {
        switch message {
        case .ready:
            switch phase {
            case .loading(.redeeming), .loading(.waitingForReady), .slow, .failed, .offline, .ready:
                phase = .ready
                waitingSince = nil
            default: break
            }
            return []
        case .authExpired:
            // While the redeem request loads, the page that is still shown is the old one: its message is stale.
            guard phase == .loading(.waitingForReady) || phase == .slow || phase == .ready else { return [] }
            return sessionExpired(at: now, fromPage: true)
        case .error(let kind, let status):
            guard isLoadingPage else { return [] }
            switch kind {
            case .http where (status ?? 0) >= 500: phase = .failed(.server(status ?? 0))
            case .network: failOrShowCopy()
            default: return []
            }
            waitingSince = nil
            return []
        default:
            return []
        }
    }
}

extension Duration {
    fileprivate var seconds: TimeInterval {
        let parts = components
        return TimeInterval(parts.seconds) + TimeInterval(parts.attoseconds) / 1e18
    }
}
