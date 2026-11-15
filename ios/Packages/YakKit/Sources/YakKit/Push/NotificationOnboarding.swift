import Foundation

/// The system's notification permission, reduced to what the app decides on.
public enum NotificationAuthorization: Equatable, Sendable {
    case notDetermined, denied, authorized, provisional, ephemeral

    /// Whether the app may register with APNs.
    public var allowsRegistration: Bool {
        switch self {
        case .authorized, .provisional, .ephemeral: true
        case .notDetermined, .denied: false
        }
    }
}

public enum NotificationSettingsHint: Equatable, Sendable {
    case turnOn, offInSystemSettings
}

public enum NotificationOnboarding {
    public enum Step: Equatable, Sendable { case explain, register, skip }

    /// After a pairing: whether the explanation screen shows. An unknown availability never asks,
    /// and "Not now" is not remembered, so the next pairing explains again until the person decides.
    public static func afterPairing(availability: PushAvailability?, authorization: NotificationAuthorization) -> Step {
        guard availability == .available else { return .skip }
        if authorization == .notDetermined { return .explain }
        return authorization.allowsRegistration ? .register : .skip
    }

    /// The permission line in Settings, when one shows.
    public static func settingsHint(authorization: NotificationAuthorization, anyInstanceCanPush: Bool)
        -> NotificationSettingsHint?
    {
        switch authorization {
        case .denied: .offInSystemSettings
        case .notDetermined: anyInstanceCanPush ? .turnOn : nil
        case .authorized, .provisional, .ephemeral: nil
        }
    }
}
