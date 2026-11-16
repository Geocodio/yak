import Foundation
import Testing

@testable import YakKit

private let build = PushBuild(bundleID: "com.example.yak", environment: .production)

private func pushState(apns: Bool, bundleID: String?, environment: String?) throws -> Me.PushState {
    var object: [String: Any] = ["apns": apns]
    object["bundleId"] = bundleID
    object["environment"] = environment
    return try JSONDecoder().decode(Me.PushState.self, from: JSONSerialization.data(withJSONObject: object))
}

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private func me(url: String? = nil, bundleID: String = "com.example.yak") throws -> Me {
    var data = try Scenarios.data("scenario-me", setting: ["push", "bundleId"], toJSON: "\"\(bundleID)\"")
    if let url {
        data = try Scenarios.data("scenario-me", setting: ["instance", "url"], toJSON: "\"\(url)\"")
    }
    return try JSONDecoder().decode(Me.self, from: data)
}

private func store(build: PushBuild?) -> InstanceStore {
    InstanceStore(
        defaults: MemoryDefaults(), tokens: InMemoryTokenStore(), now: { Date(timeIntervalSince1970: 1_000) },
        build: build)
}

@Suite struct PushAvailabilityTests {
    @Test(
        arguments: [
            (true, "com.example.yak", "production", .available),
            (false, "com.example.yak", "production", .noKey),
            (true, "com.example.other", "production", .otherBuild),
            (true, nil, "production", .otherBuild),
            (true, "com.example.yak", "sandbox", .otherEnvironment(instance: .sandbox)),
            (true, "com.example.yak", "staging", .otherEnvironment(instance: nil)),
            (true, "com.example.yak", nil, .available),
        ] as [(Bool, String?, String?, PushAvailability)])
    func availabilityComparesWithThisBuild(
        apns: Bool, bundleID: String?, environment: String?, expected: PushAvailability
    ) throws {
        #expect(
            PushAvailability.make(try pushState(apns: apns, bundleID: bundleID, environment: environment), build: build)
                == expected)
    }

    @Test func withoutABuildOnlyTheKeyCounts() throws {
        #expect(
            PushAvailability.make(try pushState(apns: true, bundleID: "anything", environment: "sandbox"), build: nil)
                == .available)
        #expect(
            PushAvailability.make(try pushState(apns: false, bundleID: nil, environment: nil), build: nil) == .noKey)
    }

    @Test func theStoreKeepsThePushStateFromMe() async throws {
        let other = store(build: PushBuild(bundleID: "com.example.other", environment: .production))
        let first = try await other.add(
            url: URL(string: "https://yak.example.com")!, name: "n", userName: "u", token: "t")
        try await other.update(first.id, from: try me())
        let stored = try #require(await other.all().first)
        #expect(stored.push == .otherBuild && stored.pushAvailable == false)

        let same = store(build: build)
        let second = try await same.add(
            url: URL(string: "https://yak.example.com")!, name: "n", userName: "u", token: "t")
        try await same.update(second.id, from: try me())
        let matching = try #require(await same.all().first)
        #expect(matching.push == .available && matching.pushAvailable)
    }

    @Test func theStoreKeepsTheServersAddress() async throws {
        let instances = store(build: nil)
        let added = try await instances.add(
            url: URL(string: "https://yak.example.com")!, name: "n", userName: "u", token: "t")
        for (reported, expected) in [
            ("https://yak.example.com/", "https://yak.example.com"),
            ("https://alias.example.com/yak", "https://alias.example.com"),
            ("not a url", nil),
        ] as [(String, String?)] {
            try await instances.update(added.id, from: try me(url: reported))
            let stored = try #require(await instances.all().first)
            #expect(stored.canonicalURL?.absoluteString == expected)
            #expect(stored.url.absoluteString == "https://yak.example.com")
        }
    }

    @Test func anOldInstanceListStillDecodes() throws {
        let json = """
            [{"id":"6F0C3A62-0000-4000-8000-000000000001","url":"https://yak.example.com","name":"n","userName":"u",\
            "needsYouCount":1,"pushAvailable":true,"lastUsedAt":0}]
            """
        let decoded = try JSONDecoder().decode([Instance].self, from: Data(json.utf8))
        #expect(
            decoded.first?.push == nil && decoded.first?.canonicalURL == nil && decoded.first?.pushAvailable == true)
    }
}
