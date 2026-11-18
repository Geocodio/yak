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

@MainActor @Test func aTokenReadErrorKeepsTheTokenAndShowsUnreadable() async throws {
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
    #expect(model.refreshState == .tokenUnreadable)
    #expect(await instances.all().count == 1)
    #expect(try tokens.inner.token(for: instance.id) == "secret")
}

/// Answers a pairing like a healthy instance: it is Yak, accepts the code and knows who the phone is.
private struct HealthyInstanceTransport: HTTPTransport {
    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let host = request.url!.host!
        let body =
            switch request.url!.lastPathComponent {
            case "instance":
                #"{"yak":true,"name":"Yak","api":{"current":1,"min":1},"pairing":{"qr":true,"address":true}}"#
            case "pair":
                #"{"token":"secret-\#(host)","device":{"id":"d1","name":"Phone","kind":"phone"},"user":{"id":1,"name":"Sam","email":"s@example.com"}}"#
            default:
                #"{"user":{"id":1,"name":"Sam","email":"s@example.com","initials":"S","isAdmin":false},"device":{"id":"d1","name":"Phone","kind":"phone"},"instance":{"name":"\#(host)","url":"https://\#(host)","version":"1"},"needsYouCount":0,"realtime":null,"terminal":{"wsUrl":"wss://\#(host)/ws"},"attachments":{"maxFiles":4,"maxFileBytes":100},"push":{"apns":true,"bundleId":null,"environment":null},"features":{}}"#
            }
        return (
            Data(body.utf8),
            HTTPURLResponse(url: request.url!, statusCode: 200, httpVersion: nil, headerFields: nil)!
        )
    }
}

@MainActor func makeLinkModel(
    observers: [any InstanceLifecycleObserver] = [], observerTimeout: Duration = .seconds(5)
) -> (AppModel, InstanceStore) {
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
    let model = AppModel(
        instances: instances, tokens: tokens,
        makeClient: {
            APIClient(
                baseURL: $0, token: nil, transport: HealthyInstanceTransport(), appVersion: "1",
                systemDescription: "test", sleep: { _ in })
        },
        observers: observers, observerTimeout: observerTimeout)
    return (model, instances)
}

@MainActor @Test func openingAPairingLinkWhileAlreadyPairedAddsASecondActiveInstance() async throws {
    let (model, instances) = makeLinkModel()
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Ffirst.example.com&code=a")!)
    #expect(await instances.all().isEmpty)
    await model.confirmLinkPairing()
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Fsecond.example.com&code=b")!)
    #expect(await instances.all().count == 1)
    await model.confirmLinkPairing()
    let all = await instances.all()
    #expect(all.count == 2)
    #expect(await instances.active()?.url == URL(string: "https://second.example.com")!)
    #expect(model.linkPairing == nil)
    guard case .signedIn(let shown) = model.route else {
        Issue.record("expected the signed-in route")
        return
    }
    #expect(shown.url == URL(string: "https://second.example.com")!)
}

@MainActor
@Test(arguments: ["yak://settings", "yak://pair?code=a", "yak://pair?url=http%3A%2F%2Fx.example.com&code=a"])
func aLinkThatIsNotAPairingLinkChangesNothing(link: String) async {
    let (model, instances) = makeLinkModel()
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Ffirst.example.com&code=a")!)
    await model.confirmLinkPairing()
    let activeBefore = await instances.active()
    let routeBefore = model.route
    await model.handle(url: URL(string: link)!)
    #expect(await instances.all().count == 1)
    #expect(await instances.active() == activeBefore)
    #expect(model.route == routeBefore)
    #expect(model.linkPairing == nil)
    #expect(model.linkPairingSuccessCount == 1)
}

@MainActor @Test func anUnreadableTokenAtLaunchShowsTheSignedInScreenNotPairAgain() async throws {
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
    await model.reloadRoute()
    #expect(model.route == .signedIn(instance))
    #expect(try tokens.inner.token(for: instance.id) == "secret")
}

@MainActor @Test func aMissingTokenAtLaunchShowsPairAgain() async throws {
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
    let instance = try await instances.add(
        url: URL(string: "https://yak.example.com")!, name: "Acme", userName: "Ada", token: "secret")
    try tokens.removeToken(for: instance.id)
    let model = AppModel(instances: instances, tokens: tokens, makeClient: { _ in fatalError() })
    await model.reloadRoute()
    #expect(model.route == .pairAgain(instance))
}

