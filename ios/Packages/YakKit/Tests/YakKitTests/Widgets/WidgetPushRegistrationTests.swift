import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let route = "PUT push-tokens/widget"
private let token = String(repeating: "0123456789abcdef", count: 4)
private let newerToken = String(repeating: "fedcba9876543210", count: 4)
private let noContent = FakeTransport.Result.json(204, "")
private let production = PushBuild(bundleID: "com.example.yak", environment: .production)

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct Rig {
    let transport: ScriptedTransport
    let clock: ManualClock
    let files: InMemoryFileStore
    let store: InstanceStore
    let tokens: InMemoryTokenStore
    let environment: SessionEnvironment
    let instances: [Instance]

    func registration(build: PushBuild? = production) -> WidgetPushRegistration {
        WidgetPushRegistration(instances: store, tokens: tokens, environment: environment, build: build)
    }
}

private func mePayload(bundleID: String = "com.example.yak") throws -> Me {
    let data = try Scenarios.data("scenario-me", setting: ["push", "bundleId"], toJSON: "\"\(bundleID)\"")
    return try JSONDecoder().decode(Me.self, from: data)
}

/// One instance per bundle identifier, `host0.example.com` and so on.
private func makeRig(
    bundles: [String] = ["com.example.yak"], build: PushBuild? = production,
    transport: (any HTTPTransport)? = nil
) async throws -> (Rig, ScriptedTransport) {
    let scripted = ScriptedTransport()
    let clock = ManualClock()
    let files = InMemoryFileStore()
    let tokens = InMemoryTokenStore()
    let store = InstanceStore(
        defaults: MemoryDefaults(), tokens: tokens, now: { Date(timeIntervalSince1970: 1_000) }, build: build)
    var added: [Instance] = []
    for (index, bundle) in bundles.enumerated() {
        let instance = try await store.add(
            url: URL(string: "https://host\(index).example.com")!, name: "Example Yak", userName: "Test person",
            token: "bearer-\(index)")
        try await store.update(instance.id, from: try mePayload(bundleID: bundle))
        added.append(instance)
    }
    let environment = SessionEnvironment(
        http: transport ?? scripted, webSockets: FakeWebSocketServer(), files: files, clock: clock,
        makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
    return (
        Rig(
            transport: scripted, clock: clock, files: files, store: store, tokens: tokens, environment: environment,
            instances: added), scripted
    )
}

private func sent(_ transport: ScriptedTransport, host: String) async -> [URLRequest] {
    await transport.requests(on: route).filter { $0.url?.host == host }
}

private actor ConcurrencyCounter {
    private(set) var maximum = 0
    private var active = 0
    func enter() {
        active += 1
        maximum = max(maximum, active)
    }
    func leave() { active -= 1 }
    var isFull: Bool { active >= 4 }
}

private struct CountingTransport: HTTPTransport {
    let counter: ConcurrencyCounter
    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        await counter.enter()
        var yields = 0
        while await !counter.isFull, yields < 10_000 {
            await Task.yield()
            yields += 1
        }
        await counter.leave()
        return (Data(), HTTPURLResponse(url: request.url!, statusCode: 204, httpVersion: nil, headerFields: nil)!)
    }
}

/// Answers per host: host0 loses its first request to the network, host2 refuses until it is told to accept.
private final class PerHostBehaviour: @unchecked Sendable {
    private let lock = NSLock()
    private var hasFailedFirstHost = false
    private var thirdHostAccepts = false

    func acceptsThirdHost() { lock.withLock { thirdHostAccepts = true } }

    func answer(host: String) -> FakeTransport.Result {
        lock.withLock {
            if host == "host0.example.com", !hasFailedFirstHost {
                hasFailedFirstHost = true
                return .failure(.notConnectedToInternet)
            }
            if host == "host2.example.com", !thirdHostAccepts {
                return .json(
                    409,
                    #"{"error":"push_environment_mismatch","message":"No.","details":{"expected":"sandbox","given":"production"}}"#
                )
            }
            return noContent
        }
    }
}

