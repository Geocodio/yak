/// Whether this person's account may use a repository as an extra one on a new thread.
public struct RepositoryAccess: Codable, Equatable, Sendable {
    public let allowed: Bool
    public let reason: String?

    public init(allowed: Bool, reason: String? = nil) {
        self.allowed = allowed
        self.reason = reason
    }
}
