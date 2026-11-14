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

@MainActor private func makeLinkModel() -> (AppModel, InstanceStore) {
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
    let model = AppModel(
        instances: instances, tokens: tokens,
        makeClient: {
            APIClient(
                baseURL: $0, token: nil, transport: HealthyInstanceTransport(), appVersion: "1",
                systemDescription: "test", sleep: { _ in })
        })
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
