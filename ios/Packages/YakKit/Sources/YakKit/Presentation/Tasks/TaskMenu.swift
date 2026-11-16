public struct TaskMenuItem: Equatable, Sendable, Identifiable {
    public let id: String
    public let action: TaskAction?
    public let isDestructive: Bool
}

public enum TaskMenu {
    /// Empty when `canDrive` is false. Order: retry, rerun review, retry render, reroute (one item per target),
    /// cancel last. Only actions the server allows appear.
    public static func items(_ task: AppTask) -> [TaskMenuItem] {
        guard task.canDrive else { return [] }
        let actions = task.actions
        var items: [TaskMenuItem] = []
        if actions.canRetry { items.append(TaskMenuItem(id: "retry", action: .retry, isDestructive: false)) }
        if actions.canRerunReview {
            items.append(TaskMenuItem(id: "rerun-review", action: .rerunReview, isDestructive: false))
        }
        if actions.canRetryRender {
            items.append(TaskMenuItem(id: "retry-render", action: .retryRender, isDestructive: false))
        }
        if actions.canReroute {
            for target in actions.rerouteTargets {
                items.append(
                    TaskMenuItem(id: "reroute:\(target)", action: .reroute(repo: target), isDestructive: false))
            }
        }
        if actions.canCancel { items.append(TaskMenuItem(id: "cancel", action: .cancel, isDestructive: true)) }
        return items
    }
}
