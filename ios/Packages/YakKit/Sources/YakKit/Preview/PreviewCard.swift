import Foundation

public enum PreviewStepStatus: Equatable, Sendable { case done, active, pending }

public enum PreviewAction: Hashable, Sendable {
    /// Drivers only.
    case ask(MessageIntent, isPending: Bool)
    case tryAgain, restore, retryRestore, openTerminal, showConversation, openInBrowser
}

/// The card the Preview pane shows for a preview state, and the buttons it offers.
public struct PreviewCard: Equatable, Sendable {
    public enum Kind: Equatable, Sendable {
        /// ready: the web view.
        case page
        /// waking_sandbox, starting_dev_server.
        case waking(steps: [PreviewStepStatus])
        case waitingForSlot
        case setupRunning(isAdding: Bool)
        case failing
        case noManifest
        case setupEnded
        case invalidManifest
        case destroyed(isReadOnly: Bool, tookTooLong: Bool, neverHadSandbox: Bool)
        case restoring
        case restoreFailed(isReadOnly: Bool)
        case closed
        case localMachine
        case unknown(state: String)
    }

    public let kind: Kind
    /// In display order; the first is the primary button.
    public let actions: [PreviewAction]

    public init(kind: Kind, actions: [PreviewAction]) {
        self.kind = kind
        self.actions = actions
    }

    /// `sandboxState` is the thread snapshot's (`"none"` tells a thread that never had a sandbox);
    /// `hasKnownPreviewURL` is true when this pane session has seen a preview URL (PreviewSnapshot.lastPreviewURL).
    public static func make(
        status: PreviewStatus, canDrive: Bool?, blockedReason: String?, isRestoring: Bool,
        sandboxState: String? = nil, hasKnownPreviewURL: Bool = false,
        restoreTookTooLong: Bool = false, pendingIntent: MessageIntent? = nil,
        terminalAvailable: Bool = false
    ) -> PreviewCard {
        let drives = canDrive ?? false
        let state = status.state
        func ask(_ intent: MessageIntent) -> PreviewAction { .ask(intent, isPending: pendingIntent == intent) }
        func card(_ kind: Kind, _ actions: [PreviewAction] = []) -> PreviewCard {
            PreviewCard(kind: kind, actions: actions)
        }

        if isRestoring && state == .destroyed { return card(.restoring) }
        if blockedReason == "restore_failed" && state != .closed && state != .localMachine {
            return card(.restoreFailed(isReadOnly: !drives), drives ? [.retryRestore] : [])
        }
        if status.setup.isRunning && (state == .failing || state == .noManifest) {
            return card(.setupRunning(isAdding: status.setup.intent == .addPreviewManifest), [.showConversation])
        }
        switch state {
        case .ready:
            return card(.page)
        case .wakingSandbox:
            return card(.waking(steps: [.active, .pending, .pending]))
        case .startingDevServer:
            return card(.waking(steps: [.done, .active, .pending]))
        case .waitingForSlot:
            return card(.waitingForSlot)
        case .failing:
            var actions: [PreviewAction] = drives ? [ask(.fixPreview)] : []
            if terminalAvailable { actions.append(.openTerminal) }
            actions.append(.tryAgain)
            return card(.failing, actions)
        case .noManifest:
            let actions: [PreviewAction] = drives ? [ask(.addPreviewManifest)] : []
            return card(status.setup.endedWithoutManifest ? .setupEnded : .noManifest, actions)
        case .invalidManifest:
            return card(.invalidManifest, (drives ? [ask(.fixPreview)] : []) + [.tryAgain])
        case .destroyed:
            return card(
                .destroyed(
                    isReadOnly: !drives, tookTooLong: restoreTookTooLong, neverHadSandbox: sandboxState == "none"),
                drives ? [.restore] : [])
        case .closed:
            return card(.closed)
        case .localMachine:
            return card(.localMachine)
        default:
            return card(.unknown(state: state.rawValue), hasKnownPreviewURL ? [.openInBrowser] : [])
        }
    }
}
