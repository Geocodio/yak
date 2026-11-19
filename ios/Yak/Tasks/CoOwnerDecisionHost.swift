import Foundation
import Observation
import YakKit

/// What a co-owner can do from the decision bar and its two sheets.
enum CoOwnerDecision: Equatable {
    case approve
    case requestChanges(feedback: String)
    case reject, withdraw, decline
    case tag(userID: Int)
}

/// The list of people a co-owner request can go to.
enum CoOwnerCandidateList: Equatable {
    case loading
    /// `isChecking`: the server stopped before it reached everyone, so the list is asked for again.
    case loaded([CoOwnerCandidate], isChecking: Bool)
    case failed
}

/// What the decision bar, the Request changes sheet and the Tag a co-owner sheet read. The task screen and the card
/// of a thread both provide it, so one set of views serves both.
@MainActor protocol CoOwnerDecisionHost: AnyObject, Observable {
    var decision: TaskDecisionState { get }
    var isSending: Bool { get }
    var isApproving: Bool { get }
    var message: String? { get }
    var canRetryAfterMessage: Bool { get }
    var linkGitHubURL: URL? { get }
    var isOffline: Bool { get }
    var feedback: String { get set }
    var feedbackError: String? { get }
    var candidates: CoOwnerCandidateList { get }
    var holdReasons: [String] { get }
    var repositoryName: String { get }
    func loadCandidates() async
    /// Sends the decision once. True when the server accepted it.
    @discardableResult func decide(_ decision: CoOwnerDecision) async -> Bool
    /// Asks the server for the current state again.
    func retry() async
}
