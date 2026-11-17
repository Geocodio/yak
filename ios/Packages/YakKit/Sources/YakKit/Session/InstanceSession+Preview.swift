import Foundation

extension InstanceSession {
    /// A new preview session for one pane; nil unless the session is active. The caller closes it.
    public func openPreview(_ threadID: String) async -> PreviewSession? {
        guard let client = apiClient() else { return nil }
        return PreviewSession(
            threadID: threadID, client: client, clock: clock,
            allowsLoopbackHTTP: environment.allowsInsecureRealtime)
    }

    /// A walkthrough video address the player may open for this instance, or nil.
    public nonisolated func playableVideoURL(_ text: String?) -> URL? {
        WalkthroughVideoLink.playable(text, instance: instance, allowsLoopbackHTTP: environment.allowsInsecureRealtime)
    }
}
