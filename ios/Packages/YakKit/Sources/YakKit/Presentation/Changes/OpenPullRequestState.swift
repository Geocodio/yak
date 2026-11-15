/// Why the Open PR button is shown disabled. The reason only chooses the accessibility hint.
public enum OpenPullRequestUnavailable: Equatable, Sendable {
    case loading, readOnly, closed, noChanges, notReady
}

/// What the Open PR button shows, decided from the thread show. Enablement is the server's
/// `canOpenPullRequest` alone.
public enum OpenPullRequestState: Equatable, Sendable {
    case unavailable(OpenPullRequestUnavailable)
    /// `needsConfirmation` is the show's `publishWarning`.
    case available(needsConfirmation: Bool)
    case sending
    /// The server is opening it.
    case opening
    /// Waiting for a co-owner.
    case held
    /// The server's reason; the button is available again.
    case failed(message: String)
    case open(number: Int, url: String)

    public static let defaultFailureMessage = "Yak could not open the pull request. Try again."

    public static func make(show: ThreadShow?, isSending: Bool) -> OpenPullRequestState {
        guard let show else { return .unavailable(.loading) }
        if let pullRequest = show.pullRequest { return .open(number: pullRequest.number, url: pullRequest.url) }
        if isSending { return .sending }
        let opening = show.thread.pullRequestOpening
        if opening?.state == .opening { return .opening }
        if opening?.state == .held { return .held }
        if show.canOpenPullRequest {
            if let opening, opening.state == .failed {
                return .failed(message: opening.error ?? defaultFailureMessage)
            }
            return .available(needsConfirmation: show.publishWarning)
        }
        if !show.canDrive { return .unavailable(.readOnly) }
        if show.thread.status == .closed { return .unavailable(.closed) }
        if (show.diffTotals?.filesCount ?? 0) == 0 { return .unavailable(.noChanges) }
        return .unavailable(.notReady)
    }
}
