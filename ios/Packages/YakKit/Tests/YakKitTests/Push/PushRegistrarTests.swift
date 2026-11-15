import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let alertToken = String(repeating: "0123456789abcdef", count: 4)
private let newerToken = String(repeating: "fedcba9876543210", count: 4)
private let startToken = String(repeating: "00112233445566778899aabbccddeeff", count: 2)

private let alertRoute = "PUT push-tokens/alert"
private let startRoute = "PUT push-tokens/live_activity_start"
private let noContent = FakeTransport.Result.json(204, "")

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private func mePayload(bundleID: String = "com.example.yak", environment: String = "production", needsYou: Int = 2)
    throws -> Me
{
    var data = try Scenarios.data("scenario-me", setting: ["push", "bundleId"], toJSON: "\"\(bundleID)\"")
    data = try JSONSerialization.data(
        withJSONObject: {
            var object = try JSONSerialization.jsonObject(with: data) as? [String: Any] ?? [:]
            var push = object["push"] as? [String: Any] ?? [:]
            push["environment"] = environment
            object["push"] = push
            object["needsYouCount"] = needsYou
            return object
        }())
    return try JSONDecoder().decode(Me.self, from: data)
}

private struct Rig {
    let registrar: PushRegistrar
    let transport: ScriptedTransport
    let clock: ManualClock
    let files: InMemoryFileStore
    let store: InstanceStore
    let tokens: InMemoryTokenStore
    let environment: SessionEnvironment
    let first: Instance
    let second: Instance
}

