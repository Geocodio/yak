/// What the one card above the composer, which the server names in `card` and the thread page draws, asks of the
/// person. The app draws no card; it only knows whether one waits for the person, for the activity line of the other
/// panes. A reader has the words and no buttons.
public enum ThreadCardState: Equatable, Sendable {
    case none
    /// The clarification question.
    case question
    /// Another card the server named; `hasButtons` is whether the person has something to press on it.
    case card(ThreadCard, hasButtons: Bool)
    /// A card word this build does not know.
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
            return .card(card, hasButtons: question.canAnswer && canDrive)
        case .earlyWarning:
            guard let notice = show.earlyWarning else { return .none }
            if case .pick = EarlyWarningRow.make(canDrive: canDrive, notice: notice) {
                return .card(card, hasButtons: true)
            }
            return .card(card, hasButtons: false)
        case .coOwner:
            guard let panel = show.coOwner else { return .none }
            let mayDecide = canDrive && (panel.canDecide ?? true)
            switch TaskDecisionState.make(canDrive: mayDecide, panel: panel) {
            case .none, .decided, .noWriteAccess: return .card(card, hasButtons: false)
            default: return .card(card, hasButtons: true)
            }
        case .stopped:
            guard show.stopped != nil else { return .none }
            let flags = show.actions
            let canRetry = canDrive && flags.canRetry
            let hasMove = canDrive && flags.canMove && !flags.moveTargets.isEmpty
            return .card(card, hasButtons: canRetry || hasMove)
        default:
            return .unknown(card.rawValue)
        }
    }

    /// True when a card other than the question waits for the person: it has something to press.
    public var needsYou: Bool {
        if case .card(_, let hasButtons) = self { hasButtons } else { false }
    }

    /// True when the card is there but offers nothing to press.
    public var hasNoButtons: Bool {
        if case .card(_, let hasButtons) = self { !hasButtons } else { false }
    }
}