@MainActor @Test func scanningAgainClosesThePairingAndAsksForTheScanner() async {
    let (model, _) = makeLinkModel()
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Ffirst.example.com&code=a")!)
    model.scanAgain()
    #expect(model.linkPairing == nil)
    #expect(model.wantsScanner)
}

@MainActor @Test func aScannerRequestDoesNotOutliveTheInstanceItWasMadeFor() async throws {
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
    let instance = try await instances.add(
        url: URL(string: "https://yak.example.com")!, name: "Acme", userName: "Ada", token: "secret")
    try tokens.removeToken(for: instance.id)
    let model = AppModel(instances: instances, tokens: tokens, makeClient: { _ in fatalError() })
    await model.reloadRoute()
    #expect(model.route == .pairAgain(instance))
    model.scanAgain()
    #expect(model.wantsScanner)
    await model.remove(instance)
    #expect(model.route == .connect)
    #expect(!model.wantsScanner)
}

@MainActor private func pairTwoInstances(_ model: AppModel) async {
    for (host, code) in [("first", "a"), ("second", "b")] {
        await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2F\(host).example.com&code=\(code)")!)
        await model.confirmLinkPairing()
    }
}

@MainActor @Test func pairingAnotherInstanceStartsTheWindowOver() async {
    let (model, _) = makeLinkModel()
    let scene = model.makeSceneModel(restored: nil)
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Ffirst.example.com&code=a")!)
    await model.confirmLinkPairing()
    let first = scene.state.instanceID
    scene.applyColdStart(.thread("a1b2c3d4"))
    scene.justPairedUserName = "Sam"
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Fsecond.example.com&code=b")!)
    await model.confirmLinkPairing()
    #expect(scene.state.instanceID != first)
    #expect(scene.state.threadID == nil)
    #expect(!scene.hasAppliedColdStart)
    #expect(scene.justPairedUserName == "Sam")
}

@MainActor @Test func aLinkSwitchKeepsTheThreadItOpened() async throws {
    let (model, instances) = makeLinkModel()
    let scene = model.makeSceneModel(restored: nil)
    await pairTwoInstances(model)
    scene.applyColdStart(.drawer(.mine))
    await model.handle(link: .thread(instance: URL(string: "https://first.example.com")!, id: "a1b2c3d4"))
    let first = try #require(await instances.all().first { $0.url.host() == "first.example.com" })
    #expect(await instances.active()?.id == first.id)
    #expect(scene.state.threadID == "a1b2c3d4")
    #expect(scene.state.instanceID == first.id)
    #expect(scene.hasAppliedColdStart)
}

@MainActor @Test func aLinkBeforeTheColdStartIsTakenOnce() async {
    let (model, _) = makeLinkModel()
    _ = model.makeSceneModel(restored: nil)
    await pairTwoInstances(model)
    let link = DeepLink.thread(instance: URL(string: "https://second.example.com")!, id: "a1b2c3d4")
    await model.handle(link: link)
    #expect(model.takeLaunchLink() == link)
    #expect(model.takeLaunchLink() == nil)
}

@MainActor @Test func aLinkForAnUnknownInstanceFillsTheAddress() async {
    let (model, _) = makeLinkModel()
    let scene = model.makeSceneModel(restored: nil)
    await pairTwoInstances(model)
    scene.applyColdStart(.drawer(.mine))
    let address = URL(string: "https://unknown.example.com")!
    await model.handle(link: .thread(instance: address, id: "a1b2c3d4"))
    #expect(model.addressToConnect == address)
}

@MainActor @Test func aLinkOnTheConnectScreenFillsTheAddress() async {
    let (model, _) = makeLinkModel()
    await model.reloadRoute()
    let address = URL(string: "https://unknown.example.com")!
    await model.handle(link: .thread(instance: address, id: "a1b2c3d4"))
    #expect(model.addressToConnect == address)
    #expect(model.takeLaunchLink() == nil)
}

/// Answers `me` with a count of four, or fails the way a cancelled request does.
private struct CountingTransport: HTTPTransport {
    let isCancelled: Bool
    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        if isCancelled { throw URLError(.cancelled) }
        let body =
            #"{"user":{"id":1,"name":"Sam","email":"s@example.com","initials":"S","isAdmin":false},"device":{"id":"d1","name":"Phone","kind":"phone"},"instance":{"name":"Renamed","url":"https://x.example.com","version":"1"},"needsYouCount":4,"realtime":null,"terminal":{"wsUrl":"wss://x.example.com/ws"},"attachments":{"maxFiles":4,"maxFileBytes":100},"push":{"apns":true,"bundleId":null,"environment":null},"features":{}}"#
        return (
            Data(body.utf8),
            HTTPURLResponse(url: request.url!, statusCode: 200, httpVersion: nil, headerFields: nil)!
        )
    }
}

