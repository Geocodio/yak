import Foundation
import Testing
import YakKit

@testable import Yak

@Suite struct FocusFilterTests {
    private let home = Instance(
        url: URL(string: "https://Yak.Example.com")!, name: "Example Yak", userName: "Test person",
        lastUsedAt: Date(), canonicalURL: URL(string: "https://alias.example.com")!)
    private let other = Instance(
        url: URL(string: "https://other.example.com")!, name: "Other Yak", userName: "Test person", lastUsedAt: Date())

    @Test func theContextAllowsTheChosenHosts() throws {
        let predicate = try #require(YakFocusFilter.predicate(for: [InstanceEntity(home)]))
        #expect(predicate.evaluate(with: "yak.example.com"))
        #expect(predicate.evaluate(with: "alias.example.com"))
        #expect(!predicate.evaluate(with: "other.example.com"))
    }

    @Test func aChosenInstanceThatNeedsPairingIsStillResolved() async throws {
        let tokens = InMemoryTokenStore()
        let store = InstanceStore(defaults: FocusMemoryDefaults(), tokens: tokens, now: { Date() })
        let added = try await store.add(url: home.url, name: "Example Yak", userName: "Test person", token: "t")
        try tokens.removeToken(for: added.id)
        #expect(await store.state(of: added.id) == .needsPairing)
        let found = await InstanceQuery().entities(for: [added.id], in: store)
        #expect(found.map(\.id) == [added.id])
    }

    @Test func noChoiceHasNoPredicate() {
        #expect(YakFocusFilter.predicate(for: nil) == nil)
    }

    @Test func anEmptyChoiceLetsNothingThrough() throws {
        let predicate = try #require(YakFocusFilter.predicate(for: []))
        #expect(!predicate.evaluate(with: "yak.example.com"))
    }

    @Test func theDescriptionNamesInstancesOnly() {
        #expect(YakFocusFilter.summary(names: nil) == "All Yaks")
        #expect(YakFocusFilter.summary(names: []) == "No Yaks")
        #expect(YakFocusFilter.summary(names: ["Example Yak"]) == "Example Yak")
        #expect(YakFocusFilter.summary(names: ["Example Yak", "Other Yak"]) == "Example Yak and Other Yak")
        #expect(YakFocusFilter.summary(names: ["Example Yak", "Other Yak", "Third Yak"]) == "Example Yak and 2 more")
    }
}

private final class FocusMemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}
