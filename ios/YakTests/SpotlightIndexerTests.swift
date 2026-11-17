import CoreSpotlight
import Foundation
import Testing
import YakKit

@testable import Yak

private final class Store: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct Allowing: DeviceOwnerAuthenticator {
    func capability() async -> CapabilityReading { .known(LockCapability(biometry: .faceID, hasPasscode: true)) }
    func authenticate(reason: String) async -> DeviceOwnerAuthenticationResult { .success }
    func cancel() {}
}

private final class RecordingWriter: SpotlightWriter, @unchecked Sendable {
    private let lock = NSLock()
    private var applied: [SpotlightChange] = []
    private var held: CheckedContinuation<Void, Never>?
    var failsErase = false
    var holdsFirst = false
    var clock: (any YakClock)?
    var probe: (@Sendable () -> SpotlightIndexRecord)?
    private(set) var probed: [SpotlightIndexRecord] = []

    var changes: [SpotlightChange] { lock.withLock { applied } }
    var isHolding: Bool { lock.withLock { held != nil } }
    func clear() { lock.withLock { applied = [] } }

    struct Refused: Error {}

    func apply(_ change: SpotlightChange) async throws {
        if let probe { lock.withLock { probed.append(probe()) } }
        let hold = lock.withLock { () -> Bool in
            applied.append(change)
            defer { holdsFirst = false }
            return holdsFirst
        }
        if hold { await withCheckedContinuation { continuation in lock.withLock { held = continuation } } }
        if let clock { try await clock.sleep(for: .seconds(3600)) }
        if failsErase, case .erase = change { throw Refused() }
    }

    func release() {
        let continuation = lock.withLock { () -> CheckedContinuation<Void, Never>? in
            defer { held = nil }
            return held
        }
        continuation?.resume()
    }
}

@MainActor private final class Fixture {
    let store = Store()
    let writer = RecordingWriter()
    let clock = ManualClock()
    let settings: PrivacySettings
    let appLock: AppLockController
    let instances: InstanceStore
    var active: Instance?
    var lists: [ThreadList] = []
    let first: Instance
    let second: Instance

    init() async throws {
        settings = PrivacySettings(store: Store())
        // A store that holds no record at all means "unknown", which erases everything first.
        store.set(SpotlightIndexRecord.empty.encoded(), forKey: SpotlightIndexer.recordKey)
        appLock = AppLockController(settings: settings, authenticator: Allowing(), clock: clock)
        instances = InstanceStore(defaults: Store(), tokens: InMemoryTokenStore(), now: { Date() })
        first = try await instances.add(
            url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test Person", token: "t1")
        second = try await instances.add(
            url: URL(string: "https://other.example.com")!, name: "Other Yak", userName: "Test Person", token: "t2")
        try await instances.setActive(first.id)
        active = first
        lists = [try JSONDecoder().decode(ThreadList.self, from: ScenarioFile.data("scenario-thread-list"))]
    }

    func makeIndexer() -> SpotlightIndexer {
        SpotlightIndexer(
            writer: writer, settings: settings, appLock: appLock, store: store, instances: instances,
            activeLists: { [self] in
                guard let active else { return nil }
                return (SpotlightInstance(id: active.id, name: active.name), lists)
            }, clock: clock)
    }

    var record: SpotlightIndexRecord { SpotlightIndexRecord.decode(store.data(forKey: SpotlightIndexer.recordKey)) }
}

private func titles(_ change: SpotlightChange) -> [String] {
    guard case .replace(_, let entries) = change else { return [] }
    return entries.map(\.title)
}

@MainActor @Suite struct SpotlightIndexerTests {
    @Test func indexesTheActiveInstanceWithoutPrivateTitles() async throws {
        let fixture = try await Fixture()
        await fixture.makeIndexer().reindex()
        let changes = fixture.writer.changes
        #expect(changes.count == 1)
        guard case .replace(let domain, let entries) = try #require(changes.first) else {
            Issue.record("expected a replace")
            return
        }
        #expect(domain == fixture.first.id)
        #expect(entries.map(\.identifier) == ["\(fixture.first.id.uuidString)/d0e515ef"])
        #expect(entries.first?.instanceName == "Example Yak")
    }

    @Test func aRevokeErasesTheDomainAndARelaunchFinishesIt() async throws {
        let fixture = try await Fixture()
        let indexer = fixture.makeIndexer()
        await indexer.reindex()
        fixture.writer.failsErase = true
        try await fixture.instances.markRevoked(fixture.first.id)
        await indexer.instanceRemoved(fixture.first, reason: .revoked)
        #expect(fixture.record.pendingErasures.contains(fixture.first.id))
        fixture.writer.failsErase = false
        fixture.writer.clear()
        fixture.active = nil
        await fixture.makeIndexer().reindex()
        #expect(fixture.writer.changes == [.erase(domains: [fixture.first.id])])
        #expect(fixture.record.pendingErasures.isEmpty)
    }

