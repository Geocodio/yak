import Foundation

/// Why a walkthrough video could not play, read from the player item's error chain.
public enum WalkthroughVideoProblem: Equatable, Sendable {
    case offline, timedOut, expired, unsupported, other
    public static let maximumDepth = 4

    /// `errors` is the player item's error followed by its `NSUnderlyingErrorKey` chain, outermost first; only the
    /// first `maximumDepth` entries are read. `httpStatus` is the item's error log's last `errorStatusCode`, because
    /// progressive MP4 errors usually arrive as AVFoundationErrorDomain -11800 over a CoreMedia or NSURL error and
    /// carry no status of their own.
    /// Order: httpStatus 403, 404 or 410 is `expired`; else the first pair in the chain that the table maps; else `other`.
    public static func make(errors: [(domain: String, code: Int)], httpStatus: Int?) -> WalkthroughVideoProblem {
        if let httpStatus, [403, 404, 410].contains(httpStatus) { return .expired }
        for error in errors.prefix(maximumDepth) {
            switch (error.domain, error.code) {
            case ("NSURLErrorDomain", -1009): return .offline
            case ("NSURLErrorDomain", -1001): return .timedOut
            case ("CoreMediaErrorDomain", -12660), ("CoreMediaErrorDomain", -12938): return .expired
            case ("AVFoundationErrorDomain", -11828): return .unsupported
            default: continue
            }
        }
        return .other
    }
}

/// What the player shows while a walkthrough video starts.
public enum WalkthroughPlaybackPhase: Equatable, Sendable {
    case loading, slow, playing
    case failed(WalkthroughVideoProblem)
    public static let slowAfter: Duration = .seconds(8)
    public static let failAfter: Duration = .seconds(30)

    /// Pure: `isReady` from the item status, `failure` from its error, `waited` since play was asked.
    /// Precedence: ready, then an item failure, then offline, then the time limits.
    /// Offline before ready gives `.failed(.offline)`; not ready after `failAfter` gives `.failed(.timedOut)`.
    public static func make(
        isReady: Bool, failure: WalkthroughVideoProblem?, isOnline: Bool, waited: Duration
    ) -> WalkthroughPlaybackPhase {
        if isReady { return .playing }
        if let failure { return .failed(failure) }
        if !isOnline { return .failed(.offline) }
        if waited >= failAfter { return .failed(.timedOut) }
        return waited >= slowAfter ? .slow : .loading
    }
}
