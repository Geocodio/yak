public struct ThreadMenuItem: Equatable, Sendable, Identifiable {
    public let id: String
    public let action: ThreadWorkAction
    public let isDestructive: Bool
}

/// The work actions of the thread's menu. Stop stays in the composer.
public enum ThreadMenu {
    /// Order: retry (or set up and continue while the repository is not set up), request review, retry render, move
    /// (one item per target), cancel last. Only what the server allows appears, and nothing for a reader. Move
    /// needs both `canMove` and a target.
    public static func items(actions: ThreadActionFlags, canDrive: Bool, isWaitingForSetup: Bool = false)
        -> [ThreadMenuItem]
    {
        guard canDrive else { return [] }
        var items: [ThreadMenuItem] = []
        if actions.canRetry {
            items.append(
                isWaitingForSetup
                    ? ThreadMenuItem(id: "set-up-and-continue", action: .setUpAndContinue, isDestructive: false)
                    : ThreadMenuItem(id: "retry", action: .retry, isDestructive: false))
        }
        if actions.canRequestReview {
            items.append(ThreadMenuItem(id: "request-review", action: .requestReview, isDestructive: false))
        }
        if actions.canRetryRender {
            items.append(ThreadMenuItem(id: "retry-render", action: .retryRender, isDestructive: false))
        }
        if actions.canMove {
            for target in actions.moveTargets {
                items.append(ThreadMenuItem(id: "move:\(target)", action: .move(repo: target), isDestructive: false))
            }
        }
        if actions.canCancel { items.append(ThreadMenuItem(id: "cancel", action: .cancel, isDestructive: true)) }
        return items
    }

    public static func items(show: ThreadShow?, isForbidden: Bool) -> [ThreadMenuItem] {
        guard let show else { return [] }
        return items(
            actions: show.actions, canDrive: show.canDrive && !isForbidden,
            isWaitingForSetup: show.stopped?.isWaitingForSetup ?? false)
    }
}