    @Test func aRevokedInstanceIsNotPaired() async throws {
        let fixture = try await Fixture()
        let indexer = fixture.makeIndexer()
        await indexer.reindex()
        fixture.writer.clear()
        try await fixture.instances.markRevoked(fixture.first.id)
        await indexer.reindex()
        let changes = fixture.writer.changes
        #expect(changes == [.erase(domains: [fixture.first.id])])
        #expect(!changes.contains { titles($0).isEmpty == false })
    }

    @Test func theLockErasesPrivateTitles() async throws {
        let fixture = try await Fixture()
        fixture.settings.spotlightIncludesPrivate = true
        let indexer = fixture.makeIndexer()
        await indexer.reindex()
        #expect(titles(fixture.writer.changes[0]).count == 3)
        #expect(fixture.record.includedPrivate)
        fixture.writer.clear()

        await fixture.appLock.refreshCapability()
        await fixture.appLock.setOn(true)
        await indexer.reindex()
        let locked = fixture.writer.changes
        #expect(locked.first == .eraseAll)
        #expect(titles(locked[1]) == ["Retry failed rows when the import times out"])
        fixture.writer.clear()

        await indexer.reindex()
        #expect(fixture.writer.changes.count == 1)
        #expect(titles(fixture.writer.changes[0]).count == 1)
        fixture.writer.clear()

        await fixture.appLock.setOn(false)
        await indexer.reindex()
        #expect(fixture.writer.changes.count == 1)
        #expect(titles(fixture.writer.changes[0]).count == 3)
    }

    @Test func turningThePrivateSwitchOffErasesFirst() async throws {
        let fixture = try await Fixture()
        fixture.settings.spotlightIncludesPrivate = true
        let indexer = fixture.makeIndexer()
        await indexer.reindex()
        fixture.writer.clear()
        fixture.settings.spotlightIncludesPrivate = false
        await indexer.reindex()
        #expect(fixture.writer.changes.first == .eraseAll)
        #expect(titles(fixture.writer.changes[1]).count == 1)
    }

    @Test func aSlowIndexIsCutAfterTenSeconds() async throws {
        let fixture = try await Fixture()
        fixture.writer.clock = fixture.clock
        let indexer = fixture.makeIndexer()
        let run = Task { await indexer.reindex() }
        while fixture.clock.sleeperCount(waking: .seconds(10)) == 0 { await Task.yield() }
        fixture.clock.advance(.seconds(10))
        await run.value
        #expect(indexer.state == .failed)
        #expect(fixture.record.pendingErasures.isEmpty)
        fixture.clock.advance(.seconds(4000))
    }

    @Test func aFailingWriterShowsFailed() async throws {
        let fixture = try await Fixture()
        fixture.active = nil
        try await fixture.instances.markRevoked(fixture.first.id)
        fixture.writer.failsErase = true
        let indexer = fixture.makeIndexer()
        await indexer.instanceRemoved(fixture.first, reason: .revoked)
        await indexer.reindex()
        #expect(indexer.state == .failed)
    }

    @Test func runsNeverOverlap() async throws {
        let fixture = try await Fixture()
        fixture.writer.holdsFirst = true
        let indexer = fixture.makeIndexer()
        let first = Task { await indexer.reindex() }
        while !fixture.writer.isHolding { await Task.yield() }
        await indexer.reindex()
        #expect(fixture.writer.changes.count == 1)
        fixture.writer.release()
        await first.value
        #expect(fixture.writer.changes.count == 2)
    }

    @Test func theWriteIsRecordedBeforeItHappens() async throws {
        let fixture = try await Fixture()
        fixture.settings.spotlightIncludesPrivate = true
        let store = fixture.store
        fixture.writer.probe = {
            SpotlightIndexRecord.decode(store.data(forKey: SpotlightIndexer.recordKey))
        }
        await fixture.makeIndexer().reindex()
        let seen = try #require(fixture.writer.probed.first)
        #expect(seen.indexedDomains.contains(fixture.first.id))
        #expect(seen.includedPrivate)
    }

    @Test func continuingAnItemOpensItsThreadLink() async throws {
        let fixture = try await Fixture()
        let indexer = fixture.makeIndexer()
        let activity = NSUserActivity(activityType: CSSearchableItemActionType)
        activity.userInfo = [CSSearchableItemActivityIdentifier: "\(fixture.first.id.uuidString)/d0e515ef"]
        let url = try #require(await indexer.link(for: activity))
        let items = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems ?? []
        #expect(items.first { $0.name == "id" }?.value == "d0e515ef")
        #expect(items.first { $0.name == "instance" }?.value == "https://yak.example.com")
        #expect(
            DeepLink(url: url) == .thread(instance: URL(string: "https://yak.example.com")!, id: "d0e515ef"))

        let hostile = NSUserActivity(activityType: CSSearchableItemActionType)
        hostile.userInfo = [CSSearchableItemActivityIdentifier: "../../x"]
        #expect(await indexer.link(for: hostile) == nil)
        #expect(await indexer.link(for: NSUserActivity(activityType: "other")) == nil)
    }
}
