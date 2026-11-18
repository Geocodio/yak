import UIKit
import UserNotifications
import YakKit

#if canImport(os)
    import os
#endif

/// The app delegate push needs: it owns the notification center's delegate from launch, so a launch from a tap is
/// not lost, and forwards the device token and the taps to the controller.
final class PushAppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    private var controller: PushController?
    private var earlyResponses: [PushPayload] = []

    /// Responses that arrived before the controller existed are handed over here.
    func attach(_ controller: PushController) {
        guard self.controller == nil else { return }
        self.controller = controller
        let waiting = earlyResponses
        earlyResponses = []
        for payload in waiting { Task { await controller.didTap(payload) } }
    }

    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        return true
    }

    func application(
        _ application: UIApplication, configurationForConnecting connectingSceneSession: UISceneSession,
        options: UIScene.ConnectionOptions
    ) -> UISceneConfiguration {
        let configuration = UISceneConfiguration(name: nil, sessionRole: connectingSceneSession.role)
        configuration.delegateClass = QuickActionSceneDelegate.self
        return configuration
    }

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        guard let controller else { return }
        Task { await controller.didRegister(deviceToken: deviceToken) }
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: any Error) {
        controller?.didFailToRegister(error)
    }

    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter, willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping @Sendable (UNNotificationPresentationOptions) -> Void
    ) {
        let userInfo = notification.request.content.userInfo
        let payload = PushPayload.make(userInfo: userInfo)
        Task { @MainActor in
            guard let payload, let controller = self.controller else {
                completionHandler([.banner, .list, .sound])
                return
            }
            let rule = await controller.foreground(for: payload)
            completionHandler(rule.showsBanner ? [.banner, .list, .sound] : [])
        }
    }

    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping @Sendable () -> Void
    ) {
        let payload = PushPayload.make(userInfo: response.notification.request.content.userInfo)
        Task { @MainActor in
            defer { completionHandler() }
            guard let payload else { return }
            if let controller = self.controller {
                await controller.didTap(payload)
            } else {
                self.earlyResponses.append(payload)
            }
        }
    }
}