private func makeEnvironment(
    transport: ScriptedTransport, clock: ManualClock, files: InMemoryFileStore
) -> SessionEnvironment {
    SessionEnvironment(
        http: transport, webSockets: FakeWebSocketServer(), files: files, clock: clock, makeID: { UUID() },
        appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
}

/// Two instances, `yak.example.com` and `other.example.com`, each updated from the given `me`.
private func makeRig(
    second: Me? = nil, first: Me? = nil,
    build: PushBuild? = PushBuild(bundleID: "com.example.yak", environment: .production),
    transport: ScriptedTransport = ScriptedTransport(), files: InMemoryFileStore = InMemoryFileStore(),
    clock: ManualClock = ManualClock(), tokens: InMemoryTokenStore = InMemoryTokenStore()
) async throws -> Rig {
    let store = InstanceStore(
        defaults: MemoryDefaults(), tokens: tokens, now: { Date(timeIntervalSince1970: 1_000) }, build: build)
    let one = try await store.add(
        url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person", token: "bearer-one")
    let two = try await store.add(
        url: URL(string: "https://other.example.com")!, name: "Other Yak", userName: "Test person",
        token: "bearer-two")
    try await store.update(one.id, from: first ?? mePayload())
    try await store.update(two.id, from: second ?? mePayload())
    let environment = makeEnvironment(transport: transport, clock: clock, files: files)
    let registrar = PushRegistrar(instances: store, tokens: tokens, environment: environment)
    return Rig(
        registrar: registrar, transport: transport, clock: clock, files: files, store: store, tokens: tokens,
        environment: environment, first: one, second: two)
}

private func requests(_ rig: Rig, _ route: String, host: String) async -> [URLRequest] {
    await rig.transport.requests(on: route).filter { $0.url?.host == host }
}

private func body(_ request: URLRequest) -> String {
    String(decoding: request.httpBody ?? Data(), as: UTF8.self)
}

private func record(_ rig: Rig, _ instance: Instance) throws -> PushRegistrationRecord? {
    guard let data = try rig.files.read("instances/\(instance.id.uuidString)/push-registration.json") else {
        return nil
    }
    return try JSONDecoder().decode(PushRegistrationRecord.self, from: data)
}

private typealias Statuses = StreamRecorder<[UUID: PushRegistrationStatus]>

private func waitFor(_ recorder: Statuses, _ instance: Instance, _ status: PushRegistrationStatus) async throws {
    let id = instance.id
    _ = try await recorder.next { $0[id] == status }
}

private func waitForAll(_ recorder: Statuses, _ instances: [Instance], _ status: PushRegistrationStatus) async throws {
    let ids = instances.map(\.id)
    _ = try await recorder.next { snapshot in ids.allSatisfy { snapshot[$0] == status } }
}

private func settle() async {
    for _ in 0..<200 { await Task.yield() }
}

@Suite struct PushRegistrarTests {
    @Test func registersTheTokenWithEveryAvailableInstance() async throws {
        let rig = try await makeRig()
        await rig.transport.on(alertRoute, noContent)
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitForAll(statuses, [rig.first, rig.second], .registered)

        for host in ["yak.example.com", "other.example.com"] {
            let sent = await requests(rig, alertRoute, host: host)
            #expect(sent.count == 1)
            #expect(body(sent[0]).contains(#""environment":"production""#) && body(sent[0]).contains(alertToken))
        }
        for instance in [rig.first, rig.second] {
            #expect(try record(rig, instance)?.entries["alert"]?.isRegistered == true)
            #expect(
                rig.files.protection(of: "instances/\(instance.id.uuidString)/push-registration.json")
                    == .untilFirstUnlock)
        }
        await rig.registrar.stop()
    }

    @Test func anotherBuildNeverRegistersAndSaysWhy() async throws {
        let rig = try await makeRig(
            second: try mePayload(bundleID: "com.example.other"), first: try mePayload(environment: "sandbox"))
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitFor(statuses, rig.first, .unavailable(.otherEnvironment(instance: .sandbox)))
        try await waitFor(statuses, rig.second, .unavailable(.otherBuild))
        await settle()
        #expect(await rig.transport.requests.isEmpty)
        await rig.registrar.stop()
    }

    @Test func anAvailableInstanceRegistersNextToAnotherBuild() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on(alertRoute, noContent)
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        let secondID = rig.second.id
        _ = try await statuses.next {
            $0[rig.first.id] == .registered && $0[secondID] == .unavailable(.otherBuild)
        }
        await settle()
        #expect(await requests(rig, alertRoute, host: "yak.example.com").count == 1)
        #expect(await requests(rig, alertRoute, host: "other.example.com").isEmpty)
        await rig.registrar.stop()
    }

    @Test func aBuildWithoutAnIdentityRegistersNothing() async throws {
        let rig = try await makeRig(build: nil)
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitFor(statuses, rig.first, .unavailable(.noKey))
        #expect(await rig.transport.requests.isEmpty)
        await rig.registrar.stop()
    }

    @Test func aServerRefusalIsStoredAndNotRetried() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on(
            alertRoute,
            .json(
                409,
                #"{"error":"push_environment_mismatch","message":"No.","details":{"expected":"sandbox","given":"production"}}"#
            ))
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        let refused = PushRegistrationStatus.refused(.environmentMismatch(expected: "sandbox", given: "production"))
        try await waitFor(statuses, rig.first, refused)
        await rig.clock.advance(by: .seconds(600))
        await settle()
        #expect(await requests(rig, alertRoute, host: "yak.example.com").count == 1)
        #expect(try record(rig, rig.first)?.entries["alert"]?.refusal != nil)

        await rig.registrar.setToken(newerToken, kind: .alert)
        await rig.transport.waitForRequests(2, on: alertRoute)
        #expect(await requests(rig, alertRoute, host: "yak.example.com").count == 2)
        await rig.registrar.stop()
    }

    @Test func aStoredRefusalSurvivesARelaunch() async throws {
        let rig = try await makeRig()
        await rig.transport.on(alertRoute, .json(409, #"{"error":"push_unavailable","message":"No."}"#))
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitFor(statuses, rig.first, .refused(.pushUnavailable))
        await rig.registrar.stop()

        let again = PushRegistrar(instances: rig.store, tokens: rig.tokens, environment: rig.environment)
        let afterwards = Statuses(await again.statuses())
        await again.start()
        try await waitFor(afterwards, rig.first, .refused(.pushUnavailable))
        await settle()
        #expect(await requests(rig, alertRoute, host: "yak.example.com").count == 1)
        await again.stop()
    }

    @Test func retriesWithTheOutboxBackoff() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        let unavailable = FakeTransport.Result.json(503, #"{"error":"unavailable","message":"Busy."}"#)
        await rig.transport.on(alertRoute, unavailable, unavailable, noContent)
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        await rig.clock.waitForSleeper(endingAt: .seconds(1))
        _ = try await statuses.next { if case .retrying = $0[rig.first.id] { true } else { false } }
        await rig.clock.advance(by: .seconds(1))
        await rig.clock.waitForSleeper(endingAt: .seconds(3))
        await rig.clock.advance(by: .seconds(2))
        try await waitFor(statuses, rig.first, .registered)
        #expect(await requests(rig, alertRoute, host: "yak.example.com").count == 3)
        await rig.registrar.stop()
    }

    @Test func waitsForTheNetworkWhileOffline() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on(alertRoute, .failure(.notConnectedToInternet), noContent)
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.networkChanged(isOnline: false)
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitFor(statuses, rig.first, .waitingForNetwork)
        #expect(rig.clock.sleeperDeadlines.isEmpty)
        let before = await requests(rig, alertRoute, host: "yak.example.com").count
        await rig.registrar.sync()
        await settle()
        let current = Statuses(await rig.registrar.statuses())
        try await waitFor(current, rig.first, .waitingForNetwork)

        await rig.registrar.networkChanged(isOnline: true)
        try await waitFor(statuses, rig.first, .registered)
        #expect(await requests(rig, alertRoute, host: "yak.example.com").count == before + 1)
        await rig.registrar.stop()
    }

    @Test func unfinishedWorkSurvivesARelaunch() async throws {
        let rig = try await makeRig()
        await rig.transport.on(alertRoute, noContent)
        await rig.transport.hold(alertRoute)
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        await rig.transport.waitForRequests(2, on: alertRoute)
        await rig.registrar.stop()
        #expect(try record(rig, rig.first)?.entries["alert"]?.isRegistered == false)

        await rig.transport.release(alertRoute)
        let again = PushRegistrar(instances: rig.store, tokens: rig.tokens, environment: rig.environment)
        let statuses = Statuses(await again.statuses())
        await again.start()
        try await waitForAll(statuses, [rig.first, rig.second], .registered)
        #expect(try record(rig, rig.first)?.entries["alert"]?.isRegistered == true)
        await again.stop()
    }

    @Test func theSameTokenIsNotSentTwice() async throws {
        let rig = try await makeRig()
        await rig.transport.on(alertRoute, noContent)
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitForAll(statuses, [rig.first, rig.second], .registered)
        await rig.registrar.setToken(alertToken, kind: .alert)
        await rig.registrar.sync()
        await settle()
        #expect(await rig.transport.requests(on: alertRoute).count == 2)
        await rig.registrar.stop()
    }

    @Test func aNewerTokenWinsOverAnOlderOne() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on(alertRoute, noContent)
        await rig.transport.hold(alertRoute)
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        await rig.transport.waitForRequests(1, on: alertRoute)
        await rig.registrar.setToken(String(repeating: "a", count: 64), kind: .alert)
        await rig.registrar.setToken(newerToken, kind: .alert)
        await rig.transport.release(alertRoute)
        try await waitFor(statuses, rig.first, .registered)

        let sent = await requests(rig, alertRoute, host: "yak.example.com")
        #expect(sent.count == 2)
        #expect(body(sent[0]).contains(alertToken) && body(sent[1]).contains(newerToken))
        #expect(try record(rig, rig.first)?.entries["alert"]?.token == newerToken)
        await rig.registrar.stop()
    }

    @Test func aRevokedInstanceStops() async throws {
        let rig = try await makeRig()
        await rig.transport.on(alertRoute, .json(401, #"{"error":"device_revoked","message":"Revoked."}"#))
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitFor(statuses, rig.first, .needsPairing)
        await settle()
        #expect(rig.clock.sleeperDeadlines.isEmpty)
        #expect(await requests(rig, alertRoute, host: "yak.example.com").count == 1)
        await rig.registrar.stop()
    }

    @Test func aTooOldServerIsRefusedNotPairing() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on(
            alertRoute, .json(200, "{}", headers: [ClientProtocol.headerName: "0"]))
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitFor(statuses, rig.first, .refused(.serverTooOld))
        await rig.clock.advance(by: .seconds(600))
        await settle()
        #expect(await rig.transport.requests(on: alertRoute).count == 1)
        await rig.registrar.sync()
        await rig.transport.waitForRequests(2, on: alertRoute)
        await rig.registrar.stop()
    }

    @Test func aCancelledAttemptSendsTheNewestStateAtOnce() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on(alertRoute, .failure(.cancelled), .failure(.cancelled), .failure(.cancelled), noContent)
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        await rig.transport.waitForRequests(3, on: alertRoute)
        await rig.clock.waitForSleeper(endingAt: .seconds(4))
        #expect(rig.clock.sleeperDeadlines == [.seconds(4)])
        await rig.registrar.setToken(newerToken, kind: .alert)
        await rig.clock.advance(by: .seconds(4))
        try await waitFor(statuses, rig.first, .registered)
        let sent = await rig.transport.requests(on: alertRoute)
        #expect(sent.count == 4)
        #expect(body(sent[3]).contains(newerToken))
        for snapshot in await statuses.values {
            #expect(snapshot[rig.first.id] != .waitingForNetwork)
            if case .retrying = snapshot[rig.first.id] { Issue.record("cancellation must not show retrying") }
        }
        await rig.registrar.stop()
    }

    @Test func unregisterSendsEachStoredTokenOnceWithoutRetry() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on(alertRoute, noContent)
        await rig.transport.on(startRoute, noContent)
        await rig.transport.on(
            "DELETE push-tokens/alert", .json(503, #"{"error":"unavailable","message":"Busy."}"#))
        await rig.transport.on("DELETE push-tokens/live_activity_start", noContent)
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        await rig.registrar.setToken(startToken, kind: .liveActivityStart)
        try await waitFor(statuses, rig.first, .registered)

        await rig.registrar.unregister(rig.first.id, budget: .seconds(5))
        #expect(await rig.transport.requests(on: "DELETE push-tokens/alert").count == 1)
        #expect(await rig.transport.requests(on: "DELETE push-tokens/live_activity_start").count == 1)
        #expect(try record(rig, rig.first) != nil)
        await rig.clock.advance(by: .seconds(60))
        await settle()
        #expect(await rig.transport.requests(on: "DELETE push-tokens/alert").count == 1)
        await rig.registrar.stop()
    }

    @Test func unregisterEndsAtTheBudgetAndSkipsQueuedWork() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on(alertRoute, noContent)
        await rig.transport.on("DELETE push-tokens/alert", noContent)
        await rig.transport.hold("DELETE push-tokens/alert")
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitFor(statuses, rig.first, .registered)

        let unregistering = Task { await rig.registrar.unregister(rig.first.id, budget: .seconds(5)) }
        await rig.transport.waitForRequests(1, on: "DELETE push-tokens/alert")
        await rig.clock.waitForSleeper(endingAt: .seconds(5))
        await rig.clock.advance(by: .seconds(5))
        await unregistering.value
        await rig.transport.release("DELETE push-tokens/alert")

        await rig.registrar.setToken(newerToken, kind: .alert)
        await settle()
        #expect(await requests(rig, alertRoute, host: "yak.example.com").count == 1)
        await rig.registrar.stop()
    }

    @Test func unregisterWithoutTokensSendsNothing() async throws {
        let rig = try await makeRig()
        await rig.registrar.start()
        await rig.registrar.unregister(rig.first.id, budget: .seconds(5))
        #expect(await rig.transport.requests.isEmpty)
        await rig.registrar.stop()
    }

    @Test func reportsTheActivitySetAndClearsIt() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on("PUT live-activities", noContent)
        await rig.registrar.start()
        await rig.registrar.reportActivities(["d0e515ef", "d0e515ef", "a1b2c3d4"], instance: rig.first.id)
        await rig.transport.waitForRequests(1, on: "PUT live-activities")
        await rig.registrar.reportActivities([], instance: rig.first.id)
        await rig.transport.waitForRequests(2, on: "PUT live-activities")
        let sent = await rig.transport.requests(on: "PUT live-activities")
        #expect(body(sent[0]) == #"{"threads":["a1b2c3d4","d0e515ef"]}"#)
        #expect(body(sent[1]) == #"{"threads":[]}"#)
        await settle()
        #expect(try record(rig, rig.first)?.activitiesReported == true)

        await rig.registrar.reportActivities([], instance: rig.first.id)
        await settle()
        #expect(await rig.transport.requests(on: "PUT live-activities").count == 2)
        await rig.registrar.stop()
    }

    @Test func theActivitySetIsCappedAtTen() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on("PUT live-activities", noContent)
        await rig.registrar.start()
        await rig.registrar.reportActivities((0..<12).map { String(format: "t%02d", $0) }, instance: rig.first.id)
        await rig.transport.waitForRequests(1, on: "PUT live-activities")
        let sent = await rig.transport.requests(on: "PUT live-activities")
        #expect(body(sent[0]).components(separatedBy: ",").count == 10)
        await rig.registrar.stop()
    }

    @Test func forgettingRemovesTheRecord() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        await rig.transport.on(alertRoute, noContent)
        await rig.transport.hold(alertRoute)
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        await rig.transport.waitForRequests(1, on: alertRoute)
        #expect(try record(rig, rig.first) != nil)

        await rig.registrar.forget(rig.first.id)
        #expect(try record(rig, rig.first) == nil)
        await rig.transport.release(alertRoute)
        await settle()
        #expect(try record(rig, rig.first) == nil)
        await rig.registrar.stop()
    }

    @Test func startRemovesTheRecordOfAnInstanceThatIsGone() async throws {
        let rig = try await makeRig()
        let gone = UUID()
        try rig.files.write(
            Data("{}".utf8), to: "instances/\(gone.uuidString)/push-registration.json", protection: .untilFirstUnlock)
        try rig.files.write(
            Data("x".utf8), to: "instances/\(gone.uuidString)/cache/keep.json", protection: .untilFirstUnlock)
        await rig.registrar.start()
        #expect(try rig.files.read("instances/\(gone.uuidString)/push-registration.json") == nil)
        #expect(try rig.files.read("instances/\(gone.uuidString)/cache/keep.json") != nil)
        await rig.registrar.stop()
    }

    @Test func anUnreadableRecordCountsAsNothingRegistered() async throws {
        let rig = try await makeRig(second: try mePayload(bundleID: "com.example.other"))
        try rig.files.write(
            Data("not json".utf8), to: "instances/\(rig.first.id.uuidString)/push-registration.json",
            protection: .untilFirstUnlock)
        await rig.transport.on(alertRoute, noContent)
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        try await waitFor(statuses, rig.first, .waitingForToken)
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitFor(statuses, rig.first, .registered)
        #expect(try record(rig, rig.first)?.entries["alert"]?.isRegistered == true)
        await rig.registrar.stop()
    }

    @Test func uiCallsMapRefusals() async throws {
        let rig = try await makeRig()
        await rig.transport.on(
            "POST push-tokens/alert/test", .json(409, #"{"error":"no_push_token","message":"No token."}"#))
        await rig.transport.on(
            "POST threads/d0e515ef/live-activity-channel", try .scenario(200, "scenario-push-channel"))
        await rig.transport.on("PUT notifications", try .scenario(200, "scenario-push-preferences-updated"))
        await rig.transport.on("GET notifications", try .scenario(200, "scenario-push-preferences"))

        await #expect(throws: PushProblem.noPushToken) {
            try await rig.registrar.sendTestNotification(instance: rig.first.id)
        }
        let channel = try await rig.registrar.liveActivityChannel(thread: "d0e515ef", instance: rig.first.id)
        #expect(channel == "c2NlbmFyaW8tY2hhbm5lbA")
        let updated = try await rig.registrar.setPreference(.turnFinished, isOn: false, instance: rig.first.id)
        #expect(updated.isOn(.turnFinished) == false)
        let sent = await rig.transport.requests(on: "PUT notifications")
        #expect(body(sent[0]) == #"{"events":{"turn_finished":false}}"#)
        let read = try await rig.registrar.preferences(instance: rig.first.id)
        #expect(read.isOn(.turnFinished))
        await #expect(throws: PushProblem.needsPairing) {
            try await rig.registrar.preferences(instance: UUID())
        }
    }

    @Test func backgroundRefreshUpdatesEveryInstanceAndSumsTheBadge() async throws {
        let rig = try await makeRig()
        let first = String(
            decoding: try Scenarios.data("scenario-me", setting: ["needsYouCount"], toJSON: "2"), as: UTF8.self)
        let third = String(
            decoding: try Scenarios.data("scenario-me", setting: ["needsYouCount"], toJSON: "3"), as: UTF8.self)
        await rig.transport.on(prefix: "GET me") { request in
            .json(200, request.url?.host == "yak.example.com" ? first : third)
        }
        let badge = await rig.registrar.refreshInstances(budget: .seconds(25))
        #expect(badge == 5)
        await rig.registrar.stop()
    }

    @Test func backgroundRefreshSkipsAFailingHostAndStopsAtTheBudget() async throws {
        let rig = try await makeRig()
        await rig.transport.on(prefix: "GET me") { request in
            request.url?.host == "yak.example.com"
                ? .json(500, #"{"error":"broken","message":"No."}"#)
                : .json(200, String(decoding: try! Scenarios.data("scenario-me"), as: UTF8.self))
        }
        #expect(await rig.registrar.refreshInstances(budget: .seconds(25)) == 4)

        await rig.transport.hold("GET me")
        let refreshing = Task { await rig.registrar.refreshInstances(budget: .seconds(1)) }
        await rig.transport.waitForRequests(3, on: "GET me")
        await rig.clock.waitForSleeper(endingAt: .seconds(1))
        await rig.clock.advance(by: .seconds(1))
        _ = await refreshing.value
        await rig.registrar.stop()
    }

    @Test func neverLogsOrShowsTheToken() async throws {
        let rig = try await makeRig()
        await rig.transport.on(
            alertRoute,
            .json(409, #"{"error":"push_rejected","message":"Rejected.","details":{"reason":"BadDeviceToken"}}"#))
        let statuses = Statuses(await rig.registrar.statuses())
        await rig.registrar.start()
        await rig.registrar.setToken(alertToken, kind: .alert)
        try await waitFor(statuses, rig.first, .refused(.rejected(reason: "BadDeviceToken", message: "Rejected.")))
        for snapshot in await statuses.values {
            #expect(!String(describing: snapshot).contains(alertToken))
        }
        await rig.registrar.stop()
    }

    @Test func hexIsLowercaseAndPadded() {
        #expect(PushRegistrar.hex(Data([0x00, 0xab, 0x0f])) == "00ab0f")
    }
}
