import CoreSpotlight
import Foundation
import Testing
import UIKit
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
    private var index: [String: Set<String>] = [:]

    /// What the fake index holds for a domain.
    func entries(in domain: UUID) -> Set<String> { lock.withLock { index[domain.uuidString] ?? [] } }

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
        lock.withLock {
            switch change {
            case .eraseAll: index = [:]
            case .erase(let domains): for domain in domains { index[domain.uuidString] = nil }
            case .replace(let domain, let entries): index[domain.uuidString] = Set(entries.map(\.identifier))
            }
        }
    }

    func release() {
        let continuation = lock.withLock { () -> CheckedContinuation<Void, Never>? in
            defer { held = nil }
            return held
        }
        continuation?.resume()
    }
}

private final class Availability: ProtectedDataAvailability, @unchecked Sendable {
    @MainActor var isAvailable = true
}

@MainActor private final class Fixture {
    let availability = Availability()
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
        settings = PrivacySettings(store: Store(), protectedData: availability)
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
        #expect(fixture.record.pendingEraseAll)
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

    @Test func aRemovalDuringARunLeavesNoItemOfThatInstance() async throws {
        let fixture = try await Fixture()
        fixture.writer.holdsFirst = true
        let indexer = fixture.makeIndexer()
        let run = Task { await indexer.reindex() }
        while !fixture.writer.isHolding { await Task.yield() }
        try await fixture.instances.markRevoked(fixture.first.id)
        let removal = Task { await indexer.instanceRemoved(fixture.first, reason: .revoked) }
        await Task.yield()
        fixture.writer.release()
        await run.value
        await removal.value
        #expect(fixture.writer.entries(in: fixture.first.id).isEmpty)
        #expect(fixture.writer.changes.contains { titles($0).isEmpty == false })
    }

    @Test func aReplaceForADomainThatIsNoLongerPairedIsSkipped() async throws {
        let fixture = try await Fixture()
        fixture.store.set(nil, forKey: SpotlightIndexer.recordKey)
        fixture.writer.holdsFirst = true
        let indexer = fixture.makeIndexer()
        let run = Task { await indexer.reindex() }
        while !fixture.writer.isHolding { await Task.yield() }
        try await fixture.instances.markRevoked(fixture.first.id)
        fixture.writer.release()
        await run.value
        #expect(!fixture.writer.changes.contains { titles($0).isEmpty == false })
        #expect(fixture.writer.entries(in: fixture.first.id).isEmpty)
    }

    @Test func aPendingErasureSurvivesAConcurrentSave() async throws {
        let fixture = try await Fixture()
        fixture.writer.holdsFirst = true
        fixture.writer.failsErase = true
        let indexer = fixture.makeIndexer()
        let run = Task { await indexer.reindex() }
        while !fixture.writer.isHolding { await Task.yield() }
        let removal = Task { await indexer.instanceRemoved(fixture.first, reason: .unpaired) }
        await Task.yield()
        fixture.writer.release()
        await run.value
        await removal.value
        #expect(fixture.record.pendingErasures.contains(fixture.first.id))
    }

    @Test func anErasureNeverWaitsBehindAnAbandonedWriteAndALateLandingIsErased() async throws {
        let fixture = try await Fixture()
        fixture.writer.holdsFirst = true
        let indexer = fixture.makeIndexer()
        let run = Task { await indexer.reindex() }
        while !fixture.writer.isHolding { await Task.yield() }
        while fixture.clock.sleeperCount(waking: .seconds(10)) == 0 { await Task.yield() }
        fixture.clock.advance(.seconds(10))
        await run.value
        #expect(indexer.state == .failed)
        #expect(fixture.record.pendingEraseAll)
        // The abandoned write is still hanging, and the removal completes anyway.
        await indexer.instanceRemoved(fixture.first, reason: .unpaired)
        #expect(fixture.writer.isHolding)
        #expect(fixture.writer.changes.contains(.erase(domains: [fixture.first.id])))
        // The abandoned write ends late and lands; the flag is set again and the rebuild starts with an erase of all.
        fixture.writer.clear()
        fixture.writer.release()
        for _ in 0..<5000 where !fixture.writer.changes.contains(.eraseAll) { await Task.yield() }
        #expect(fixture.writer.changes.first == .eraseAll)
    }

