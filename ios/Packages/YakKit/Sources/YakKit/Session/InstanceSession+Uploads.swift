import Foundation

#if canImport(os)
    import os
#endif

extension InstanceSession {
    /// Progress and failures of this instance's background uploads; nil when the app has no background session.
    public func uploadSummaries() async -> AsyncStream<UploadSummary>? {
        guard let link = environment.uploads?(instance.id) else { return nil }
        return await link.ledger.summaries()
    }

    /// Starts every item whose background upload failed or was lost.
    public func retryFailedUploads() async {
        await outbox()?.retryFailedUploads()
    }
}

// MARK: Share context

extension InstanceSession {
    /// Writes the thread the person opened as the share sheet's last thread. A failure is logged by type.
    public func noteOpenedThread(_ show: ThreadShow) async {
        updateShareContext { $0.lastThread = ShareContextStore.lastThread(from: show) }
    }

    /// Writes the new-thread options the screen loaded, and the repository last used.
    public func noteNewThreadOptions(_ options: NewThreadOptions, lastRepo: String?) async {
        updateShareContext {
            $0.options = options
            if let lastRepo { $0.lastRepo = lastRepo }
        }
    }

    private func updateShareContext(_ change: (inout ShareContext) -> Void) {
        guard !hasEnded, !isUnpairing else { return }
        let files = environment.files
        var context = ShareContextStore.read(instance: instance.id, files: files)
        if let me = me() {
            context =
                ShareContext(
                    instanceName: me.instance.name, limits: ShareLimits(me.attachments),
                    lastThread: context?.lastThread, options: context?.options, lastRepo: context?.lastRepo,
                    updatedAt: clock.now())
        }
        guard var updated = context else {
            logShareContext("share context skipped: no stored context and no profile yet")
            return
        }
        change(&updated)
        updated.updatedAt = clock.now()
        do { try ShareContextStore.write(updated, instance: instance.id, files: files) } catch {
            logShareContext("share context not written: \(String(describing: type(of: error)))")
        }
    }

    private func logShareContext(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "YakKit", category: "ShareContext").error("\(message, privacy: .public)")
        #endif
    }
}