@Suite struct WidgetPushRegistrationTests {
    @Test func aRegistrationSurvivesARelaunchAndStopsAtARefusal() async throws {
        let (rig, transport) = try await makeRig(bundles: ["com.example.yak", "com.example.other", "com.example.yak"])
        let (first, other, third) = (rig.instances[0], rig.instances[1], rig.instances[2])
        let behaviour = PerHostBehaviour()
        await transport.on(prefix: route) { request in behaviour.answer(host: request.url?.host ?? "") }
        let registration = rig.registration()
        var statuses = await registration.tokenChanged(token, budget: .seconds(8))
        guard case .retrying(let retryAt) = statuses[first.id] else {
            Issue.record("expected retrying, got \(String(describing: statuses[first.id]))")
            return
        }
        #expect(
            statuses[third.id] == .refused(.environmentMismatch(expected: "sandbox", given: "production")))
        #expect(statuses[other.id] == .notEligible(.otherBuild))
        #expect(await sent(transport, host: "host1.example.com").isEmpty)
        let stored = try #require(try rig.files.read(InstanceFolders.widgetPushRecord(first.id)))
        #expect(String(decoding: stored, as: UTF8.self).contains(#""isRegistered":false"#))

        await rig.clock.advance(by: .seconds(2))
        #expect(rig.clock.now() >= retryAt)
        let relaunched = rig.registration()
        statuses = await relaunched.registerPending(budget: .seconds(8))
        #expect(statuses[first.id] == .registered)
        #expect(
            statuses[third.id] == .refused(.environmentMismatch(expected: "sandbox", given: "production")))
        #expect(await sent(transport, host: "host2.example.com").count == 1)
        let bodies = try await sent(transport, host: "host0.example.com").map {
            try JSONDecoder().decode([String: String].self, from: $0.httpBody ?? Data())
        }
        #expect(bodies.count == 2)
        #expect(bodies[0] == bodies[1])
        #expect(bodies[0] == ["token": token, "environment": "production"])

        behaviour.acceptsThirdHost()
        statuses = await relaunched.tokenChanged(newerToken, budget: .seconds(8))
        #expect(statuses[first.id] == .registered)
        #expect(statuses[third.id] == .registered)
        #expect(await sent(transport, host: "host2.example.com").count == 2)
    }

    @Test func aRefusalIsKeptUntilTheTokenChanges() async throws {
        let (rig, transport) = try await makeRig()
        let id = rig.instances[0].id
        await transport.on(
            route,
            .json(
                409,
                #"{"error":"push_environment_mismatch","message":"No.","details":{"expected":"sandbox","given":"production"}}"#
            ))
        let registration = rig.registration()
        var statuses = await registration.tokenChanged(token, budget: .seconds(8))
        #expect(statuses[id] == .refused(.environmentMismatch(expected: "sandbox", given: "production")))
        await rig.clock.advance(by: .seconds(600))
        statuses = await registration.registerPending(budget: .seconds(8))
        #expect(await transport.requests(on: route).count == 1)
        #expect(statuses[id] == .refused(.environmentMismatch(expected: "sandbox", given: "production")))

        await transport.on(route, noContent)
        statuses = await registration.tokenChanged(newerToken, budget: .seconds(8))
        #expect(statuses[id] == .registered)
        #expect(await transport.requests(on: route).count == 2)
    }

    @Test func anOlderServerIsAskedAgainTomorrow() async throws {
        let (rig, transport) = try await makeRig()
        let id = rig.instances[0].id
        await transport.on(route, .json(404, #"{"error":"not_found","message":"No."}"#))
        let registration = rig.registration()
        var statuses = await registration.tokenChanged(token, budget: .seconds(8))
        guard case .unsupported(let retryAt) = statuses[id] else {
            Issue.record("expected unsupported")
            return
        }
        #expect(retryAt.timeIntervalSince(rig.clock.now()) == 86_400)
        await rig.clock.advance(by: .seconds(86_000))
        _ = await registration.registerPending(budget: .seconds(8))
        #expect(await transport.requests(on: route).count == 1)

        await transport.on(route, noContent)
        await rig.clock.advance(by: .seconds(500))
        statuses = await registration.registerPending(budget: .seconds(8))
        #expect(await transport.requests(on: route).count == 2)
        #expect(statuses[id] == .registered)
    }

    @Test func aRevokedInstanceIsSkipped() async throws {
        let (rig, transport) = try await makeRig()
        try rig.tokens.removeToken(for: rig.instances[0].id)
        await transport.on(route, noContent)
        let statuses = await rig.registration().tokenChanged(token, budget: .seconds(8))
        #expect(statuses[rig.instances[0].id] == .needsPairing)
        #expect(await transport.requests.isEmpty)
    }

    @Test func aBudgetLeavesTheRecordPending() async throws {
        let (rig, transport) = try await makeRig()
        await transport.on(route, noContent)
        await transport.hold(route)
        let registration = rig.registration()
        let task = Task { await registration.tokenChanged(token, budget: .seconds(8)) }
        await transport.waitForRequests(1, on: route)
        await rig.clock.waitForSleepers(1)
        await rig.clock.advance(by: .seconds(9))
        let statuses = await task.value
        #expect(statuses[rig.instances[0].id] == .pending)
        let stored = try #require(try rig.files.read(InstanceFolders.widgetPushRecord(rig.instances[0].id)))
        #expect(String(decoding: stored, as: UTF8.self).contains(#""isRegistered":false"#))
    }

    @Test func noBuildRegistersNothing() async throws {
        let (rig, transport) = try await makeRig(build: nil)
        let statuses = await rig.registration(build: nil).tokenChanged(token, budget: .seconds(8))
        #expect(statuses[rig.instances[0].id] == .notEligible(.noKey))
        #expect(await transport.requests.isEmpty)
    }

    @Test func atMostFourAtOnce() async throws {
        let counter = ConcurrencyCounter()
        let (rig, _) = try await makeRig(
            bundles: Array(repeating: "com.example.yak", count: 8), transport: CountingTransport(counter: counter))
        let statuses = await rig.registration().tokenChanged(token, budget: .seconds(8))
        #expect(statuses.values.allSatisfy { $0 == .registered })
        #expect(statuses.count == 8)
        #expect(await counter.maximum == 4)
    }

    @Test(arguments: ["", "not-hex-at-all", String(repeating: "a", count: 10_000)])
    func hostileTokensAreRefusedLocally(_ hostile: String) async throws {
        let (rig, transport) = try await makeRig()
        let statuses = await rig.registration().tokenChanged(hostile, budget: .seconds(8))
        #expect(statuses[rig.instances[0].id] == .pending)
        #expect(try rig.files.read(InstanceFolders.widgetPushToken) == nil)
        #expect(try rig.files.read(InstanceFolders.widgetPushRecord(rig.instances[0].id)) == nil)
        #expect(await transport.requests.isEmpty)
    }

    @Test func oversizedRecordsAreIgnored() async throws {
        let (rig, transport) = try await makeRig()
        let big = Data(repeating: UInt8(ascii: " "), count: 5_000)
        try rig.files.write(big, to: InstanceFolders.widgetPushToken, protection: .untilFirstUnlock)
        try rig.files.write(
            big, to: InstanceFolders.widgetPushRecord(rig.instances[0].id), protection: .untilFirstUnlock)
        let registration = rig.registration()
        let statuses = await registration.registerPending(budget: .seconds(8))
        #expect(statuses[rig.instances[0].id] == .pending)
        #expect(await transport.requests.isEmpty)
        #expect(try rig.files.read(InstanceFolders.widgetPushToken) == nil)

        await transport.on(route, noContent)
        try rig.files.write(
            Data(#"{"token":"\#(token)"}"#.utf8), to: InstanceFolders.widgetPushToken, protection: .untilFirstUnlock)
        let after = await registration.registerPending(budget: .seconds(8))
        #expect(after[rig.instances[0].id] == .registered)
    }

    @Test func undecodableFilesAreRemoved() async throws {
        let (rig, transport) = try await makeRig()
        let junk = Data("not json".utf8)
        try rig.files.write(junk, to: InstanceFolders.widgetPushToken, protection: .untilFirstUnlock)
        try rig.files.write(
            junk, to: InstanceFolders.widgetPushRecord(rig.instances[0].id), protection: .untilFirstUnlock)
        _ = await rig.registration().registerPending(budget: .seconds(8))
        #expect(try rig.files.read(InstanceFolders.widgetPushToken) == nil)
        #expect(await transport.requests.isEmpty)
    }
}