    @Test func aLateErasureStartsNoNewRun() async throws {
        let fixture = try await Fixture()
        var record = fixture.record
        record.pendingEraseAll = true
        fixture.store.set(record.encoded(), forKey: SpotlightIndexer.recordKey)
        fixture.writer.holdsFirst = true
        let indexer = fixture.makeIndexer()
        let run = Task { await indexer.reindex() }
        while !fixture.writer.isHolding { await Task.yield() }
        while fixture.clock.sleeperCount(waking: .seconds(10)) == 0 { await Task.yield() }
        fixture.clock.advance(.seconds(10))
        await run.value
        #expect(fixture.writer.changes == [.eraseAll])
        fixture.writer.release()
        for _ in 0..<2000 { await Task.yield() }
        #expect(fixture.writer.changes == [.eraseAll], "a late erase lands nothing, so nothing is rebuilt or erased again")
    }

    @Test func aSuccessfulEraseAllKeepsTheFlagWhileAnAbandonedReplaceIsStillInFlight() async throws {
        let fixture = try await Fixture()
        fixture.writer.holdsFirst = true
        let indexer = fixture.makeIndexer()
        let run = Task { await indexer.reindex() }
        while !fixture.writer.isHolding { await Task.yield() }
        while fixture.clock.sleeperCount(waking: .seconds(10)) == 0 { await Task.yield() }
        fixture.clock.advance(.seconds(10))
        await run.value
        var finished = false
        let again = Task {
            await indexer.reindex()
            finished = true
        }
        while !finished {
            fixture.clock.advance(.seconds(10))
            await Task.yield()
        }
        await again.value
        #expect(fixture.writer.changes.contains(.eraseAll))
        #expect(fixture.record.pendingEraseAll, "the abandoned replace can still land, so the record is not clean")
        fixture.writer.release()
    }

    @Test func nothingRunsOrIsRecordedWhileTheSettingsStoreIsUnreadableAndItCatchesUpAfter() async throws {
        let fixture = try await Fixture()
        let indexer = fixture.makeIndexer()
        fixture.availability.isAvailable = false
        await indexer.reindex()
        await indexer.instanceRemoved(fixture.first, reason: .unpaired)
        #expect(fixture.writer.changes.isEmpty)
        #expect(fixture.record.pendingErasures.isEmpty)
        fixture.availability.isAvailable = true
        fixture.active = nil
        await indexer.reindex()
        #expect(fixture.writer.changes == [.erase(domains: [fixture.first.id])])
        #expect(fixture.record.pendingErasures.isEmpty)
    }

    @Test func aReplaceWaitsForAnAbandonedWriteOnlyUpToTheWriteBound() async throws {
        let fixture = try await Fixture()
        fixture.writer.holdsFirst = true
        let indexer = fixture.makeIndexer()
        let run = Task { await indexer.reindex() }
        while !fixture.writer.isHolding { await Task.yield() }
        while fixture.clock.sleeperCount(waking: .seconds(10)) == 0 { await Task.yield() }
        fixture.clock.advance(.seconds(10))
        await run.value
        var finished = false
        let again = Task {
            await indexer.reindex()
            finished = true
        }
        while !finished {
            fixture.clock.advance(.seconds(10))
            await Task.yield()
        }
        await again.value
        // Only the abandoned write itself was a replace; the second run's replace never started.
        #expect(fixture.writer.changes.filter { !titles($0).isEmpty }.count == 1)
        #expect(indexer.state == .failed)
        fixture.writer.release()
    }

    @Test func theBackgroundTaskEndsOnceAndCancelsTheRunWhenTimeExpires() async {
        var expire: (@MainActor @Sendable () -> Void)?
        var ends = 0
        var sawCancel = false
        let run = Task { @MainActor in
            await BackgroundWork.run(
                name: "test",
                begin: { _, expiration in
                    expire = expiration
                    return UIBackgroundTaskIdentifier(rawValue: 7)
                },
                end: { _ in ends += 1 },
                work: {
                    while !Task.isCancelled { await Task.yield() }
                    sawCancel = true
                })
        }
        while expire == nil { await Task.yield() }
        expire?()
        await run.value
        #expect(sawCancel)
        #expect(ends == 1)
    }
}