@MainActor @Test(arguments: [false, true])
func refreshingAnInstanceThatIsNotActiveOnlyUpdatesItsStoredCount(isCancelled: Bool) async throws {
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
    let model = AppModel(
        instances: instances, tokens: tokens,
        makeClient: {
            APIClient(
                baseURL: $0, token: nil, transport: CountingTransport(isCancelled: isCancelled), appVersion: "1",
                systemDescription: "test", sleep: { _ in })
        })
    let other = try await instances.add(
        url: URL(string: "https://other.example.com")!, name: "Other", userName: "Sam", token: "t1")
    let active = try await instances.add(
        url: URL(string: "https://active.example.com")!, name: "Active", userName: "Sam", token: "t2")
    try await instances.setActive(active.id)
    await model.reloadRoute()
    let routeBefore = model.route
    guard case .signedIn(let shown) = routeBefore else {
        Issue.record("not signed in")
        return
    }
    #expect(shown.id == active.id)
    await model.refreshMe(for: other)
    #expect(model.route == routeBefore)
    #expect(model.refreshState == .idle)
    let stored = try #require(await instances.all().first { $0.id == other.id })
    #expect(stored.needsYouCount == (isCancelled ? 0 : 4))
}

@MainActor private final class RecordingObserver: InstanceLifecycleObserver {
    private(set) var events: [String] = []
    func instancePaired(_ instance: Instance) async { events.append("paired \(instance.url.host() ?? "")") }
    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        events.append("removed \(instance.url.host() ?? "") \(reason)")
    }
}

/// Never answers, like an observer waiting on a network that is gone.
@MainActor private final class StuckObserver: InstanceLifecycleObserver {
    func instancePaired(_ instance: Instance) async { try? await Task.sleep(for: .seconds(3600)) }
    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        try? await Task.sleep(for: .seconds(3600))
    }
}

private final class SleeperCount: @unchecked Sendable {
    private let lock = NSLock()
    private var value = 0
    var count: Int { lock.withLock { value } }
    func change(_ delta: Int) { lock.withLock { value += delta } }
}

@MainActor @Test func aFinishedObserverCallCancelsItsTimer() async throws {
    let sleepers = SleeperCount()
    let observers = InstanceObservers(
        observers: [RecordingObserver()], timeout: .seconds(3600),
        sleep: { _ in
            sleepers.change(1)
            defer { sleepers.change(-1) }
            try await Task.sleep(for: .seconds(3600))
        })
    let (_, instances) = makeLinkModel()
    let instance = try await instances.add(
        url: URL(string: "https://first.example.com")!, name: "Example Yak", userName: "Test person", token: "secret")
    for _ in 0..<20 { await observers.paired(instance) }
    for _ in 0..<100 where sleepers.count > 0 { try await Task.sleep(for: .milliseconds(20)) }
    #expect(sleepers.count == 0, "a timer is still sleeping after its call finished")
}

@MainActor @Test func observersHearAPairing() async {
    let observer = RecordingObserver()
    let (model, _) = makeLinkModel(observers: [observer])
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Ffirst.example.com&code=a")!)
    await model.confirmLinkPairing()
    #expect(observer.events == ["paired first.example.com"])
}

@MainActor @Test func observersHearARemoval() async throws {
    let observer = RecordingObserver()
    let (model, instances) = makeLinkModel(observers: [observer])
    let instance = try await instances.add(
        url: URL(string: "https://first.example.com")!, name: "Example Yak", userName: "Test person", token: "secret")
    await model.remove(instance)
    #expect(observer.events == ["removed first.example.com unpaired"])
    #expect(await instances.all().isEmpty)
}

@MainActor @Test func observersHearARevoke() async throws {
    let observer = RecordingObserver()
    let (model, instances) = makeLinkModel(observers: [observer])
    let instance = try await instances.add(
        url: URL(string: "https://first.example.com")!, name: "Example Yak", userName: "Test person", token: "secret")
    await model.markRevoked(instance)
    #expect(observer.events == ["removed first.example.com revoked"])
}

