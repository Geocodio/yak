import Foundation
import Observation
import YakKit

/// The card above the composer: which one the server names, and the action in flight on it. The rules for what is
/// offered are YakKit's `ThreadCardState`; this class sends the person's choice once and keeps the words for how it ended.
@MainActor @Observable
final class ThreadCardModel: CoOwnerDecisionHost {
    /// Refusals that mean the card was already dealt with elsewhere; the page is refreshed and says so.
    private static let closedElsewhereCodes: Set<String> = [
        "thread_closed", "already_resolved", "already_decided", "repository_already_picked", "question_closed",
    ]
    static let candidateRounds = 5
    static let candidateDelay: Duration = .seconds(2)
    static let slowAfter: Duration = .seconds(5)
    static let alreadyAnsweredFor: Duration = .seconds(3)

    private(set) var sending: ThreadWorkAction?
    private(set) var message: String?
    private(set) var canRetryAfterMessage = false
    var feedback = ""
    private(set) var feedbackError: String?
    private(set) var candidates: CoOwnerCandidateList = .loading
    /// True while the card says the work was already dealt with.
    private(set) var isAlreadyAnswered = false
    /// The approval has run for more than five seconds.
    private(set) var isApprovalSlow = false
    private(set) var successCount = 0
    private(set) var errorCount = 0
    /// A refused decision said the account is not linked: the card then offers the link, until the panel's access or
    /// status changes or a later action succeeds.
    private var linkOverride: (connectURL: String, access: String, status: String)?
    @ObservationIgnored private var lastAction: ThreadWorkAction?
    @ObservationIgnored private var slowTask: Task<Void, Never>?
    @ObservationIgnored private var answeredTask: Task<Void, Never>?
    @ObservationIgnored private weak var thread: ThreadModel?

    init(thread: ThreadModel) { self.thread = thread }

    var state: ThreadCardState { ThreadCardState.make(show: thread?.snapshot?.show, isForbidden: false) }
    var isOffline: Bool { thread?.isOffline ?? false }
    var isSending: Bool { sending != nil }
    var isApproving: Bool { sending == .approve }
    var holdReasons: [String] { [] }
    var repositoryName: String {
        thread?.snapshot?.show?.thread.shownRepo ?? String(localized: "thread.card.coOwner.thisRepository")
    }

    var decision: TaskDecisionState {
        guard case .coOwner(let decision, _) = state else { return .none }
        if let linkOverride, case .decide = decision, let panel = thread?.snapshot?.show?.coOwner,
            panel.viewer.access == linkOverride.access, panel.status == linkOverride.status
        {
            return .linkGitHub(connectURL: linkOverride.connectURL)
        }
        return decision
    }

    var linkGitHubURL: URL? {
        guard case .linkGitHub(let connectURL) = decision else { return nil }
        return WebLinkRule.safeURL(connectURL)
    }

    func decide(_ decision: CoOwnerDecision) async -> Bool {
        switch decision {
        case .approve: await send(.approve)
        case .requestChanges(let feedback): await send(.requestChanges(feedback: feedback))
        case .reject: await send(.reject)
        case .withdraw: await send(.withdraw)
        case .decline: await send(.decline)
        case .tag(let userID): await send(.tag(userID: userID))
        }
    }

    /// Sends the action once and says how it ended. True when the server accepted it, or said it was already done.
    @discardableResult
    func send(_ action: ThreadWorkAction) async -> Bool {
        guard sending == nil, !isOffline, let engine = thread?.engine else { return false }
        sending = action
        lastAction = action
        message = nil
        canRetryAfterMessage = false
        feedbackError = nil
        startSlowTimer(for: action)
        let outcome = await engine.perform(action)
        slowTask?.cancel()
        isApprovalSlow = false
        sending = nil
        switch outcome {
        case .done:
            linkOverride = nil
            successCount += 1
            if case .requestChanges = action { feedback = "" }
            return true
        case .alreadySent:
            return true
        case .refused(let code, let text, let fieldErrors, let connectURL):
            if Self.closedElsewhereCodes.contains(code) || code.hasPrefix("nothing_to_") {
                showAlreadyAnswered()
                return true
            }
            if code == "not_linked" {
                if let panel = thread?.snapshot?.show?.coOwner {
                    linkOverride = (connectURL ?? panel.connectUrl, panel.viewer.access, panel.status)
                }
            } else {
                message = text.isEmpty ? String(localized: "chat.action.refused") : text
                errorCount += 1
            }
            if case .requestChanges = action { feedbackError = fieldErrors["feedback"]?.first }
            return false
        case .failed(let problem):
            errorCount += 1
            message =
                switch problem {
                case .offline: String(localized: "chat.action.offline")
                case .server(_, let text) where !text.isEmpty: text
                default: String(localized: "chat.action.failed")
                }
            canRetryAfterMessage = action.isSafeToResend
            return false
        }
    }

    /// Sends the action that failed again, or asks the server for the page when it cannot be repeated safely.
    func retry() async {
        let action = lastAction
        message = nil
        canRetryAfterMessage = false
        if let action, action.isSafeToResend { await send(action) } else { await thread?.engine?.refreshShow() }
    }

    /// Asks GitHub again for the viewer's access.
    func checkAccess() async { await send(.checkAccess) }

    /// Asks who can approve; asks again, a few times, while the server says the list is not complete.
    func loadCandidates() async {
        guard let engine = thread?.engine, let clock = thread?.session.clock else {
            candidates = .failed
            return
        }
        candidates = .loading
        for round in 0..<Self.candidateRounds {
            switch await engine.coOwnerCandidates() {
            case .success(let answer):
                let isChecking = !answer.isComplete && round + 1 < Self.candidateRounds
                candidates = .loaded(answer.candidates, isChecking: isChecking)
                guard isChecking else { return }
                do { try await clock.sleep(for: Self.candidateDelay) } catch { return }
            case .failure(.network(.cancelled)):
                return
            case .failure:
                candidates = .failed
                return
            }
        }
    }

    private func startSlowTimer(for action: ThreadWorkAction) {
        slowTask?.cancel()
        isApprovalSlow = false
        guard action == .approve, let clock = thread?.session.clock else { return }
        slowTask = Task { [weak self] in
            do { try await clock.sleep(for: Self.slowAfter) } catch { return }
            self?.isApprovalSlow = true
        }
    }

    private func showAlreadyAnswered() {
        isAlreadyAnswered = true
        answeredTask?.cancel()
        guard let clock = thread?.session.clock else { return }
        answeredTask = Task { [weak self] in
            do { try await clock.sleep(for: Self.alreadyAnsweredFor) } catch { return }
            self?.isAlreadyAnswered = false
        }
    }

    func stop() {
        slowTask?.cancel()
        answeredTask?.cancel()
    }
}
