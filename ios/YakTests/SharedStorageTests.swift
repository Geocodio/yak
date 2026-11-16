import Foundation
import Security
import Testing
import YakKit

@testable import Yak

/// A defaults suite that is removed when the test ends.
private struct TemporarySuite {
    let name = "group.com.example.yak.tests.\(UUID().uuidString)"

    func info(bundleID: String? = "com.example.yak") -> [String: Any] {
        var info: [String: Any] = [SharedStorage.appGroupKey: name]
        if let bundleID { info[SharedStorage.appBundleIDKey] = bundleID }
        return info
    }

    func cleanUp() { UserDefaults().removePersistentDomain(forName: name) }
}

private let container = FileManager.default.temporaryDirectory

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

@Suite struct SharedStorageTests {
    @Test func missingInfoKeysOpenNothing() {
        let suite = TemporarySuite()
        defer { suite.cleanUp() }
        #expect(SharedStorage.open(infoDictionary: nil, container: { _ in container }) == nil)
        #expect(SharedStorage.open(infoDictionary: [:], container: { _ in container }) == nil)
        #expect(SharedStorage.open(infoDictionary: suite.info(bundleID: nil), container: { _ in container }) == nil)
        #expect(
            SharedStorage.open(infoDictionary: [SharedStorage.appBundleIDKey: "com.example.yak"]) == nil,
            "a bundle without the group opens nothing")
        #expect(SharedStorage.open(infoDictionary: suite.info(), container: { _ in nil }) == nil)
    }

    @Test func anOpenedStorageUsesTheAppGroupDefaults() async throws {
        let suite = TemporarySuite()
        defer { suite.cleanUp() }
        let first = try #require(SharedStorage.open(infoDictionary: suite.info(), container: { _ in container }))
        let second = try #require(SharedStorage.open(infoDictionary: suite.info(), container: { _ in container }))
        #expect(first.appGroup == suite.name && first.appBundleID == "com.example.yak")
        let tokens = InMemoryTokenStore()
        let writer = InstanceStore(defaults: first.defaults, tokens: tokens, now: { Date() })
        let added = try await writer.add(
            url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test Person", token: "t")
        let reader = InstanceStore(defaults: second.defaults, tokens: tokens, now: { Date() })
        #expect(await reader.all().map(\.id) == [added.id])
    }

    @Test func theListMovesIntoTheAppGroupOnce() async throws {
        let suite = TemporarySuite()
        defer { suite.cleanUp() }
        let storage = try #require(SharedStorage.open(infoDictionary: suite.info(), container: { _ in container }))
        let standard = MemoryDefaults()
        let tokens = InMemoryTokenStore()
        let old = InstanceStore(defaults: standard, tokens: tokens, now: { Date() })
        _ = try await old.add(
            url: URL(string: "https://one.example.com")!, name: "One", userName: "Test Person", token: "a")
        _ = try await old.add(
            url: URL(string: "https://two.example.com")!, name: "Two", userName: "Test Person", token: "b")
        #expect(storage.migrateFromStandardStores(from: standard))
        let moved = InstanceStore(defaults: storage.defaults, tokens: tokens, now: { Date() })
        #expect(await moved.all().count == 2)
        #expect(!storage.migrateFromStandardStores(from: standard), "the second launch moves nothing")
        #expect(standard.data(forKey: "instances.v1") == nil)
    }

    @Test func nothingToMoveChangesNothing() throws {
        let suite = TemporarySuite()
        defer { suite.cleanUp() }
        let storage = try #require(SharedStorage.open(infoDictionary: suite.info(), container: { _ in container }))
        #expect(!storage.migrateFromStandardStores(from: MemoryDefaults()))
    }

    /// The Keychain needs the app's entitlements, which the test host has only when it is signed with them.
    @Test(.enabled(if: SharedStorageTests.hostHasKeychain, "The test host has no Keychain entitlement"))
    func aTokenSavedByTheAppIsReadWithoutAGroup() throws {
        let tokens = KeychainTokenStore(accessGroup: nil)
        let id = UUID()
        try tokens.setToken("test-token", for: id)
        defer { try? tokens.removeToken(for: id) }
        #expect(try KeychainTokenStore(accessGroup: nil).token(for: id) == "test-token")
    }

    static var hostHasKeychain: Bool {
        let probe: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: "com.example.yak.probe",
            kSecAttrAccount as String: UUID().uuidString, kSecValueData as String: Data("x".utf8),
        ]
        let status = SecItemAdd(probe as CFDictionary, nil)
        if status == errSecSuccess { SecItemDelete(probe as CFDictionary) }
        return status == errSecSuccess
    }
}
