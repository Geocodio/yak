import Foundation

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
