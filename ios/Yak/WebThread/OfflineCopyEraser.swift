import YakKit

#if canImport(os)
    import os
#endif

/// Removes an instance's saved thread copies when the person unpairs it or the server revokes it. It deletes the
/// folder itself, so it works when no session or window exists for the instance. The session erases and invalidates
/// its store as well when it ends itself.
@MainActor
final class OfflineCopyEraser: InstanceLifecycleObserver {
    private let files: any FileStore

    init(files: any FileStore) { self.files = files }

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        do { try files.remove(InstanceFolders.offlineCopies(instance.id)) } catch {
            Logger(subsystem: "Yak", category: "OfflineCopies").error("Offline copies were not erased")
        }
    }
}
