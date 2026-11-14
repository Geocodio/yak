import Foundation
import Testing
@testable import YakKit

private final class InMemoryKeyValueStore: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private final class Clock: @unchecked Sendable {
    private let lock = NSLock()
    private var current = Date(timeIntervalSince1970: 1_000)
    func now() -> Date { lock.withLock { current } }
    func advance() { lock.withLock { current += 60 } }
}

private let address = URL(string: "https://yak.example.com")!
private let otherAddress = URL(string: "https://other.example.com")!

private func makeStore(
    defaults: KeyValueStore = InMemoryKeyValueStore(),
    tokens: TokenStore = InMemoryTokenStore(),
    clock: Clock = Clock()
) -> InstanceStore {
    InstanceStore(defaults: defaults, tokens: tokens, now: { clock.now() })
}

@Suite struct InstanceStoreTests {
    @Test func addingTheSameAddressTwiceReplacesTheEntry() async throws {
        let tokens = InMemoryTokenStore()
        let store = makeStore(tokens: tokens)
        _ = try await store.add(url: address, name: "A", userName: "U", token: "yakdev_1")
        let second = try await store.add(url: address, name: "A", userName: "U", token: "yakdev_2")
        #expect(await store.all().count == 1)
        #expect(try tokens.token(for: second.id) == "yakdev_2")
    }

    @Test func anInstanceWithoutATokenNeedsPairing() async throws {
        let tokens = InMemoryTokenStore()
        let store = makeStore(tokens: tokens)
        let instance = try await store.add(url: address, name: "A", userName: "U", token: "yakdev_1")
        #expect(await store.state(of: instance.id) == .ready)
        try tokens.removeToken(for: instance.id)
        #expect(await store.state(of: instance.id) == .needsPairing)
        #expect(await store.all().count == 1)
    }

    @Test func markRevokedKeepsTheEntryAndDropsTheToken() async throws {
        let tokens = InMemoryTokenStore()
        let store = makeStore(tokens: tokens)
        let instance = try await store.add(url: address, name: "A", userName: "U", token: "yakdev_1")
        try await store.markRevoked(instance.id)
        #expect(try tokens.token(for: instance.id) == nil)
        #expect(await store.all().map(\.id) == [instance.id])
        #expect(await store.state(of: instance.id) == .needsPairing)
    }

    @Test func removeDropsTheEntryAndTheToken() async throws {
        let tokens = InMemoryTokenStore()
        let store = makeStore(tokens: tokens)
        let instance = try await store.add(url: address, name: "A", userName: "U", token: "yakdev_1")
        try await store.remove(instance.id)
        #expect(await store.all().isEmpty)
        #expect(await store.active() == nil)
        #expect(try tokens.token(for: instance.id) == nil)
    }

    @Test func ordersByLastUse() async throws {
        let clock = Clock()
        let store = makeStore(clock: clock)
        let first = try await store.add(url: address, name: "A", userName: "U", token: "t1")
        clock.advance()
        let second = try await store.add(url: otherAddress, name: "B", userName: "U", token: "t2")
        #expect(await store.all().map(\.id) == [second.id, first.id])
        clock.advance()
        try await store.setActive(first.id)
        #expect(await store.all().map(\.id) == [first.id, second.id])
        #expect(await store.active()?.id == first.id)
    }

    @Test func survivesANewStoreOnTheSameDefaults() async throws {
        let defaults = InMemoryKeyValueStore()
        let tokens = InMemoryTokenStore()
        let first = makeStore(defaults: defaults, tokens: tokens)
        let instance = try await first.add(url: address, name: "A", userName: "U", token: "t1")
        let second = makeStore(defaults: defaults, tokens: tokens)
        #expect(await second.all() == [instance])
        #expect(await second.active()?.id == instance.id)
    }

    @Test func unknownInstancesAreRefused() async throws {
        let store = makeStore()
        await #expect(throws: InstanceStoreError.unknownInstance) {
            try await store.setActive(UUID())
        }
    }

    @Test func corruptStoredDataStartsEmpty() async throws {
        let defaults = InMemoryKeyValueStore()
        defaults.set(Data("not json".utf8), forKey: "instances.v1")
        #expect(await makeStore(defaults: defaults).all().isEmpty)
    }
}
