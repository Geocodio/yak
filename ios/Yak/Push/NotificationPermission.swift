import ActivityKit
import UIKit
import UserNotifications
import YakKit

enum NotificationRequestResult: Equatable {
    case granted, denied, failed
}

/// The system's notification permission and the registration with APNs, behind a seam tests replace.
protocol NotificationPermission: Sendable {
    func authorization() async -> NotificationAuthorization
    /// Asks the system for alert, sound and badge. Only an explicit tap reaches it.
    func request() async -> NotificationRequestResult
    @MainActor func registerForRemoteNotifications()
    func liveActivitiesEnabled() -> Bool
}

struct SystemNotificationPermission: NotificationPermission {
    func authorization() async -> NotificationAuthorization {
        let settings = await UNUserNotificationCenter.current().notificationSettings()
        switch settings.authorizationStatus {
        case .notDetermined: return .notDetermined
        case .denied: return .denied
        case .authorized: return .authorized
        case .provisional: return .provisional
        case .ephemeral: return .ephemeral
        @unknown default: return .denied
        }
    }

    func request() async -> NotificationRequestResult {
        do {
            let isGranted = try await UNUserNotificationCenter.current()
                .requestAuthorization(options: [.alert, .sound, .badge])
            return isGranted ? .granted : .denied
        } catch {
            return .failed
        }
    }

    @MainActor func registerForRemoteNotifications() {
        UIApplication.shared.registerForRemoteNotifications()
    }

    func liveActivitiesEnabled() -> Bool {
        ActivityAuthorizationInfo().areActivitiesEnabled
    }
}
