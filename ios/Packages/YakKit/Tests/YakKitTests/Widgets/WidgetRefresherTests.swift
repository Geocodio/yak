import Foundation
import Testing

@testable import YakKit

private let listRoute = "GET threads"

private struct Rig {
    let refresher: WidgetRefresher
    let transport: ScriptedTransport
    let clock: ManualClock
    let files: InMemoryFileStore
    let defaults: InMemoryKeyValueStore
    let tokens: InMemoryTokenStore
    let store: InstanceStore
    let first: Instance
    let second: Instance

    func cache(_ instance: Instance) -> Cache {
        Cache(instance: instance.id, files: files, clock: clock)
    }

    /// Runs `work` while stepping the manual clock, so retry delays and budgets elapse.
    func advancing<Result: Sendable>(_ work: @escaping @Sendable () async -> Result) async -> Result {
        let task = Task { await work() }
        let clock = clock
        let stepper = Task {
            while !Task.isCancelled {
                await clock.advance(by: .seconds(1))
                await Task.yield()
            }
        }
        let result = await task.value
        stepper.cancel()
        return result
    }
}

private func makeRig(needsPairing: Bool = false) async throws -> Rig {
    let transport = ScriptedTransport()
    let clock = ManualClock()
    let files = InMemoryFileStore()
    let defaults = InMemoryKeyValueStore()
    let tokens = InMemoryTokenStore()
    let ticks = Ticks()
    let store = InstanceStore(defaults: defaults, tokens: tokens, now: { ticks.next() })
    let first = try await store.add(
        url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person", token: "bearer-one")
    let second = try await store.add(
        url: URL(string: "https://other.example.com")!, name: "Other Yak", userName: "Test person",
        token: "bearer-two")
    let environment = SessionEnvironment(
        http: transport, webSockets: FakeWebSocketServer(), files: files, clock: clock, makeID: { UUID() },
        appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
    return Rig(
        refresher: WidgetRefresher(instances: store, tokens: tokens, environment: environment),
        transport: transport, clock: clock, files: files, defaults: defaults, tokens: tokens, store: store,
        first: first, second: second)
}

/// A clock that moves forward on every read, so the second paired instance is the most recently used.
private final class Ticks: @unchecked Sendable {
    private let lock = NSLock()
    private var value = 1_000.0
    func next() -> Date {
        lock.withLock {
            value += 1
            return Date(timeIntervalSince1970: value)
        }
    }
}

struct WidgetRefresherTests {
    @Test func loadsAndSavesTheMineList() async throws {
        let rig = try await makeRig()
        await rig.transport.on(listRoute, try .scenario(200, "scenario-widgets-thread-list-busy"))
        let result = await rig.refresher.refresh(instance: rig.first.id, budget: .seconds(8))
        #expect(result.state == .content)
        #expect(result.isFresh)
        #expect(result.instance == rig.first)
        let requests = await rig.transport.requests(on: listRoute)
        #expect(requests.count == 1)
        #expect(requests[0].url?.host == "yak.example.com")
        #expect(requests[0].url?.query == "filter=mine")
        let saved = await rig.cache(rig.first).widgetSnapshot()
        #expect(saved == result.snapshot)
        #expect(saved?.instanceName == "Example Yak")
        #expect((saved?.needsYouCount ?? 0) > 0)
    }

    @Test func aRefreshThatFinishesAfterARemovalSavesNothing() async throws {
        let rig = try await makeRig()
        let environment = SessionEnvironment(
            http: rig.transport, webSockets: FakeWebSocketServer(), files: rig.files, clock: rig.clock,
            makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
        let refresher = WidgetRefresher(
            instances: rig.store, tokens: rig.tokens, environment: environment, isStillPaired: { _ in false })
        await rig.transport.on(listRoute, try .scenario(200, "scenario-widgets-thread-list-busy"))
        let result = await refresher.refresh(instance: rig.first.id, budget: .seconds(8))
        #expect(result.state == .instanceMissing)
        #expect(await rig.cache(rig.first).widgetSnapshot() == nil)
    }

    @Test func usesTheMostRecentInstanceWhenNoneIsConfigured() async throws {
        let rig = try await makeRig()
        await rig.transport.on(listRoute, try .scenario(200, "scenario-widgets-thread-list-empty"))
        let result = await rig.refresher.refresh(instance: nil, budget: .seconds(8))
        #expect(result.instance == rig.second)
        let requests = await rig.transport.requests(on: listRoute)
        #expect(requests.first?.url?.host == "other.example.com")
        #expect(result.snapshot?.needsYouCount == 0)
        #expect(result.state == .content)
    }

    @Test func noInstanceAtAll() async {
        let store = InstanceStore(
            defaults: InMemoryKeyValueStore(), tokens: InMemoryTokenStore(), now: { Date(timeIntervalSince1970: 1) })
        let environment = SessionEnvironment(
            http: ScriptedTransport(), webSockets: FakeWebSocketServer(), files: InMemoryFileStore(),
            clock: ManualClock(), makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Test",
            allowsInsecureRealtime: false)
        let refresher = WidgetRefresher(instances: store, tokens: InMemoryTokenStore(), environment: environment)
        #expect(await refresher.refresh(instance: nil, budget: .seconds(8)).state == .noInstance)
        #expect(await refresher.saved(instance: nil).state == .noInstance)
    }

    @Test func aRemovedInstanceIsMissing() async throws {
        let rig = try await makeRig()
        let result = await rig.refresher.refresh(instance: UUID(), budget: .seconds(8))
        #expect(result.state == .instanceMissing)
        #expect(result.instance == nil)
        #expect(await rig.transport.requests.isEmpty)
    }

    @Test func aMissingTokenNeedsPairingWithoutARequest() async throws {
        let rig = try await makeRig()
        try rig.tokens.removeToken(for: rig.first.id)
        let result = await rig.refresher.refresh(instance: rig.first.id, budget: .seconds(8))
        #expect(result.state == .needsPairing(host: "yak.example.com"))
        #expect(await rig.transport.requests.isEmpty)
    }

    @Test func aRevokedTokenNeedsPairingAndWritesNothing() async throws {
        let rig = try await makeRig()
        await rig.transport.on(listRoute, .json(401, #"{"error":"device_revoked","message":"Revoked."}"#))
        let before = rig.defaults.data(forKey: "instances.v1")
        let result = await rig.refresher.refresh(instance: rig.first.id, budget: .seconds(8))
        #expect(result.state == .needsPairing(host: "yak.example.com"))
        #expect(result.snapshot == nil)
        #expect(!result.isFresh)
        #expect(await rig.cache(rig.first).widgetSnapshot() == nil)
        #expect(try rig.files.list("instances/\(rig.first.id.uuidString)").isEmpty)
        #expect(rig.defaults.data(forKey: "instances.v1") == before)
    }

    @Test func aFailureKeepsTheSavedSnapshot() async throws {
        let rig = try await makeRig()
        await rig.transport.on(listRoute, try .scenario(200, "scenario-widgets-thread-list-busy"))
        let fresh = await rig.refresher.refresh(instance: rig.first.id, budget: .seconds(8))
        await rig.transport.on(listRoute, .json(503, #"{"error":"unavailable","message":"Try later."}"#))
        let refresher = rig.refresher
        let id = rig.first.id
        let result = await rig.advancing { await refresher.refresh(instance: id, budget: .seconds(8)) }
        #expect(result.snapshot == fresh.snapshot)
        #expect(result.state == .content)
        #expect(!result.isFresh)
    }

    @Test func aFailureWithoutASavedSnapshotIsNotLoaded() async throws {
        let rig = try await makeRig()
        await rig.transport.on(listRoute, .failure(.notConnectedToInternet))
        let refresher = rig.refresher
        let id = rig.first.id
        let result = await rig.advancing { await refresher.refresh(instance: id, budget: .seconds(8)) }
        #expect(result.state == .notLoaded(host: "yak.example.com"))
        #expect(result.snapshot == nil)
        #expect(!result.isFresh)
    }

    @Test func theBudgetEndsASlowLoad() async throws {
        let rig = try await makeRig()
        await rig.transport.on(listRoute, try .scenario(200, "scenario-widgets-thread-list-busy"))
        let saved = WidgetSnapshot(
            instanceName: "Example Yak", needsYouCount: 3, needsYou: [], running: [], updatedAt: rig.clock.now())
        await rig.cache(rig.first).saveWidgetSnapshot(saved)
        await rig.transport.hold(listRoute)
        let refresher = rig.refresher
        let id = rig.first.id
        let task = Task { await refresher.refresh(instance: id, budget: .seconds(8)) }
        await rig.transport.waitForRequests(1, on: listRoute)
        await rig.clock.waitForSleepers(1)
        await rig.clock.advance(by: .seconds(8))
        let result = await task.value
        #expect(result.snapshot == saved)
        #expect(!result.isFresh)
        #expect(result.state == .content)
        #expect(await rig.transport.cancelledRequests == 1)
    }

    @Test func aLockedTokenMakesNoRequest() async throws {
        let rig = try await makeRig()
        let locked = LockedTokenStore()
        let environment = SessionEnvironment(
            http: rig.transport, webSockets: FakeWebSocketServer(), files: rig.files, clock: rig.clock,
            makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
        let store = InstanceStore(
            defaults: rig.defaults, tokens: locked, now: { Date(timeIntervalSince1970: 1_000) })
        let refresher = WidgetRefresher(instances: store, tokens: locked, environment: environment)
        let withoutSnapshot = await refresher.refresh(instance: rig.first.id, budget: .seconds(8))
        #expect(withoutSnapshot.state == .notLoaded(host: "yak.example.com"))
        let saved = WidgetSnapshot(
            instanceName: "Example Yak", needsYouCount: 1, needsYou: [], running: [], updatedAt: rig.clock.now())
        await rig.cache(rig.first).saveWidgetSnapshot(saved)
        let withSnapshot = await refresher.refresh(instance: rig.first.id, budget: .seconds(8))
        #expect(withSnapshot.state == .content)
        #expect(withSnapshot.snapshot == saved)
        #expect(!withSnapshot.isFresh)
        #expect(await rig.transport.requests.isEmpty)
    }

    @Test func anOversizedSnapshotFileIsIgnored() async throws {
        let rig = try await makeRig()
        await rig.transport.on(listRoute, .failure(.notConnectedToInternet))
        let path = InstanceFolders.widgetSnapshot(rig.first.id)
        try rig.files.write(Data(repeating: 0x20, count: 300 * 1024), to: path, protection: .untilFirstUnlock)
        let result = await rig.refresher.saved(instance: rig.first.id)
        #expect(result.state == .notLoaded(host: "yak.example.com"))
        #expect(result.snapshot == nil)
        #expect(try rig.files.read(path) == nil)
    }

    @Test func savedNeverAsksTheServer() async throws {
        let rig = try await makeRig()
        let result = await rig.refresher.saved(instance: rig.first.id)
        #expect(result.state == .notLoaded(host: "yak.example.com"))
        #expect(await rig.transport.requests.isEmpty)
    }

    @Test func neverWritesTheInstanceList() async throws {
        let rig = try await makeRig()
        let writesBefore = rig.defaults.writeCount
        await rig.transport.on(listRoute, try .scenario(200, "scenario-widgets-thread-list-busy"))
        _ = await rig.refresher.refresh(instance: rig.first.id, budget: .seconds(8))
        await rig.transport.on(listRoute, .json(401, #"{"error":"device_revoked","message":"Revoked."}"#))
        _ = await rig.refresher.refresh(instance: nil, budget: .seconds(8))
        await rig.transport.on(listRoute, .failure(.timedOut))
        let refresher = rig.refresher
        let second = rig.second.id
        _ = await rig.advancing { await refresher.refresh(instance: second, budget: .seconds(8)) }
        _ = await rig.refresher.refresh(instance: UUID(), budget: .seconds(8))
        _ = await rig.refresher.saved(instance: nil)
        #expect(rig.defaults.writeCount == writesBefore)
    }
}

private final class LockedTokenStore: TokenStore, @unchecked Sendable {
    struct Locked: Error {}
    func token(for instance: UUID) throws -> String? { throw Locked() }
    func setToken(_ token: String, for instance: UUID) throws {}
    func removeToken(for instance: UUID) throws {}
}
