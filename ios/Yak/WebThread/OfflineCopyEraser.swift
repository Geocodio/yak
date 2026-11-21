import Foundation
import YakKit

#if canImport(os)
    import os
#endif

/// Removes an instance's saved thread copies when the person unpairs it or the server revokes it. It deletes the
/// folder itself, so it works when no session or window exists for the instance, and puts a session that does exist
/// in the erased state, so its store refuses later writes.
@MainActor
final class OfflineCopyEraser: InstanceLifecycleObserver {
    private let files: any FileStore
    private let existingSession: @MainActor (UUID) -> InstanceSession?

    init(files: any FileStore, existingSession: @escaping @MainActor (UUID) -> InstanceSession? = { _ in nil }) {
        self.files = files
        self.existingSession = existingSession
    }

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        do { try files.remove(InstanceFolders.offlineCopies(instance.id)) } catch {
            Logger(subsystem: "Yak", category: "OfflineCopies").error("Offline copies were not erased")
        }
        // A session that still exists must not write into the erased folder again.
        await existingSession(instance.id)?.eraseOfflineCopies()
    }
}
