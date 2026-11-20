import Foundation

extension InstanceSession {
    /// Mints a one-time code for the embedded thread page; nil while the session is not active.
    public func webSession(path: String) async throws(APIFailure) -> WebSession? {
        guard let client = apiClient() else { return nil }
        return try await client.send(.webSession(path: path))
    }
}
