import Foundation
import Testing

@testable import YakKit

private let storeKey = "instances.v1"

private func address(_ index: Int) -> URL { URL(string: "https://yak\(index).example.com")! }

private func makeStore(_ defaults: KeyValueStore = InMemoryKeyValueStore()) -> InstanceStore {
    InstanceStore(
        defaults: defaults, tokens: InMemoryTokenStore(),
        now: { Date(timeIntervalSince1970: 1_000) })
}

/// A stored list as an older build or a hostile writer would leave it: `tints` replaces each entry's `tint` key,
/// where `nil` leaves the key out and `NSNull` writes `null`.
private func storedList(_ tints: [Any?]) throws -> Data {
    let instances = tints.indices.map {
        Instance(
            url: address($0), name: "Instance \($0)", userName: "Test person",
            lastUsedAt: Date(timeIntervalSince1970: 1_000))
    }
    let encoded = try JSONSerialization.jsonObject(with: JSONEncoder().encode(instances))
    var objects = try #require(encoded as? [[String: Any]])
    for (index, tint) in tints.enumerated() {
        objects[index]["tint"] = tint
    }
    return try JSONSerialization.data(withJSONObject: objects)
}

@Suite struct InstanceTintTests {
    @Test func sixInstancesGetSixTints() async throws {
        let store = makeStore()
        for index in 0..<6 {
            try await store.add(url: address(index), name: "A", userName: "U", token: "t")
        }
        let tints = await store.all().sorted { $0.url.absoluteString < $1.url.absoluteString }.map(\.tint)
        #expect(tints == [.slate, .green, .blue, .purple, .teal, .gold])
    }

    @Test func aSeventhRepeatsByItsStableIndex() async throws {
        let store = makeStore()
        for index in 0..<6 {
            try await store.add(url: address(index), name: "A", userName: "U", token: "t")
        }
        let seventh = try await store.add(url: address(6), name: "A", userName: "U", token: "t")
        #expect(seventh.tint == InstanceTint.palette[InstanceTint.stableIndex(of: seventh.id)])
    }

    @Test func theStableIndexIsTheSameInEveryProcess() throws {
        // Computed offline with FNV-1a 64; a seeded hash would not match.
        let first = try #require(UUID(uuidString: "00000000-0000-0000-0000-000000000001"))
        let second = try #require(UUID(uuidString: "7B2E4C1A-9D3F-4E8B-A5C6-0F1E2D3C4B5A"))
        #expect(InstanceTint.stableIndex(of: first) == 2)
        #expect(InstanceTint.stableIndex(of: second) == 1)
    }

    @Test func aStoredTintNeverChanges() async throws {
        let defaults = InMemoryKeyValueStore()
        let store = makeStore(defaults)
        let first = try await store.add(url: address(0), name: "A", userName: "U", token: "t")
        let second = try await store.add(url: address(1), name: "A", userName: "U", token: "t")
        let third = try await store.add(url: address(2), name: "A", userName: "U", token: "t")
        try await store.remove(first.id)
        let reloaded = makeStore(defaults)
        let all = await reloaded.all()
        #expect(all.first { $0.id == second.id }?.tint == .green)
        #expect(all.first { $0.id == third.id }?.tint == .blue)
        let fourth = try await reloaded.add(url: address(3), name: "A", userName: "U", token: "t")
        #expect(fourth.tint == .slate)
    }

    @Test func repairingKeepsTheTint() async throws {
        let store = makeStore()
        _ = try await store.add(url: address(0), name: "A", userName: "U", token: "t")
        let second = try await store.add(url: address(1), name: "A", userName: "U", token: "t")
        let again = try await store.add(url: address(1), name: "B", userName: "U", token: "t2")
        #expect(again.id == second.id)
        #expect(again.tint == .green)
    }

    @Test func aListWithoutTintsGetsThemInStoredOrder() async throws {
        let data = try storedList([nil, nil, nil])
        let first = makeStore(DroppingKeyValueStore(seed: [storeKey: data]))
        let second = makeStore(DroppingKeyValueStore(seed: [storeKey: data]))
        let tints = await first.all().sorted { $0.name < $1.name }.map(\.tint)
        #expect(tints == [.slate, .green, .blue])
        #expect(await second.all().sorted { $0.name < $1.name }.map(\.tint) == tints)
    }

    @Test func loadingWritesNothingUntilTheAppPersists() async throws {
        let defaults = InMemoryKeyValueStore()
        let data = try storedList([nil, nil, nil])
        defaults.set(data, forKey: storeKey)
        let writesBefore = defaults.writeCount
        let store = makeStore(defaults)
        #expect(defaults.writeCount == writesBefore)
        #expect(defaults.data(forKey: storeKey) == data)

        try await store.persistMissingTints()
        #expect(defaults.writeCount == writesBefore + 1)
        let fresh = makeStore(defaults)
        #expect(await fresh.all().sorted { $0.name < $1.name }.map(\.tint) == [.slate, .green, .blue])

        try await store.persistMissingTints()
        #expect(defaults.writeCount == writesBefore + 1)
    }

    @Test(arguments: ["\"magenta\"", "\"\"", "5", "{}", "[]", "null"])
    func anUnknownTintIsReassignedAndTheListSurvives(oddJSON: String) async throws {
        let odd = try JSONSerialization.jsonObject(with: Data(oddJSON.utf8), options: .fragmentsAllowed)
        let data = try storedList(["slate", odd, "blue"])
        let store = makeStore(DroppingKeyValueStore(seed: [storeKey: data]))
        let all = await store.all().sorted { $0.name < $1.name }
        #expect(all.count == 3)
        #expect(all[0].tint == .slate)
        #expect(all[2].tint == .blue)
        let reassigned = try #require(all[1].tint)
        #expect(reassigned.isKnown)
        #expect(reassigned != .slate && reassigned != .blue)
    }

    @Test func aStoreThatLosesWritesStillTints() async throws {
        let data = try storedList([nil, nil])
        let defaults = DroppingKeyValueStore(seed: [storeKey: data])
        let store = makeStore(defaults)
        try await store.persistMissingTints()
        #expect(await store.all().sorted { $0.name < $1.name }.map(\.tint) == [.slate, .green])
        let fresh = makeStore(defaults)
        #expect(await fresh.all().sorted { $0.name < $1.name }.map(\.tint) == [.slate, .green])
    }
}

@Suite struct InstanceInitialTests {
    @Test(
        arguments: [
            ("Example Yak", nil, "E"),
            ("  yak", nil, "Y"),
            ("🚀 Rocket", nil, "R"),
            ("42 things", nil, "4"),
            ("", "staging.example.com", "S"),
            ("🚀🚀🚀", nil, "Y"),
            ("\u{200F}\u{200D}  zed", nil, "Z"),
        ] as [(String, String?, String)])
    func makesTheInitial(name: String, host: String?, expected: String) {
        #expect(InstanceInitial.make(name: name, host: host) == expected)
    }

    @Test func aSharpSIsOneCharacter() {
        #expect(InstanceInitial.make(name: "ßeta", host: nil).count == 1)
    }

    @Test func onlyTheFirstSixtyFourCharactersAreRead() {
        let name = String(repeating: "-", count: 64) + "Z" + String(repeating: "-", count: 100_000)
        #expect(InstanceInitial.make(name: name, host: "staging.example.com") == "S")
    }

    @Test func aGraphemeOfManyScalarsIsSkipped() {
        let name = "a" + String(repeating: "\u{0301}", count: 500) + " b"
        #expect(InstanceInitial.make(name: name, host: nil) == "B")
    }
}
