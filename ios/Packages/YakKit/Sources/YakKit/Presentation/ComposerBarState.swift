import Foundation

/// Why the field is replaced by a reason and one action.
public enum ComposerUnavailable: Equatable, Sendable {
    case readOnly
    case closed
    case onComputer
    /// The notice's title and body when the server sent one.
    case blocked(title: String?, body: String?)
}

/// The controls of the one-row bottom bar for what the server allows.
public struct ComposerBarState: Equatable, Sendable {
    public enum Placeholder: Equatable, Sendable { case messageYak, steerYak }

    /// Non-nil: the reason replaces the field.
    public var unavailable: ComposerUnavailable?
    public var placeholder: Placeholder
    public var showsPlus: Bool
    public var showsSend: Bool
    public var showsStop: Bool
    /// The long press menu on Send.
    public var offersSendAfterTurn: Bool
    public var isTurnRunning: Bool

    /// The typed text belongs to the caller and is never cleared by this state.
    public static func make(
        composer: ComposerState?, canDrive: Bool?, turn: TurnChange?, notice: ThreadNotice?,
        typedText: String, attachmentCount: Int
    ) -> ComposerBarState {
        let isRunning = turn?.state.isActive ?? (composer == .working)
        var reason: ComposerUnavailable?
        if canDrive == false || composer == .readonly {
            reason = .readOnly
        } else if composer == .disabledClosed {
            reason = .closed
        } else if composer == .disabledComputer {
            reason = .onComputer
        } else if composer == .disabledBlocked {
            reason = .blocked(title: notice?.title, body: notice?.body)
        }
        guard reason == nil else {
            return ComposerBarState(
                unavailable: reason, placeholder: .messageYak, showsPlus: false, showsSend: false,
                showsStop: false, offersSendAfterTurn: false, isTurnRunning: isRunning)
        }
        let isTyping =
            !typedText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || attachmentCount > 0
        return ComposerBarState(
            unavailable: nil, placeholder: isRunning ? .steerYak : .messageYak, showsPlus: !isTyping,
            showsSend: isTyping, showsStop: isRunning, offersSendAfterTurn: isRunning && isTyping,
            isTurnRunning: isRunning)
    }
}
