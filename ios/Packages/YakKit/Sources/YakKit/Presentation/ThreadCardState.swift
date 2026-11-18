/// The one card above the composer. The server names it in `card`; the app draws only that one, with
/// the buttons the server's flags allow. A reader gets the words and no buttons.
public enum ThreadCardState: Equatable, Sendable {
    case none
    /// The clarification question; its own model draws it.
    case question
    case repositoryQuestion(RepositoryQuestion, canAnswer: Bool)
    case earlyWarning(EarlyWarningRow, EarlyWarningNotice)
    case coOwner(TaskDecisionState, CoOwnerPanel)
    /// `canRetry` offers Retry; `canSetUp` offers Set up and continue instead, while the repository is not set up.
    case stopped(ThreadStop, canRetry: Bool, canSetUp: Bool, moveTargets: [String])
    /// A card word this build does not know. Nothing is drawn and the composer stays usable.
    case unknown(String)

    /// `isForbidden` is the engine's answer to a refused action: the person can read but not drive.
    public static func make(show: ThreadShow?, isForbidden: Bool) -> ThreadCardState {
        guard let show else { return .none }
        let canDrive = show.canDrive && !isForbidden
        guard let card = show.card else {
            // An older server names no card; it only ever had the question.
            return show.question == nil ? .none : .question
        }
        switch card {
        case .question:
            return show.question == nil ? .none : .question
        case .repositoryQuestion:
            guard let question = show.repositoryQuestion else { return .none }
            return .repositoryQuestion(question, canAnswer: question.canAnswer && canDrive)
        case .earlyWarning:
            guard let notice = show.earlyWarning else { return .none }
            return .earlyWarning(EarlyWarningRow.make(canDrive: canDrive, notice: notice), notice)
        case .coOwner:
            guard let panel = show.coOwner else { return .none }
            let mayDecide = canDrive && (panel.canDecide ?? true)
            return .coOwner(TaskDecisionState.make(canDrive: mayDecide, panel: panel), panel)
        case .stopped:
            guard let stop = show.stopped else { return .none }
            let flags = show.actions
            let canRetry = canDrive && flags.canRetry
            let moveTargets = canDrive && flags.canMove ? flags.moveTargets : []
            return .stopped(
                stop, canRetry: canRetry && !stop.isWaitingForSetup, canSetUp: canRetry && stop.isWaitingForSetup,
                moveTargets: moveTargets)
        default:
            return .unknown(card.rawValue)
        }
    }

    /// True when the card is drawn but offers nothing to press.
    public var hasNoButtons: Bool {
        switch self {
        case .none, .question, .unknown: false
        case .repositoryQuestion(_, let canAnswer): !canAnswer
        case .earlyWarning(let row, _):
            if case .pick = row { false } else { true }
        case .coOwner(let decision, _):
            switch decision {
            case .none, .decided, .noWriteAccess: true
            default: false
            }
        case .stopped(_, let canRetry, let canSetUp, let moveTargets): !canRetry && !canSetUp && moveTargets.isEmpty
        }
    }
}
