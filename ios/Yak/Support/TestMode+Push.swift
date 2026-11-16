#if DEBUG
    import Foundation
    import YakKit

    /// The push arguments of test mode: `-yakTestNotifications notDetermined|authorized|denied`,
    /// `-yakTestNotificationRequest granted|denied|failed|slow`, `-yakTestPushToken fail`,
    /// `-yakTestLiveActivities off` and `-yakTestPushBuild <bundle id>/<environment>`.
    extension TestMode {
        static func pushBuild() -> PushBuild {
            let fallback = PushBuild(bundleID: "com.example.yak", environment: .production)
            guard let value = value("yakTestPushBuild") else { return fallback }
            let parts = value.split(separator: "/", maxSplits: 1).map(String.init)
            guard parts.count == 2, let environment = PushEnvironment(rawValue: parts[1]) else { return fallback }
            return PushBuild(bundleID: parts[0], environment: environment)
        }

        @MainActor static func makePushDoubles() -> (
            permission: TestNotificationPermission, center: TestNotificationCenter
        ) {
            let authorization: NotificationAuthorization =
                switch value("yakTestNotifications") {
                case "authorized": .authorized
                case "denied": .denied
                default: .notDetermined
                }
            let request: TestNotificationPermission.RequestPlan =
                switch value("yakTestNotificationRequest") {
                case "denied": .denied
                case "failed": .failed
                case "slow": .slow
                default: .granted
                }
            // `-yakTestHoldExplanation YES` keeps "Notifications are on" long enough to be photographed.
            if flag("yakTestHoldExplanation") { NotificationExplanationModel.closingDelay = .seconds(6) }
            let permission = TestNotificationPermission(
                authorization: authorization, request: request,
                tokenFails: value("yakTestPushToken") == "fail",
                liveActivitiesEnabled: value("yakTestLiveActivities") != "off")
            return (permission, TestNotificationCenter())
        }
    }

    /// The system's permission, played from launch arguments. A grant moves the state to authorized.
    final class TestNotificationPermission: NotificationPermission, @unchecked Sendable {
        enum RequestPlan { case granted, denied, failed, slow }

        private let lock = NSLock()
        private var current: NotificationAuthorization
        private let plan: RequestPlan
        private let tokenFails: Bool
        private let isLiveActivitiesEnabled: Bool
        /// Set once the controller exists; the fake delivers its token here.
        @MainActor weak var controller: PushController?

        init(
            authorization: NotificationAuthorization, request: RequestPlan, tokenFails: Bool,
            liveActivitiesEnabled: Bool
        ) {
            self.current = authorization
            self.plan = request
            self.tokenFails = tokenFails
            self.isLiveActivitiesEnabled = liveActivitiesEnabled
        }

        func authorization() async -> NotificationAuthorization { lock.withLock { current } }

        func request() async -> NotificationRequestResult {
            switch plan {
            case .granted:
                lock.withLock { current = .authorized }
                return .granted
            case .slow:
                try? await Task.sleep(for: .seconds(3))
                lock.withLock { current = .authorized }
                return .granted
            case .denied:
                lock.withLock { current = .denied }
                return .denied
            case .failed: return .failed
            }
        }

        @MainActor func registerForRemoteNotifications() {
            guard let controller else { return }
            if tokenFails {
                controller.didFailToRegister(URLError(.cannotConnectToHost))
            } else {
                let token = Data(
                    Array(repeating: [0x01, 0x23, 0x45, 0x67, 0x89, 0xab, 0xcd, 0xef], count: 4).flatMap { $0 })
                Task { await controller.didRegister(deviceToken: token) }
            }
        }

        func liveActivitiesEnabled() -> Bool { isLiveActivitiesEnabled }
    }

    /// Delivered notifications and the badge, kept in memory.
    final class TestNotificationCenter: NotificationCenterClient, @unchecked Sendable {
        private let lock = NSLock()
        private var notifications: [DeliveredNotification] = []
        private var badgeValue = 0

        func delivered() async -> [DeliveredNotification] { lock.withLock { notifications } }
        func remove(_ identifiers: [String]) {
            lock.withLock { notifications.removeAll { identifiers.contains($0.identifier) } }
        }
        func setBadge(_ count: Int) async { lock.withLock { badgeValue = count } }
        var badge: Int { lock.withLock { badgeValue } }
    }
#endif
