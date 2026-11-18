import UIKit
import UserNotifications
import YakKit
import os

/// The delivered notifications and the icon badge, behind a seam tests replace.
protocol NotificationCenterClient: Sendable {
    func delivered() async -> [DeliveredNotification]
    func remove(_ identifiers: [String])
    func setBadge(_ count: Int) async
}

struct SystemNotificationCenter: NotificationCenterClient {
    func delivered() async -> [DeliveredNotification] {
        let notifications = await UNUserNotificationCenter.current().deliveredNotifications()
        return notifications.map { notification in
            DeliveredNotification(
                identifier: notification.request.identifier,
                payload: PushPayload.make(userInfo: notification.request.content.userInfo))
        }
    }

    func remove(_ identifiers: [String]) {
        UNUserNotificationCenter.current().removeDeliveredNotifications(withIdentifiers: identifiers)
    }

    func setBadge(_ count: Int) async {
        do {
            try await UNUserNotificationCenter.current().setBadgeCount(count)
        } catch {
            Logger(subsystem: "Yak", category: "Push")
                .error("The badge was not set: \(String(describing: type(of: error)), privacy: .public)")
        }
    }
}

extension PushPayload {
    /// The payload of a notification's `userInfo`; nil when it is not a Yak push.
    static func make(userInfo: [AnyHashable: Any]) -> PushPayload? {
        var object: [String: Any] = [:]
        for (key, value) in userInfo {
            if let name = key as? String { object[name] = value }
        }
        guard JSONSerialization.isValidJSONObject(object),
            let data = try? JSONSerialization.data(withJSONObject: object)
        else { return nil }
        // A payload that does not decode is not a Yak push, so it maps to nil.
        return try? PushPayload(json: data)
    }
}
