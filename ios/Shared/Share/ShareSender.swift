import Foundation
import YakKit

#if canImport(os)
    import os
#endif

enum ShareSendProblem: Error, Equatable, Sendable { case notSaved }

protocol ShareSending: Sendable {
    /// Moves the staged images into the item's folder, prepares the record, starts the upload, then writes the inbox
    /// item. The inbox item comes last, so the app never sees an item without its record.
    func send(_ draft: OutboxDraft, stagingFolder: String, instance: UUID, token: String) async throws(ShareSendProblem)
}

/// The share sheet's sender: its own background session, the ledger of the instance and the app's inbox.
struct ShareSender: ShareSending {
    let files: DiskFileStore
    let appBundleID: String
    let appGroup: String?
    let clock: any YakClock
    let makeClient: @Sendable (_ instance: UUID, _ token: String) async -> APIClient?

    func send(_ draft: OutboxDraft, stagingFolder: String, instance: UUID, token: String) async throws(ShareSendProblem)
    {
        let now = clock.now()
        guard let key = draft.sendKey else { throw .notSaved }
        do {
            for attachment in draft.attachments {
                try files.move("\(stagingFolder)/\(attachment.fileName)", to: attachment.path)
            }
        } catch {
            log("staged files not moved: \(type(of: error))")
            throw .notSaved
        }
        if !draft.attachments.isEmpty {
            let ledger = UploadLedger(instance: instance, files: files, clock: clock)
            let item = OutboxInbox.item(for: draft, id: key, now: now)
            do { _ = try await ledger.prepare(item, origin: .share) } catch {
                log("upload not prepared: \(type(of: error))")
                throw .notSaved
            }
            let identifier = UploadSessionID.share(invocation: UUID()).identifier(appBundleID: appBundleID)
            let session = BackgroundUploadSession(identifier: identifier, appGroup: appGroup, files: files) { _ in
                ledger
            }
            if let client = await makeClient(instance, token) {
                _ = await ledger.start(key, session: session, client: client)
            }
        }
        do { try OutboxInbox.add(draft, instance: instance, files: files, id: key, now: now) } catch {
            // The upload, if it started, still delivers; the app drops the answered record of an unknown item.
            log("inbox item not written: \(type(of: error))")
            throw .notSaved
        }
    }

    private func log(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Share", category: "Sender").error("\(message, privacy: .public)")
        #endif
    }
}
