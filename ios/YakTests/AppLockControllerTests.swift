import Foundation
import Testing
import YakKit

@testable import Yak

private final class FakeAuthenticator: DeviceOwnerAuthenticator, @unchecked Sendable {
    private let lock = NSLock()
    private var answers: [DeviceOwnerAuthenticationResult]
    private var held: CheckedContinuation<DeviceOwnerAuthenticationResult, Never>?
    private(set) var asks = 0
    private(set) var cancels = 0
    var holds = false
    var capabilityValue = LockCapability(biometry: .faceID, hasPasscode: true)

    init(_ answers: [DeviceOwnerAuthenticationResult] = [.success]) { self.answers = answers }

    func capability() async -> LockCapability { lock.withLock { capabilityValue } }

    func authenticate(reason: String) async -> DeviceOwnerAuthenticationResult {
        let hold = lock.withLock { () -> Bool in
            asks += 1
            return holds
        }
        if hold {
            return await withCheckedContinuation { continuation in lock.withLock { held = continuation } }
        }
        return lock.withLock { answers.count > 1 ? answers.removeFirst() : answers[0] }
    }

    func cancel() {
        let continuation = lock.withLock { () -> CheckedContinuation<DeviceOwnerAuthenticationResult, Never>? in
            cancels += 1
            defer { held = nil }
            return held
        }
        continuation?.resume(returning: .cancelled)
    }

    func release(_ result: DeviceOwnerAuthenticationResult) {
        let continuation = lock.withLock { () -> CheckedContinuation<DeviceOwnerAuthenticationResult, Never>? in
            defer { held = nil }
            return held
        }
        continuation?.resume(returning: result)
    }

    var isHolding: Bool { lock.withLock { held != nil } }
}

private final class Store: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

@MainActor @Suite struct AppLockControllerTests {
    private func make(
        on: Bool, _ authenticator: FakeAuthenticator = FakeAuthenticator(), clock: ManualClock = ManualClock(),
        coversWhenActive: Bool = false
    ) -> (AppLockController, PrivacySettings) {
        let settings = PrivacySettings(store: Store())
        settings.appLockOn = on
        let controller = AppLockController(
            settings: settings, authenticator: authenticator, clock: clock, coversWhenActive: coversWhenActive)
        return (controller, settings)
    }

    @Test func launchIsLockedWhenOn() {
        #expect(make(on: true).0.isLocked)
        #expect(!make(on: false).0.isLocked)
    }

    @Test func unlockSucceeds() async {
        let fake = FakeAuthenticator([.success])
        let (controller, _) = make(on: true, fake)
        await controller.launched()
        #expect(!controller.isLocked)
        #expect(fake.asks == 1)
        #expect(controller.unlockCount == 1)
    }

    @Test func aCancelLeavesItLockedAndDoesNotAskAgain() async {
        let fake = FakeAuthenticator([.cancelled, .success])
        let (controller, _) = make(on: true, fake)
        await controller.launched()
        #expect(controller.isLocked && fake.asks == 1)
        await controller.appBecameActive()
        #expect(controller.isLocked)
        #expect(controller.unlock == .idle)
        #expect(fake.asks == 1)
        await controller.requestUnlock()
        #expect(fake.asks == 2)
        #expect(!controller.isLocked)
    }

    @Test func aFailureShowsAndCanBeRetried() async {
        let fake = FakeAuthenticator([.failed, .success])
        let (controller, _) = make(on: true, fake)
        await controller.launched()
        #expect(controller.unlock == .failed && controller.isLocked)
        #expect(controller.failureCount == 1)
        await controller.requestUnlock()
        #expect(!controller.isLocked)
    }

    @Test func oneAuthenticationAtATime() async {
        let fake = FakeAuthenticator()
        fake.holds = true
        let (controller, _) = make(on: true, fake)
        let first = Task { await controller.requestUnlock() }
        while !fake.isHolding { await Task.yield() }
        await controller.requestUnlock()
        #expect(fake.asks == 1)
        fake.release(.success)
        await first.value
        #expect(!controller.isLocked)
    }

