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

    @ObservationIgnored private var lockWindows: AnyObject?
    @ObservationIgnored private var backgroundCount = 0
    @ObservationIgnored private var deferred: (@MainActor () async -> Void)?
    private(set) var policy: AppLockPolicy
    /// True while the settings store cannot be read. The content stays covered, because the lock may be on.
    private(set) var storeUnreadable: Bool
    private(set) var method: AppLockMethod?
    private(set) var unlock: UnlockState = .idle
    private(set) var toggle: ToggleState = .idle
    /// True from the moment the lock found no passcode until the person taps Continue on the lock screen.
    private(set) var showsPasscodeRemoved = false
    /// Counters that views turn into haptics.
    private(set) var unlockCount = 0
    private(set) var failureCount = 0

    var isLocked: Bool { policy.isLocked }
    /// True while nothing in the app may react to the keyboard: locked, or covered because the lock is unknown.
    var blocksInput: Bool { showsLockScreen || storeUnreadable }
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
        storeUnreadable = !settings.isReadable
        var policy = AppLockPolicy(isOn: settings.appLockOn, backgroundLimit: backgroundLimit)
        launchAskPending = policy.handle(.launched)
        self.policy = policy
    }

    /// The one place links, taps and continuations pass: they run now, or after the unlock while input is blocked.
    /// Only the newest waiting one is kept, and it is never shown under the lock.
    func whenOpen(_ action: @escaping @MainActor () async -> Void) {
        guard blocksInput else {
            Task { await action() }
            return
        }
        deferred = action
    }

    private func runDeferredIfOpen() {
        guard !blocksInput, let action = deferred else { return }
        deferred = nil
        Task { await action() }
    }

    /// Keeps the one set of lock windows alive and makes it only the first time it is asked.
    func startWindowsOnce(_ make: () -> AnyObject) {
        if lockWindows == nil { lockWindows = make() }
    }

    func coversContent(sceneIsActive: Bool) -> Bool {
        storeUnreadable
            || AppLockPolicy.coversContent(
                isOn: policy.isOn, isLocked: policy.isLocked, sceneIsActive: coversWhenActive ? false : sceneIsActive)
    }

    /// Reads what the device can do; a lock that is on with no passcode now turns itself off.
    /// An unknown reading changes nothing: the lock never turns off without a confirmed missing passcode.
    func refreshCapability() async {
        guard case .known(let capability) = await authenticator.capability() else { return }
        method = AppLockMethod.make(capability)
        guard policy.isOn, method == nil else { return }
        policy.handle(.passcodeRemoved)
        settings.appLockOn = false
        launchAskPending = false
        showsPasscodeRemoved = true
        runDeferredIfOpen()
    }

    /// Reads the stored choice again. A store that was unreadable at launch may say the lock is on now: the policy
    /// then locks and the app asks the owner. Returns true when it just locked.
    @discardableResult
    func reloadSettings() -> Bool {
        guard settings.reload() else {
            storeUnreadable = true
            return false
        }
        storeUnreadable = false
        defer { runDeferredIfOpen() }
        guard settings.appLockOn, !policy.isOn else { return false }
        policy.handle(.turnedOn)
        launchAskPending = policy.handle(.launched)
        return launchAskPending
    }

    /// Once, at launch: reads the capability and asks if the lock came up locked.
    func launched() async {
        reloadSettings()
        await refreshCapability()
        guard launchAskPending else { return }
        launchAskPending = false
        await requestUnlock()
    }

    func appEnteredBackground() {
        backgroundCount += 1
        policy.handle(.enteredBackground(clock.now()))
        authenticator.cancel()
    }

    /// Decides the time-based relock at once, with no suspension, so content never shows after the limit.
    /// Returns true when the policy just locked and the app should ask the owner by itself.
    func relockIfDue() -> Bool {
        let reloaded = reloadSettings()
        let locked = policy.handle(.becameActive(clock.now())) || reloaded
        if locked { launchAskPending = true }
        return locked
    }

    /// The device was unlocked for the first time since it started: the store can be read now.
    func protectedDataBecameAvailable(isActive: Bool) async {
        guard reloadSettings(), isActive else { return }
        await finishBecomingActive(askIfLocked: true)
    }

    func finishBecomingActive(askIfLocked ask: Bool) async {
        await refreshCapability()
        if (ask || launchAskPending) && policy.isLocked {
            launchAskPending = false
            await requestUnlock()
        }
    }

    func appBecameActive() async {
        let ask = relockIfDue()
        await finishBecomingActive(askIfLocked: ask)
    }

    func requestUnlock() async {
        guard policy.isLocked, unlock != .authenticating else { return }
        unlock = .authenticating
        let backgroundsBefore = backgroundCount
        switch await authenticator.authenticate(reason: String(localized: "lock.reason")) {
        case .success where backgroundCount != backgroundsBefore:
            // The app went to the background during the ask: the time away counts, so stay locked and ask again.
            unlock = .idle
            launchAskPending = true
        case .success:
            policy.handle(.unlocked)
            unlock = .idle
            unlockCount += 1
            runDeferredIfOpen()
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
            runDeferredIfOpen()
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

    func dismissPasscodeRemoved() {
        showsPasscodeRemoved = false
        runDeferredIfOpen()
    }

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