@MainActor @Test func aSlowObserverDoesNotHoldTheRoute() async throws {
    let recorder = RecordingObserver()
    let (model, instances) = makeLinkModel(
        observers: [StuckObserver(), recorder], observerTimeout: .milliseconds(50))
    let instance = try await instances.add(
        url: URL(string: "https://first.example.com")!, name: "Example Yak", userName: "Test person", token: "secret")
    await model.reloadRoute()
    let started = ContinuousClock.now
    await model.remove(instance)
    #expect(model.route == .connect)
    // The stuck observer never answers, so only its time limit lets the removal finish; the bound leaves room for a
    // loaded machine.
    #expect(ContinuousClock.now - started < .seconds(30))
    // The observer after the stuck one is still told.
    #expect(recorder.events == ["removed first.example.com unpaired"])
}

/// Notes whether the instance's token is still stored when it is told about a removal.
@MainActor private final class TokenWatchingObserver: InstanceLifecycleObserver {
    private(set) var events: [String] = []
    private let instances: InstanceStore
    private let tokens: any TokenStore

    init(instances: InstanceStore, tokens: any TokenStore) {
        self.instances = instances
        self.tokens = tokens
    }

    func instanceWillBeRemoved(_ instance: Instance) async {
        let hasToken = ((try? tokens.token(for: instance.id)) ?? nil) != nil
        let isListed = await instances.all().contains { $0.id == instance.id }
        events.append("will remove, token \(hasToken), listed \(isListed)")
    }

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        let isListed = await instances.all().contains { $0.id == instance.id }
        events.append("removed, listed \(isListed)")
    }
}

@MainActor @Test func observersAreToldBeforeTheTokenGoes() async throws {
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
    let observer = TokenWatchingObserver(instances: instances, tokens: tokens)
    let model = AppModel(
        instances: instances, tokens: tokens,
        makeClient: {
            APIClient(
                baseURL: $0, token: nil, transport: HealthyInstanceTransport(), appVersion: "1",
                systemDescription: "Test")
        },
        observers: [observer])
    let instance = try await instances.add(
        url: URL(string: "https://first.example.com")!, name: "Example Yak", userName: "Test person", token: "secret")
    await model.remove(instance)
    #expect(observer.events == ["will remove, token true, listed true", "removed, listed false"])
}

@MainActor @Test func aTaskLinkForAnUnpairedAddressNeverOpensTheBrowser() async {
    let (model, _) = makeLinkModel()
    let scene = model.makeSceneModel(restored: nil)
    await pairTwoInstances(model)
    scene.applyColdStart(.drawer(.mine))
    await model.handle(url: URL(string: "yak://task?instance=https%3A%2F%2Fevil.example.com&id=12345")!)
    #expect(model.browserURL == nil)
    #expect(model.taskToOpen == nil)
    #expect(model.addressToConnect == URL(string: "https://evil.example.com")!)
}

@MainActor @Test func aTaskLinkForAnotherPairedInstanceSwitchesToItFirst() async throws {
    let (model, instances) = makeLinkModel()
    let scene = model.makeSceneModel(restored: nil)
    await pairTwoInstances(model)
    scene.applyColdStart(.drawer(.mine))
    await model.handle(link: .task(instance: URL(string: "https://first.example.com")!, id: "k3m9p2qa"))
    #expect(await instances.active()?.url.host() == "first.example.com")
    #expect(model.taskToOpen == "k3m9p2qa")
    #expect(model.browserURL == nil)
}

@MainActor @Test func aLinkGoesToTheLastActiveScene() async {
    let (model, _) = makeLinkModel()
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Ffirst.example.com&code=a")!)
    await model.confirmLinkPairing()
    let first = model.makeSceneModel(restored: nil)
    let second = model.makeSceneModel(restored: nil)
    first.applyColdStart(.drawer(.mine))
    second.applyColdStart(.drawer(.mine))
    model.sceneBecameActive(first)
    model.sceneBecameActive(second)
    let address = URL(string: "https://first.example.com")!
    await model.handle(link: .thread(instance: address, id: "a1b2c3d4"))
    #expect(second.state.threadID == "a1b2c3d4" && first.state.threadID == nil)
    model.sceneDisconnected(second)
    await model.handle(link: .thread(instance: address, id: "e5f6g7h8"))
    #expect(first.state.threadID == "e5f6g7h8")
}

