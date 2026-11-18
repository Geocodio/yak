import Foundation
import Testing
import YakKit

@testable import Yak

/// A clock whose sleeps end at once, so a test does not wait out a delay.
struct ImmediateClock: YakClock {
    func now() -> Date { Date() }
    func sleep(for duration: Duration) async throws { try Task.checkCancellation() }
}

@MainActor private func makeModel(_ rig: PushRig, isOnline: Bool = true) -> NotificationExplanationModel {
    NotificationExplanationModel(
        request: NotificationExplanationRequest(id: UUID(), instanceName: "Example Yak"), push: rig.controller,
        isOnline: { isOnline }, clock: ImmediateClock())
}

@MainActor @Suite struct NotificationExplanationModelTests {
    @Test func theSystemIsAskedOnlyAfterTurnOn() async throws {
        let rig = try await makePushRig(authorization: .notDetermined)
        await rig.controller.didPair(rig.home)
        #expect(rig.controller.explanation?.instanceName == "Example Yak")
        #expect(rig.permission.requestCount == 0)
        let notNow = makeModel(rig)
        notNow.notNow()
        #expect(rig.controller.explanation == nil)
        #expect(rig.permission.requestCount == 0)
        await rig.controller.didPair(rig.home)
        let model = makeModel(rig)
        await model.turnOn()
        await model.turnOn()
        #expect(rig.permission.requestCount == 1)
    }

    @Test func aBuildThatCannotPushDoesNotExplain() async throws {
        let rig = try await makePushRig(authorization: .notDetermined)
        let other = try JSONDecoder().decode(
            Me.self, from: Data(meJSON.replacingOccurrences(of: "com.example.yak", with: "com.example.other").utf8))
        try await rig.store.update(rig.home.id, from: other)
        await rig.controller.didPair(rig.home)
        #expect(rig.controller.explanation == nil)
        #expect(rig.permission.requestCount == 0)
    }

    @Test(arguments: [
        NotificationAuthorization.notDetermined, .denied, .authorized, .provisional, .ephemeral,
    ])
    func startAndBecomingActiveNeverAsk(state: NotificationAuthorization) async throws {
        let rig = try await makePushRig(authorization: state)
        await rig.controller.start()
        await rig.controller.appBecameActive()
        #expect(rig.permission.requestCount == 0)
    }

    @Test func aGrantRegistersAndCloses() async throws {
        let rig = try await makePushRig(authorization: .notDetermined)
        await rig.controller.didPair(rig.home)
        let model = makeModel(rig)
        await model.turnOn()
        #expect(model.phase == .on)
        #expect(rig.permission.registerCount == 1)
        #expect(await eventually { rig.controller.explanation == nil })
    }

    @Test func aDenialStaysOpen() async throws {
        let rig = try await makePushRig(authorization: .notDetermined)
        rig.permission.setResult(.denied)
        await rig.controller.didPair(rig.home)
        let model = makeModel(rig)
        await model.turnOn()
        #expect(model.phase == .off(failed: false))
        #expect(rig.permission.registerCount == 0)
        #expect(rig.controller.explanation != nil)
        model.finish()
        #expect(rig.controller.explanation == nil)
    }

    @Test func aFailedRequestSaysSo() async throws {
        let rig = try await makePushRig(authorization: .notDetermined)
        rig.permission.setResult(.failed)
        let model = makeModel(rig)
        await model.turnOn()
        #expect(model.phase == .off(failed: true))
    }

    @Test func alreadyAllowedRegistersWithoutTheScreen() async throws {
        let rig = try await makePushRig(authorization: .authorized)
        await rig.controller.didPair(rig.home)
        #expect(rig.controller.explanation == nil)
        #expect(rig.permission.registerCount == 1)
        #expect(rig.permission.requestCount == 0)
    }

    @Test func offlineTheScreenSaysItRegistersLater() async throws {
        let rig = try await makePushRig(authorization: .notDetermined)
        #expect(makeModel(rig, isOnline: false).isOffline)
        #expect(!makeModel(rig, isOnline: true).isOffline)
    }
}
