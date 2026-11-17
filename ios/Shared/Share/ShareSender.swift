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
        var moved: [OutboxAttachment] = []
        let ledger = UploadLedger(instance: instance, files: files, clock: clock)
        /// Puts the files back into staging and drops the record, so the sheet can send again with everything kept.
        func undo() async {
            for attachment in moved.reversed() {
                do { try files.move(attachment.path, to: "\(stagingFolder)/\(attachment.fileName)") } catch {
                    log("staged file not restored: \(type(of: error))")
                }
            }
            await ledger.remove(key)
        }
        do {
            for attachment in draft.attachments {
                try files.move("\(stagingFolder)/\(attachment.fileName)", to: attachment.path)
                moved.append(attachment)
            }
        } catch {
            log("staged files not moved: \(type(of: error))")
            await undo()
            throw .notSaved
        }
        var didStart = false
        if !draft.attachments.isEmpty {
            let item = OutboxInbox.item(for: draft, id: key, now: now)
            do { _ = try await ledger.prepare(item, origin: .share) } catch {
                log("upload not prepared: \(type(of: error))")
                await undo()
                throw .notSaved
            }
            let identifier = UploadSessionID.share(invocation: UUID()).identifier(appBundleID: appBundleID)
            let session = BackgroundUploadSession(identifier: identifier, appGroup: appGroup, files: files) { _ in
                ledger
            }
            if let client = await makeClient(instance, token) {
                let record = await ledger.start(key, session: session, client: client)
                if case .uploading? = record?.phase { didStart = true }
            }
        }
        do { try OutboxInbox.add(draft, instance: instance, files: files, id: key, now: now) } catch {
            log("inbox item not written: \(type(of: error))")
            // A started upload delivers the message whatever the inbox says, so a second share would duplicate it.
            if !didStart {
                await undo()
                throw .notSaved
            }
        }
    }

    private func log(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Share", category: "Sender").error("\(message, privacy: .public)")
        #endif
    }
}
