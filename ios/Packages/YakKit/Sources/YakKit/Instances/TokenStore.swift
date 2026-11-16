import Foundation

public protocol TokenStore: Sendable {
    func token(for instance: UUID) throws -> String?
    func setToken(_ token: String, for instance: UUID) throws
    func removeToken(for instance: UUID) throws
}

/// Tokens held in memory, for tests and previews.
public final class InMemoryTokenStore: TokenStore, @unchecked Sendable {
    private let lock = NSLock()
    private var tokens: [UUID: String] = [:]

    public init() {}

    public func token(for instance: UUID) throws -> String? {
        lock.withLock { tokens[instance] }
    }

    public func setToken(_ token: String, for instance: UUID) throws {
        lock.withLock { tokens[instance] = token }
    }

    public func removeToken(for instance: UUID) throws {
        lock.withLock { tokens[instance] = nil }
    }
}
