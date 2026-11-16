import Foundation
import YakKit

@testable import Yak

/// Answers `METHOD path` requests from a table and records each one with its host.
actor RecordingTransport: HTTPTransport {
    struct Recorded: Equatable {
        let route: String
        let host: String
    }

    private var answers: [String: (status: Int, body: String)] = [:]
    private(set) var recorded: [Recorded] = []
    private var isOffline = false
    private var held: Set<String> = []
    private var waiting: [CheckedContinuation<Void, Never>] = []

    /// Requests for `route` wait, after they are recorded, until `release()`.
    func hold(_ route: String) { held.insert(route) }
    func release() {
        held = []
        let all = waiting
        waiting = []
        for continuation in all { continuation.resume() }
    }

    func on(_ route: String, status: Int = 204, body: String = "") { answers[route] = (status, body) }
    func setOffline(_ offline: Bool) { isOffline = offline }
    func routes() -> [String] { recorded.map(\.route) }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let path = request.url?.path.replacingOccurrences(of: "/api/app/v1/", with: "") ?? ""
        let route = "\(request.httpMethod ?? "GET") \(path)"
        recorded.append(Recorded(route: route, host: request.url?.host() ?? ""))
        if isOffline { throw URLError(.notConnectedToInternet) }
        if held.contains(route) { await withCheckedContinuation { waiting.append($0) } }
        let answer = answers[route] ?? (404, "{\"error\":\"not_found\",\"message\":\"Not found.\"}")
        let response = HTTPURLResponse(
            url: request.url!, statusCode: answer.status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(answer.body.utf8), response)
    }
}

extension InMemoryFileStore {
    func exists(_ path: String) -> Bool { ((try? read(path)) ?? nil) != nil }
}

final class FakePermission: NotificationPermission, @unchecked Sendable {
    private let lock = NSLock()
    private var state: NotificationAuthorization
    private var result: NotificationRequestResult
    private(set) var requestCount = 0
    private(set) var registerCount = 0
    var liveActivities = true

    func setResult(_ next: NotificationRequestResult) { lock.withLock { result = next } }

    init(_ state: NotificationAuthorization, result: NotificationRequestResult = .granted) {
        self.state = state
        self.result = result
    }

    func authorization() async -> NotificationAuthorization { lock.withLock { state } }

    func request() async -> NotificationRequestResult {
        lock.withLock {
            requestCount += 1
            if result == .granted { state = .authorized }
            if result == .denied { state = .denied }
            return result
        }
    }

    @MainActor func registerForRemoteNotifications() { lock.withLock { registerCount += 1 } }
    func liveActivitiesEnabled() -> Bool { liveActivities }
}

final class FakeCenter: NotificationCenterClient, @unchecked Sendable {
    private let lock = NSLock()
    private var notifications: [DeliveredNotification]
    private var badgeValue = -1

    init(_ notifications: [DeliveredNotification] = []) { self.notifications = notifications }

    func delivered() async -> [DeliveredNotification] { lock.withLock { notifications } }
    func remove(_ identifiers: [String]) {
        lock.withLock { notifications.removeAll { identifiers.contains($0.identifier) } }
    }
    func setBadge(_ count: Int) async { lock.withLock { badgeValue = count } }
    var badge: Int { lock.withLock { badgeValue } }
    var identifiers: [String] { lock.withLock { notifications.map(\.identifier) } }
}

final class PushMemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

/// Two paired instances, `yak.example.com` and `other.example.com`, both able to push to this build.
struct PushRig {
    let controller: PushController
    let registrar: PushRegistrar
    let store: InstanceStore
    let transport: RecordingTransport
    let permission: FakePermission
    let center: FakeCenter
    let files: InMemoryFileStore
    let tokens: InMemoryTokenStore
    let environment: SessionEnvironment
    let home: Instance
    let other: Instance
}

@MainActor
func makePushRig(
    authorization: NotificationAuthorization = .authorized, delivered: [DeliveredNotification] = [],
    homeCount: Int = 2, otherCount: Int = 3
) async throws -> PushRig {
    let build = PushBuild(bundleID: "com.example.yak", environment: .production)
    let tokens = InMemoryTokenStore()
    let store = InstanceStore(defaults: PushMemoryDefaults(), tokens: tokens, now: { Date() }, build: build)
    let home = try await store.add(
        url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person", token: "bearer-one")
    let other = try await store.add(
        url: URL(string: "https://other.example.com")!, name: "Other Yak", userName: "Test person",
        token: "bearer-two")
    let me = try JSONDecoder().decode(Me.self, from: Data(meJSON.utf8))
    try await store.update(home.id, from: me)
    try await store.update(other.id, from: me)
    try await store.setNeedsYouCount(home.id, homeCount)
    try await store.setNeedsYouCount(other.id, otherCount)
    let transport = RecordingTransport()
    let files = InMemoryFileStore()
    let environment = SessionEnvironment(
        http: transport, webSockets: URLSessionWebSocketConnector(), files: files, clock: SystemClock(),
        makeID: UUID.init, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
    let registrar = PushRegistrar(instances: store, tokens: tokens, environment: environment)
    let permission = FakePermission(authorization)
    let center = FakeCenter(delivered)
    let controller = PushController(instances: store, registrar: registrar, permission: permission, center: center)
    return PushRig(
        controller: controller, registrar: registrar, store: store, transport: transport, permission: permission,
        center: center, files: files, tokens: tokens, environment: environment,
        home: await store.all().first { $0.id == home.id }!,
        other: await store.all().first { $0.id == other.id }!)
}

let meJSON = """
    {"attachments":{"maxFileBytes":10485760,"maxFiles":8},
     "device":{"id":1,"kind":"phone","name":"Test phone","pairedAt":"2026-11-12T10:00:00+00:00"},
     "features":{"liveActivities":true,"widgets":true},
     "instance":{"name":"Example Yak","url":"https://yak.example.com","version":"2026.11.1"},
     "needsYouCount":0,
     "push":{"apns":true,"bundleId":"com.example.yak","environment":"production"},
     "realtime":{"host":"ws.example.test","key":"reverb-key","port":443,"scheme":"https"},
     "terminal":{"wsUrl":"wss://yak.example.com/terminal"},
     "user":{"email":"person@example.test","id":1,"initials":"TP","isAdmin":false,"name":"Test Person"}}
    """

/// Waits, a yield at a time, until `condition` holds or a bound of yields passes.
@MainActor
func eventually(_ condition: @MainActor () async -> Bool) async -> Bool {
    for _ in 0..<2000 {
        if await condition() { return true }
        try? await Task.sleep(for: .milliseconds(5))
    }
    return false
}