@MainActor @Test func aLinkWithNoSceneConnectedWaitsForTheColdStart() async {
    let (model, _) = makeLinkModel()
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Ffirst.example.com&code=a")!)
    await model.confirmLinkPairing()
    let link = DeepLink.thread(instance: URL(string: "https://first.example.com")!, id: "a1b2c3d4")
    await model.handle(link: link)
    #expect(model.takeLaunchLink() == link)
    #expect(model.routingScene == nil)
}

// MARK: Several windows

@MainActor @Test func twoInstanceSwitchesBothResetTheWindow() async throws {
    let (model, instances) = makeLinkModel()
    let scene = model.makeSceneModel(restored: nil)
    await pairTwoInstances(model)
    model.sceneBecameActive(scene)
    let all = await instances.all()
    let first = try #require(all.first { $0.url.host() == "first.example.com" })
    let second = try #require(all.first { $0.url.host() == "second.example.com" })
    scene.applyColdStart(.thread("a1b2c3d4"))
    await model.activate(first)
    #expect(scene.state.instanceID == first.id && scene.state.threadID == nil && !scene.hasAppliedColdStart)
    scene.applyColdStart(.thread("e5f6g7h8"))
    await model.activate(second)
    #expect(scene.state.instanceID == second.id && scene.state.threadID == nil && !scene.hasAppliedColdStart)
}

@MainActor @Test func aWindowThatBecomesActiveAgainIsRegisteredAgain() async throws {
    let (model, instances) = makeLinkModel()
    let scene = model.makeSceneModel(restored: nil)
    await pairTwoInstances(model)
    model.sceneDisconnected(scene)
    #expect(model.connectedSceneCount == 0)
    model.sceneBecameActive(scene)
    #expect(model.connectedSceneCount == 1 && model.routingScene === scene)
    let first = try #require(await instances.all().first { $0.url.host() == "first.example.com" })
    scene.applyColdStart(.thread("a1b2c3d4"))
    await model.activate(first)
    #expect(scene.state.instanceID == first.id && scene.state.threadID == nil)
}

@MainActor @Test func oneWindowLeavingDoesNotPauseTheOthers() async {
    let (model, _) = makeLinkModel()
    let first = model.makeSceneModel(restored: nil)
    let second = model.makeSceneModel(restored: nil)
    #expect(await model.scenePhaseChanged(first, to: .active) == .none)
    #expect(await model.scenePhaseChanged(second, to: .active) == .none)
    #expect(await model.scenePhaseChanged(second, to: .background) == .none)
    #expect(!model.isInBackground)
    #expect(await model.scenePhaseChanged(first, to: .background) == .enteredBackground)
    #expect(model.isInBackground)
    #expect(await model.scenePhaseChanged(second, to: .active) == .enteredForeground)
    #expect(!model.isInBackground)
}

@MainActor @Test func aLinkWithTwoWindowsOpensNoThirdWindow() async {
    let (model, _) = makeLinkModel()
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Ffirst.example.com&code=a")!)
    await model.confirmLinkPairing()
    let first = model.makeSceneModel(restored: nil)
    let second = model.makeSceneModel(restored: nil)
    first.applyColdStart(.drawer(.mine))
    second.applyColdStart(.drawer(.mine))
    model.sceneBecameActive(first)
    model.sceneBecameActive(second)
    await model.handle(url: URL(string: "yak://thread?instance=https%3A%2F%2Ffirst.example.com&id=a1b2c3d4")!)
    await model.handle(link: .thread(instance: URL(string: "https://first.example.com")!, id: "e5f6g7h8"))
    #expect(model.connectedSceneCount == 2)
    #expect(first.state.threadID == nil && second.state.threadID == "e5f6g7h8")
}

@MainActor @Test func theAppWideValuesAreTakenByTheRoutingWindowOnly() {
    let (model, _) = makeLinkModel()
    let first = model.makeSceneModel(restored: nil)
    let second = model.makeSceneModel(restored: nil)
    model.sceneBecameActive(first)
    model.sceneBecameActive(second)
    model.taskToOpen = "k3m9p2qa"
    #expect(model.takeTaskToOpen(for: first) == nil && model.taskToOpen == "k3m9p2qa")
    #expect(model.takeTaskToOpen(for: second) == "k3m9p2qa" && model.taskToOpen == nil)
    model.browserURL = URL(string: "https://yak.example.com/settings")!
    #expect(model.presentsSheets(in: second) && !model.presentsSheets(in: first))
    // The window that opened the sheet keeps it when the other one comes to the front.
    model.sceneBecameActive(first)
    #expect(model.presentsSheets(in: second) && !model.presentsSheets(in: first))
    model.browserURL = nil
    #expect(model.presentsSheets(in: first) && !model.presentsSheets(in: second))
    model.addressToConnect = URL(string: "https://other.example.com")!
    #expect(model.presentsSheets(in: first) && !model.presentsSheets(in: second))
}