    @Test func goingToTheBackgroundCancelsTheAsk() async {
        let fake = FakeAuthenticator()
        fake.holds = true
        let (controller, _) = make(on: true, fake)
        let ask = Task { await controller.requestUnlock() }
        while !fake.isHolding { await Task.yield() }
        controller.appEnteredBackground()
        await ask.value
        #expect(fake.cancels == 1)
        #expect(controller.isLocked)
        #expect(controller.unlock == .idle)
    }

    @Test func fiveMinutesAwayAsksAgain() async {
        let fake = FakeAuthenticator([.success, .success])
        let clock = ManualClock()
        let (controller, _) = make(on: true, fake, clock: clock)
        await controller.launched()
        #expect(!controller.isLocked)
        controller.appEnteredBackground()
        clock.advance(.seconds(300))
        await controller.appBecameActive()
        #expect(fake.asks == 2)
        #expect(controller.unlockCount == 2)
        controller.appEnteredBackground()
        clock.advance(.seconds(299))
        await controller.appBecameActive()
        #expect(fake.asks == 2)
    }

    @Test func turningOnNeedsTheOwner() async {
        let fake = FakeAuthenticator([.cancelled, .success])
        let (controller, settings) = make(on: false, fake)
        await controller.refreshCapability()
        await controller.setOn(true)
        #expect(!settings.appLockOn && !controller.policy.isOn && controller.toggle == .idle)
        await controller.setOn(true)
        #expect(settings.appLockOn && controller.policy.isOn && !controller.isLocked)
    }

    @Test func aFailedConfirmationShowsAndStaysOff() async {
        let (controller, settings) = make(on: false, FakeAuthenticator([.failed]))
        await controller.refreshCapability()
        await controller.setOn(true)
        #expect(controller.toggle == .failed && !settings.appLockOn)
    }

    @Test func turningOffNeedsNothing() async {
        let fake = FakeAuthenticator()
        let (controller, settings) = make(on: true, fake)
        await controller.setOn(false)
        #expect(!settings.appLockOn && !controller.policy.isOn && !controller.isLocked)
        #expect(fake.asks == 0)
    }

    @Test func noPasscodeDisablesTheSwitch() async {
        let fake = FakeAuthenticator()
        fake.capabilityValue = LockCapability(biometry: .none, hasPasscode: false)
        let (controller, settings) = make(on: false, fake)
        await controller.refreshCapability()
        #expect(controller.method == nil)
        await controller.setOn(true)
        #expect(fake.asks == 0 && !settings.appLockOn)
    }

    @Test func aRemovedPasscodeTurnsTheLockOff() async {
        let fake = FakeAuthenticator()
        fake.capabilityValue = LockCapability(biometry: .none, hasPasscode: false)
        let (controller, settings) = make(on: true, fake)
        await controller.launched()
        #expect(!controller.policy.isOn && !controller.isLocked && !settings.appLockOn)
        #expect(controller.policy.turnedOffForMissingPasscode)
        #expect(fake.asks == 0)
        #expect(controller.showsLockScreen)
        controller.dismissPasscodeRemoved()
        #expect(!controller.showsLockScreen)
    }

    @Test func everySceneGetsItsOwnCover() {
        let (controller, _) = make(on: true)
        var scenes = AppLockScenes<String>()
        scenes.connected("a", isActive: false)
        scenes.connected("b", isActive: true)
        // Locked at launch: both show the lock screen.
        #expect(scenes.shows("a", controller: controller) == .lockScreen)
        #expect(scenes.shows("b", controller: controller) == .lockScreen)
        scenes.disconnected("a")
        #expect(scenes.scenes == ["b"])
        scenes.phaseChanged("zzz", isActive: true)
        #expect(scenes.scenes == ["b"])
    }

    @Test func eachSceneCoversByItsOwnPhase() async {
        let (controller, _) = make(on: true, FakeAuthenticator([.success]))
        await controller.launched()
        var scenes = AppLockScenes<String>()
        scenes.connected("a", isActive: false)
        scenes.connected("b", isActive: true)
        #expect(scenes.shows("a", controller: controller) == .cover)
        #expect(scenes.shows("b", controller: controller) == .nothing)
    }

    @Test func theCoverFlagIgnoresThePhase() async {
        let (on, _) = make(on: true, coversWhenActive: true)
        await on.launched()
        #expect(on.coversContent(sceneIsActive: true))
        let (off, _) = make(on: false, coversWhenActive: true)
        #expect(!off.coversContent(sceneIsActive: true))
    }
}
