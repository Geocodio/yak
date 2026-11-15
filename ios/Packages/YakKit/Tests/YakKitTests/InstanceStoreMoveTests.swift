import Foundation
import Testing

@testable import YakKit

private let address = URL(string: "https://yak.example.com")!
private let otherAddress = URL(string: "https://other.example.com")!

@Suite struct InstanceStoreMoveTests {
    private func seededOld() async throws -> (InMemoryKeyValueStore, UUID) {
        let old = InMemoryKeyValueStore()
        let store = InstanceStore(defaults: old, tokens: InMemoryTokenStore(), now: { Date(timeIntervalSince1970: 1) })
        try await store.add(url: address, name: "One", userName: "Test person", token: "a")
        let second = try await store.add(url: otherAddress, name: "Two", userName: "Test person", token: "b")
        return (old, second.id)
    }

    @Test func theListMovesOnce() async throws {
        let (old, activeID) = try await seededOld()
        let new = InMemoryKeyValueStore()
        #expect(InstanceStore.moveStoredList(from: old, to: new))
        #expect(old.data(forKey: "instances.v1") == nil)
        #expect(old.data(forKey: "instances.active") == nil)
        let moved = InstanceStore(defaults: new, tokens: InMemoryTokenStore(), now: { Date() })
        #expect(await moved.all().count == 2)
        #expect(await moved.active()?.id == activeID)
        let writes = new.writeCount
        let oldWrites = old.writeCount
        #expect(!InstanceStore.moveStoredList(from: old, to: new))
        #expect(new.writeCount == writes)
        #expect(old.writeCount == oldWrites)
    }

    @Test func aListAlreadyInTheNewStoreWins() async throws {
        let (old, _) = try await seededOld()
        let new = InMemoryKeyValueStore()
        new.set(Data("[]".utf8), forKey: "instances.v1")
        let before = old.data(forKey: "instances.v1")
        #expect(!InstanceStore.moveStoredList(from: old, to: new))
        #expect(old.data(forKey: "instances.v1") == before)
        #expect(new.data(forKey: "instances.v1") == Data("[]".utf8))
    }

    @Test func aListThatDoesNotDecodeStays() {
        let old = InMemoryKeyValueStore()
        old.set(Data("garbage".utf8), forKey: "instances.v1")
        let new = InMemoryKeyValueStore()
        #expect(!InstanceStore.moveStoredList(from: old, to: new))
        #expect(old.data(forKey: "instances.v1") == Data("garbage".utf8))
        #expect(new.writeCount == 0)
    }

    @Test func anEmptyOldStoreMovesNothing() {
        let old = InMemoryKeyValueStore()
        let new = InMemoryKeyValueStore()
        #expect(!InstanceStore.moveStoredList(from: old, to: new))
        #expect(new.writeCount == 0 && old.writeCount == 0)
    }
}