@MainActor @Test func aLinkGoesToTheWindowThatReceivedIt() async {
    let (model, _) = makeLinkModel()
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Ffirst.example.com&code=a")!)
    await model.confirmLinkPairing()
    let first = model.makeSceneModel(restored: nil)
    let second = model.makeSceneModel(restored: nil)
    first.applyColdStart(.drawer(.mine))
    second.applyColdStart(.drawer(.mine))
    model.sceneBecameActive(first)
    model.sceneBecameActive(second)
    await model.handle(
        url: URL(string: "yak://thread?instance=https%3A%2F%2Ffirst.example.com&id=a1b2c3d4")!, in: first)
    #expect(first.state.threadID == "a1b2c3d4" && second.state.threadID == nil)
}

@MainActor @Test func aWindowThatIsReleasedLeavesTheRegistryAndACoverNeverRemovesIt() async {
    let (model, _) = makeLinkModel()
    var scene: SceneModel? = model.makeSceneModel(restored: nil)
    let other = model.makeSceneModel(restored: nil)
    model.sceneBecameActive(other)
    _ = await model.scenePhaseChanged(scene!, to: .active)
    #expect(model.connectedSceneCount == 2)
    // A full-screen cover going up and down touches nothing in the model: both windows stay and still route.
    #expect(model.routingScene === scene || model.routingScene === other)
    scene = nil
    #expect(model.connectedSceneCount == 1)
    #expect(await model.scenePhaseChanged(other, to: .background) == .enteredBackground)
}

@MainActor @Test func aWindowRecordsItsPhaseWhenItStartsSoItCannotBlockThePause() async {
    let (model, _) = makeLinkModel()
    let first = model.makeSceneModel(restored: nil)
    let second = model.makeSceneModel(restored: nil)
    _ = await model.scenePhaseChanged(first, to: .background)
    // The second window started in the background and its phase never changed afterwards.
    #expect(await model.scenePhaseChanged(second, to: .background) == .enteredBackground)
}

@MainActor @Test func theExplanationStaysInTheWindowThatClaimedIt() {
    let (model, _) = makeLinkModel()
    let first = model.makeSceneModel(restored: nil)
    let second = model.makeSceneModel(restored: nil)
    model.sceneBecameActive(first)
    #expect(model.presentsExplanation(in: first, isShowing: true))
    model.sceneBecameActive(second)
    #expect(model.presentsExplanation(in: first, isShowing: true))
    #expect(!model.presentsExplanation(in: second, isShowing: true))
    #expect(!model.presentsExplanation(in: first, isShowing: false))
    #expect(model.presentsExplanation(in: second, isShowing: true))
}

@MainActor @Test func aLinkThatArrivesWhileACoverIsUpIsHandledByTheStillRegisteredWindow() async {
    let (model, _) = makeLinkModel()
    await model.handle(url: URL(string: "yak://pair?url=https%3A%2F%2Ffirst.example.com&code=a")!)
    await model.confirmLinkPairing()
    let scene = model.makeSceneModel(restored: nil)
    scene.applyColdStart(.drawer(.mine))
    model.sceneBecameActive(scene)
    // A cover goes up over the window: the window stays registered, so the link has somewhere to go.
    await model.handle(link: .thread(instance: URL(string: "https://first.example.com")!, id: "a1b2c3d4"))
    #expect(scene.state.threadID == "a1b2c3d4" && model.takeLaunchLink() == nil)
}

@MainActor @Test func appWideWorkIsClaimedOncePerAppStateChange() async {
    let (model, _) = makeLinkModel()
    let first = model.makeSceneModel(restored: nil)
    let second = model.makeSceneModel(restored: nil)
    #expect(model.claimActiveAnnouncement() && !model.claimActiveAnnouncement())
    #expect(model.claimBackgroundAnnouncement() && !model.claimBackgroundAnnouncement())
    await model.scenePhaseChanged(first, to: .background)
    await model.scenePhaseChanged(second, to: .background)
    #expect(model.claimActiveAnnouncement())
    await model.scenePhaseChanged(first, to: .active)
    #expect(model.claimBackgroundAnnouncement())
}
