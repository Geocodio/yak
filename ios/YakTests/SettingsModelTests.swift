import Foundation
import Testing
import YakKit

@testable import Yak

/// A clock whose sleeps wait until the test advances it; a cancelled sleep throws.
private final class StepClock: YakClock, @unchecked Sendable {
    private let lock = NSLock()
    private var elapsed: Duration = .zero
    private var waiters: [UUID: (deadline: Duration, continuation: CheckedContinuation<Void, any Error>)] = [:]

    var sleeperCount: Int { lock.withLock { waiters.count } }

    func now() -> Date { Date(timeIntervalSince1970: 1_794_000_000) }

    func sleep(for duration: Duration) async throws {
        let id = UUID()
        try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, any Error>) in
                lock.lock()
                waiters[id] = (elapsed + duration, continuation)
                lock.unlock()
            }
        } onCancel: {
            lock.lock()
            let waiting = waiters.removeValue(forKey: id)
            lock.unlock()
            waiting?.continuation.resume(throwing: CancellationError())
        }
    }

    func advance(by duration: Duration) {
        lock.lock()
        elapsed += duration
        let due = waiters.filter { $0.value.deadline <= elapsed }
        for key in due.keys { waiters[key] = nil }
        lock.unlock()
        for waiting in due.values { waiting.continuation.resume() }
    }
}

private let preferencesJSON =
    #"{"events":{"needs_you":true,"turn_finished":true,"stack_usage":false,"pull_request":true}}"#
private let updatedJSON =
    #"{"events":{"needs_you":true,"turn_finished":false,"stack_usage":false,"pull_request":true}}"#
private let unavailableJSON = #"{"error":"unavailable","message":"Yak is busy. Try again in a moment."}"#

@MainActor
private struct SettingsRig {
    let rig: PushRig
    let app: AppModel
    let model: SettingsModel
    let clock: StepClock
    let removed: Removals

    final class Removals: @unchecked Sendable {
        var instances: [Instance] = []
        var gate: CheckedContinuation<Void, Never>?
    }
}

