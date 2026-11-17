import Foundation
import Observation
import YakKit

/// The app lock: owns the policy, asks the device owner to authenticate and tells the windows what to show.
@MainActor @Observable
final class AppLockController {
    enum UnlockState: Equatable { case idle, authenticating, failed }
    enum ToggleState: Equatable { case idle, confirming, failed }

    @ObservationIgnored private let settings: PrivacySettings
    @ObservationIgnored private let authenticator: any DeviceOwnerAuthenticator
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private let coversWhenActive: Bool
    @ObservationIgnored private var launchAskPending: Bool

    private(set) var policy: AppLockPolicy
    private(set) var method: AppLockMethod?
    private(set) var unlock: UnlockState = .idle
    private(set) var toggle: ToggleState = .idle
    /// True from the moment the lock found no passcode until the person taps Continue on the lock screen.
    private(set) var showsPasscodeRemoved = false
    /// Counters that views turn into haptics.
    private(set) var unlockCount = 0
    private(set) var failureCount = 0

    var isLocked: Bool { policy.isLocked }
    var showsLockScreen: Bool { policy.isLocked || showsPasscodeRemoved }

    /// The policy starts locked when the lock is on, so the first frame is already covered.
    init(
        settings: PrivacySettings, authenticator: any DeviceOwnerAuthenticator, clock: any YakClock,
        backgroundLimit: TimeInterval = AppLockPolicy.backgroundLimit, coversWhenActive: Bool = false
    ) {
        self.settings = settings
        self.authenticator = authenticator
        self.clock = clock
        self.coversWhenActive = coversWhenActive
        var policy = AppLockPolicy(isOn: settings.appLockOn, backgroundLimit: backgroundLimit)
        launchAskPending = policy.handle(.launched)
        self.policy = policy
    }

    func coversContent(sceneIsActive: Bool) -> Bool {
        AppLockPolicy.coversContent(
            isOn: policy.isOn, isLocked: policy.isLocked, sceneIsActive: coversWhenActive ? false : sceneIsActive)
    }

    /// Reads what the device can do; a lock that is on with no passcode now turns itself off.
    func refreshCapability() async {
        method = AppLockMethod.make(await authenticator.capability())
        guard policy.isOn, method == nil else { return }
        policy.handle(.passcodeRemoved)
        settings.appLockOn = false
        launchAskPending = false
        showsPasscodeRemoved = true
    }

    /// Once, at launch: reads the capability and asks if the lock came up locked.
    func launched() async {
        await refreshCapability()
        guard launchAskPending else { return }
        launchAskPending = false
        await requestUnlock()
    }

    func appEnteredBackground() {
        policy.handle(.enteredBackground(clock.now()))
        authenticator.cancel()
    }

    func appBecameActive() async {
        await refreshCapability()
        if policy.handle(.becameActive(clock.now())) { await requestUnlock() }
    }

    func requestUnlock() async {
        guard policy.isLocked, unlock != .authenticating else { return }
        unlock = .authenticating
        switch await authenticator.authenticate(reason: String(localized: "lock.reason")) {
        case .success:
            policy.handle(.unlocked)
            unlock = .idle
            unlockCount += 1
        case .cancelled:
            unlock = .idle
        case .failed:
            unlock = .failed
            failureCount += 1
        case .passcodeNotSet:
            policy.handle(.passcodeRemoved)
            settings.appLockOn = false
            unlock = .idle
            showsPasscodeRemoved = true
        }
    }

    /// Turning on needs one successful authentication; turning off needs none.
    func setOn(_ isOn: Bool) async {
        guard isOn else {
            settings.appLockOn = false
            policy.handle(.turnedOff)
            toggle = .idle
            return
        }
        guard method != nil, toggle != .confirming, !policy.isOn else { return }
        toggle = .confirming
        switch await authenticator.authenticate(reason: String(localized: "lock.turnOnReason")) {
        case .success:
            settings.appLockOn = true
            policy.handle(.turnedOn)
            toggle = .idle
            unlockCount += 1
        case .cancelled:
            toggle = .idle
        case .failed, .passcodeNotSet:
            toggle = .failed
            failureCount += 1
            await refreshCapability()
        }
    }

    func dismissPasscodeRemoved() { showsPasscodeRemoved = false }

    /// Settings has said the lock turned itself off.
    func acknowledgeTurnedOff() { policy.acknowledgeTurnedOff() }
}

/// One lock window per connected window scene; generic over the scene's key so tests use plain values.
@MainActor struct AppLockScenes<SceneKey: Hashable> {
    enum Shows: Equatable { case nothing, cover, lockScreen }

    private var activity: [SceneKey: Bool] = [:]

    var scenes: Set<SceneKey> { Set(activity.keys) }

    mutating func connected(_ scene: SceneKey, isActive: Bool) { activity[scene] = isActive }

    mutating func phaseChanged(_ scene: SceneKey, isActive: Bool) {
        guard activity[scene] != nil else { return }
        activity[scene] = isActive
    }

    mutating func disconnected(_ scene: SceneKey) { activity[scene] = nil }

    func shows(_ scene: SceneKey, controller: AppLockController) -> Shows {
        guard let isActive = activity[scene] else { return .nothing }
        if controller.showsLockScreen { return .lockScreen }
        return controller.coversContent(sceneIsActive: isActive) ? .cover : .nothing
    }
}
