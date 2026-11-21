import Foundation

extension InstanceSession {
    /// Mints a one-time code for the embedded thread page; nil while the session is not active.
    public func webSession(path: String) async throws(APIFailure) -> WebSession? {
        guard let client = apiClient() else { return nil }
        return try await client.send(.webSession(path: path))
    }

    /// True only in the app's Debug test mode, where the embedded page may be on plain http at 127.0.0.1.
    public nonisolated var allowsLoopbackHTTP: Bool { environment.allowsInsecureRealtime }

    /// The app's version, which the embedded page's user agent names.
    public nonisolated var appVersion: String { environment.appVersion }
}
