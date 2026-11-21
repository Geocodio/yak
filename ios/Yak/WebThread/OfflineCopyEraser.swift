import YakKit

/// Removes an instance's saved thread copies when the person unpairs it or the server revokes it. The session erases
/// them as well when it ends itself; this covers a revoke that the app learns of from outside the session.
@MainActor
final class OfflineCopyEraser: InstanceLifecycleObserver {
    private let erase: @MainActor (Instance) async -> Void

    init(erase: @escaping @MainActor (Instance) async -> Void) { self.erase = erase }

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async { await erase(instance) }
}
