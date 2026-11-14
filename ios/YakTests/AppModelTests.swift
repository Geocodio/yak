import Foundation
import Testing
import YakKit

@testable import Yak

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

/// A token store whose reads can start failing, like a locked Keychain.
private final class LockableTokenStore: TokenStore, @unchecked Sendable {
    struct Locked: Error {}
    let inner = InMemoryTokenStore()
    private let lock = NSLock()
    private var isLocked = false

    func lockReads() { lock.withLock { isLocked = true } }
    func token(for instance: UUID) throws -> String? {
        if lock.withLock({ isLocked }) { throw Locked() }
        return try inner.token(for: instance)
    }
    func setToken(_ token: String, for instance: UUID) throws { try inner.setToken(token, for: instance) }
    func removeToken(for instance: UUID) throws { try inner.removeToken(for: instance) }
}

@MainActor @Test func aTokenReadErrorKeepsTheTokenAndShowsFailure() async throws {
    let tokens = LockableTokenStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
    let instance = try await instances.add(
        url: URL(string: "https://yak.example.com")!, name: "Acme", userName: "Ada", token: "secret")
    let model = AppModel(
        instances: instances, tokens: tokens,
        makeClient: { _ in
            Issue.record("no request expected")
            fatalError()
        })
    tokens.lockReads()
    await model.refreshMe(for: instance)
    #expect(model.refreshState == .failed)
    #expect(await instances.all().count == 1)
    #expect(try tokens.inner.token(for: instance.id) == "secret")
}