@MainActor
private func makeSettingsRig(isAvailable: Bool = true) async throws -> SettingsRig {
    let rig = try await makePushRig()
    await rig.transport.on("GET me", status: 200, body: meJSON)
    await rig.transport.on("GET notifications", status: 200, body: preferencesJSON)
    await rig.transport.on("PUT notifications", status: 200, body: updatedJSON)
    await rig.transport.on("POST push-tokens/alert/test", status: 200, body: #"{"status":"sent"}"#)
    let transport = rig.transport
    let app = AppModel(
        instances: rig.store, tokens: rig.tokens,
        makeClient: { url in
            APIClient(
                baseURL: url, token: nil, transport: transport, appVersion: "1.0.0", systemDescription: "Test")
        },
        push: rig.controller)
    let clock = StepClock()
    let removed = SettingsRig.Removals()
    let model = SettingsModel(
        app: app, push: rig.controller, registrar: rig.registrar, active: rig.home, clock: clock,
        removeInstance: { instance in
            removed.instances.append(instance)
            if removed.gate == nil, instance.id == rig.other.id {
                await withCheckedContinuation { removed.gate = $0 }
            }
            try? await rig.store.remove(instance.id)
        })
    return SettingsRig(rig: rig, app: app, model: model, clock: clock, removed: removed)
}

@MainActor @Suite struct SettingsModelTests {
    @Test func switchesLoadAndSaveOneAtATime() async throws {
        let rig = try await makeSettingsRig()
        await rig.model.start()
        #expect(rig.model.switches.load == .loaded)
        #expect(rig.model.switches.isOn(.turnFinished))
        #expect(!rig.model.switches.isOn(.stackUsage))
        await rig.rig.transport.hold("PUT notifications")
        let first = Task { await rig.model.toggle(.turnFinished, to: false) }
        #expect(await eventually { rig.model.switches.isSaving(.turnFinished) })
        #expect(!rig.model.switches.isOn(.turnFinished))
        await rig.model.toggle(.turnFinished, to: true)
        let puts = await rig.rig.transport.routes().filter { $0 == "PUT notifications" }
        #expect(puts.count == 1)
        await rig.rig.transport.release()
        await first.value
        #expect(!rig.model.switches.isSaving(.turnFinished))
        #expect(!rig.model.switches.isOn(.turnFinished))
    }

    @Test func aFailedSaveReturnsTheSwitch() async throws {
        let rig = try await makeSettingsRig()
        await rig.model.start()
        await rig.rig.transport.on("PUT notifications", status: 503, body: unavailableJSON)
        await rig.model.toggle(.turnFinished, to: false)
        #expect(rig.model.switches.isOn(.turnFinished))
        #expect(rig.model.switches.problem?.event == .turnFinished)
        #expect(rig.model.switches.problem?.problem == .unavailable)
        #expect(rig.model.refusalCount == 1)
    }

    @Test func slowAfterEightSeconds() async throws {
        let rig = try await makeSettingsRig()
        await rig.rig.transport.hold("GET notifications")
        let loading = Task { await rig.model.start() }
        #expect(await eventually { rig.clock.sleeperCount == 1 })
        #expect(!rig.model.isSlow)
        rig.clock.advance(by: .seconds(8))
        #expect(await eventually { rig.model.isSlow })
        await rig.rig.transport.release()
        await loading.value
        #expect(!rig.model.isSlow)
        #expect(rig.model.switches.load == .loaded)
    }

    @Test func offlineReloadsWhenTheNetworkReturns() async throws {
        let rig = try await makeSettingsRig()
        await rig.rig.transport.setOffline(true)
        await rig.model.start()
        #expect(rig.model.switches.load == .failed(.offline))
        await rig.rig.transport.setOffline(false)
        await rig.model.networkReturned()
        #expect(rig.model.switches.load == .loaded)
    }

    @Test func theTestShowsSentThenIdle() async throws {
        let rig = try await makeSettingsRig()
        await rig.model.start()
        await rig.model.sendTest()
        #expect(rig.model.test == .sent)
        #expect(await eventually { rig.clock.sleeperCount == 1 })
        rig.clock.advance(by: .seconds(6))
        #expect(await eventually { rig.model.test == .idle })
    }

    @Test func everyTestRefusalHasItsState() async throws {
        let rig = try await makeSettingsRig()
        await rig.model.start()
        let cases: [(Int, String, PushProblem)] = [
            (409, #"{"error":"no_push_token","message":"None."}"#, .noPushToken),
            (409, #"{"error":"push_unavailable","message":"None."}"#, .pushUnavailable),
            (
                409, #"{"error":"push_rejected","message":"Apple said no.","details":{"reason":"BadDeviceToken"}}"#,
                .rejected(reason: "BadDeviceToken", message: "Apple said no.")
            ),
            (429, #"{"error":"rate_limited","message":"Slow down."}"#, .rateLimited(retryAfterSeconds: nil)),
        ]
        for (status, body, problem) in cases {
            await rig.rig.transport.on("POST push-tokens/alert/test", status: status, body: body)
            await rig.model.sendTest()
            #expect(rig.model.test == .failed(problem))
        }
        #expect(
            SettingsText.test(.noPushToken)
                == "This device has not registered for notifications yet. Turn them on first.")
        #expect(SettingsText.test(.pushUnavailable) == "This Yak cannot send notifications.")
        #expect(SettingsText.test(.rejected(reason: "", message: "Apple said no.")) == "Apple said no.")
        #expect(SettingsText.test(.offline) == "You are offline. Try again when the connection returns.")
        #expect(SettingsText.test(.unavailable) == "Apple did not answer in time. Try again.")
        #expect(
            SettingsText.test(.rateLimited(retryAfterSeconds: nil))
                == "Too many tests in a row. Wait a minute and try again.")
        await rig.rig.transport.setOffline(true)
        await rig.model.sendTest()
        #expect(rig.model.test == .failed(.offline))
        #expect(rig.model.refusalCount == 5)
    }

    @Test func stopCancelsTheRequests() async throws {
        let rig = try await makeSettingsRig()
        await rig.rig.transport.hold("GET notifications")
        let loading = Task { await rig.model.start() }
        #expect(await eventually { await rig.rig.transport.routes().contains("GET notifications") })
        rig.model.stop()
        await rig.rig.transport.release()
        await loading.value
        #expect(rig.model.switches.load == .loading)
    }

    @Test func removingShowsProgressAndIsNotRepeated() async throws {
        let rig = try await makeSettingsRig()
        await rig.model.start()
        let other = rig.rig.other
        let removing = Task { await rig.model.remove(other) }
        #expect(await eventually { rig.model.removing.contains(other.id) })
        await rig.model.remove(other)
        #expect(rig.removed.instances.map(\.id) == [other.id])
        rig.removed.gate?.resume()
        await removing.value
        #expect(rig.model.removing.isEmpty)
        #expect(!rig.model.instances.contains { $0.id == other.id })
    }

    @Test func everyStatusHasItsSubtitle() {
        let host = "yak.example.com"
        func subtitle(_ status: PushRegistrationStatus) -> String {
            SettingsText.subtitle(status: status, availability: nil, host: host)
        }
        #expect(subtitle(.registered) == "yak.example.com, push on")
        #expect(subtitle(.registering) == "yak.example.com, setting up push")
        #expect(subtitle(.waitingForToken) == "yak.example.com, notifications are off on this device")
        #expect(subtitle(.waitingForNetwork) == "Push is set up when the connection returns.")
        #expect(subtitle(.retrying(at: Date(), problem: .unavailable)) == "Push is not set up yet. Yak keeps trying.")
        #expect(subtitle(.refused(.serverTooOld)) == "Update Yak on the server.")
        #expect(subtitle(.refused(.pushUnavailable)) == "Push was refused. This Yak cannot send notifications.")
        #expect(
            subtitle(.refused(.rejected(reason: "", message: "Apple said no."))) == "Push was refused. Apple said no.")
        #expect(subtitle(.needsPairing) == "Pair again to use it.")
        #expect(subtitle(.unavailable(.noKey)) == "No push. This Yak has no notification key.")
        #expect(subtitle(.unavailable(.otherBuild)) == "No push. This build has no key for it.")
        #expect(
            subtitle(.unavailable(.otherEnvironment(instance: .production)))
                == "No push. This Yak sends to TestFlight builds only.")
        #expect(
            subtitle(.unavailable(.otherEnvironment(instance: .sandbox)))
                == "No push. This Yak sends to development builds only.")
        #expect(
            subtitle(.unavailable(.otherEnvironment(instance: nil)))
                == "No push. This Yak names an unknown environment.")
        let problems: [PushRegistrationStatus] = [
            .waitingForNetwork, .retrying(at: Date(), problem: .offline), .refused(.serverTooOld), .needsPairing,
            .unavailable(.noKey), .unavailable(.otherBuild), .unavailable(.otherEnvironment(instance: nil)),
        ]
        for status in problems { #expect(!subtitle(status).contains(host)) }
        #expect(
            SettingsText.subtitle(status: nil, availability: .otherBuild, host: host)
                == "No push. This build has no key for it.")
        #expect(SettingsText.subtitle(status: nil, availability: nil, host: host) == host)
    }

    @Test func theAccessibilityLabelNamesTheHostOnce() async throws {
        let rig = try await makeSettingsRig()
        await rig.model.start()
        let label = rig.model.accessibilityLabel(of: rig.rig.home)
        #expect(label.components(separatedBy: "yak.example.com").count - 1 == 1)
        #expect(label.hasPrefix("Example Yak, "))
    }
}
