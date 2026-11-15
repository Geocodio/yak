import Foundation

extension InstanceSession {
    /// A new preview session for one pane; nil unless the session is active. The caller closes it.
    public func openPreview(_ threadID: String) async -> PreviewSession? {
        guard let client = apiClient() else { return nil }
        return PreviewSession(
            threadID: threadID, client: client, clock: environment.clock,
            allowsLoopbackHTTP: environment.allowsInsecureRealtime)
    }
}
