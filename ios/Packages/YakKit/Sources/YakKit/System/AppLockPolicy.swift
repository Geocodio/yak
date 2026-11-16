import Foundation

/// The biometry the device reports; the wording never assumes a device model.
public enum LockBiometry: Equatable, Sendable { case faceID, touchID, opticID, none, other }

public struct LockCapability: Equatable, Sendable {
    public let biometry: LockBiometry
    public let hasPasscode: Bool

    public init(biometry: LockBiometry, hasPasscode: Bool) {
        self.biometry = biometry
        self.hasPasscode = hasPasscode
    }
}

/// What the switch and the unlock button name. Nil from `make`: no passcode, so the lock cannot work.
public enum AppLockMethod: Equatable, Sendable {
    case faceID, touchID, opticID, passcode, deviceUnlock

    public static func make(_ capability: LockCapability) -> AppLockMethod? {
        guard capability.hasPasscode else { return nil }
        switch capability.biometry {
        case .faceID: return .faceID
        case .touchID: return .touchID
        case .opticID: return .opticID
        case .none: return .passcode
        case .other: return .deviceUnlock
        }
    }
}

public enum AppLockEvent: Equatable, Sendable {
    case launched
    case enteredBackground(Date)
    case becameActive(Date)
    case unlocked
    case turnedOn
    case turnedOff
    case passcodeRemoved
}

/// The lock's state machine. Time arrives only through event dates, so tests need no clock.
public struct AppLockPolicy: Equatable, Sendable {
    public static let backgroundLimit: TimeInterval = 300

    public private(set) var isOn: Bool
    public private(set) var isLocked: Bool = false
    /// Set when the lock turned itself off because the device has no passcode now; Settings says so once.
    public private(set) var turnedOffForMissingPasscode: Bool = false
    private let limit: TimeInterval
    private var backgroundDate: Date?

    /// `backgroundLimit` is clamped to 0...300, so no value can lengthen the 5 minutes; test mode passes 0.
    public init(isOn: Bool, backgroundLimit: TimeInterval = AppLockPolicy.backgroundLimit) {
        self.isOn = isOn
        limit = backgroundLimit.isNaN ? Self.backgroundLimit : min(max(backgroundLimit, 0), Self.backgroundLimit)
    }

    /// True when the app should ask for authentication by itself after this event.
    @discardableResult
    public mutating func handle(_ event: AppLockEvent) -> Bool {
        switch event {
        case .launched:
            guard isOn else { return false }
            isLocked = true
            return true
        case .enteredBackground(let date):
            if isOn, backgroundDate == nil { backgroundDate = date }
            return false
        case .becameActive(let date):
            guard isOn, let held = backgroundDate else { return false }
            backgroundDate = nil
            let away = date.timeIntervalSince(held)
            guard !isLocked, !away.isFinite || away < 0 || away >= limit else { return false }
            isLocked = true
            return true
        case .unlocked:
            isLocked = false
            backgroundDate = nil
        case .turnedOn:
            isOn = true
            isLocked = false
            backgroundDate = nil
            turnedOffForMissingPasscode = false
        case .turnedOff:
            isOn = false
            isLocked = false
            backgroundDate = nil
        case .passcodeRemoved:
            guard isOn else { return false }
            isOn = false
            isLocked = false
            backgroundDate = nil
            turnedOffForMissingPasscode = true
        }
        return false
    }

    public mutating func acknowledgeTurnedOff() {
        turnedOffForMissingPasscode = false
    }

    /// The cover shows while the lock is on and the scene is not active, or while locked.
    public static func coversContent(isOn: Bool, isLocked: Bool, sceneIsActive: Bool) -> Bool {
        isLocked || (isOn && !sceneIsActive)
    }
}
