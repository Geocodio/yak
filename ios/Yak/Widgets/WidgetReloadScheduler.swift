import Foundation
import WidgetKit
import YakKit

/// Tells the system to reload the widgets' timelines when what they show has changed, and resumes the widget token
/// registration. Reloads are budgeted by the system, so it asks only when a snapshot or a count really differs.
@MainActor
final class WidgetReloadScheduler {
    static let kinds = ["NeedsYou", "RunningThreads"]

    private let instances: InstanceStore?
    private let registerPending: (@Sendable (Duration) async -> Void)?
    private let reload: @MainActor (String?) -> Void
    private var lastSnapshots: [UUID: WidgetSnapshot] = [:]
    private var lastCounts: [UUID: Int]?
    /// True after a list load changed what the widgets show and before the next reload of every timeline.
    private var hasUnreloadedChange = false

    /// `reload` takes a widget kind, or nil for every kind. A nil `instances` does nothing at all.
    init(
        instances: InstanceStore?, registerPending: (@Sendable (Duration) async -> Void)?,
        reload: @escaping @MainActor (String?) -> Void = { WidgetReloadScheduler.reloadWidgetCenter($0) }
    ) {
        self.instances = instances
        self.registerPending = registerPending
        self.reload = reload
    }

    static func reloadWidgetCenter(_ kind: String?) {
        if let kind {
            WidgetCenter.shared.reloadTimelines(ofKind: kind)
        } else {
            WidgetCenter.shared.reloadAllTimelines()
        }
    }

    /// After a `mine` list loaded: reloads both kinds when the widgets' view of it differs from the last one seen.
    func listLoaded(instance: Instance, list: ThreadList) {
        guard instances != nil else { return }
        let epoch = Date(timeIntervalSince1970: 0)
        let snapshot = WidgetSnapshot.make(from: list, instanceName: instance.name, now: epoch)
        guard lastSnapshots[instance.id] != snapshot else { return }
        lastSnapshots[instance.id] = snapshot
        hasUnreloadedChange = true
        reloadBothKinds()
    }

    /// The app left the front: reloads everything when a list load changed something since the last reload, and
    /// resumes pending registrations within 10 seconds.
    func enteredBackground() async {
        guard instances != nil else { return }
        if hasUnreloadedChange {
            hasUnreloadedChange = false
            reload(nil)
        }
        await registerPending?(.seconds(10))
    }

    /// After push's background refresh: reloads both kinds when any instance's stored needs-you count differs from
    /// the counts seen last.
    func backgroundRefreshFinished() async {
        guard let instances else { return }
        var counts: [UUID: Int] = [:]
        for instance in await instances.all() { counts[instance.id] = instance.needsYouCount }
        guard counts != lastCounts else { return }
        lastCounts = counts
        reloadBothKinds()
    }

    /// The instance the quick actions fall back to after a removal: the most recently used one still stored.
    func remainingInstance(excluding removed: UUID) async -> Instance? {
        await instances?.all().first { $0.id != removed }
    }

    /// A pairing: resumes the registration for the new instance and reloads.
    func paired() async {
        guard instances != nil else { return }
        await registerPending?(.seconds(10))
        reload(nil)
    }

    /// A removal or a revoke: the widgets stop showing the instance.
    func removed(instance: UUID) {
        guard instances != nil else { return }
        hasUnreloadedChange = false
        lastSnapshots[instance] = nil
        lastCounts?[instance] = nil
        reload(nil)
    }

    private func reloadBothKinds() {
        for kind in Self.kinds { reload(kind) }
    }
}
