import YakKit

/// Follows the instances for the widgets: a pairing registers the widget token and reloads, a removal reloads and rebuilds the Home Screen quick actions without the removed instance.
@MainActor
final class WidgetLifecycle: InstanceLifecycleObserver {
    private let scheduler: WidgetReloadScheduler

    init(scheduler: WidgetReloadScheduler) { self.scheduler = scheduler }

    func instancePaired(_ instance: Instance) async { await scheduler.paired() }

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        scheduler.removed(instance: instance.id)
        QuickActions.update(instance: await scheduler.remainingInstance(excluding: instance.id), snapshot: nil)
    }
}
