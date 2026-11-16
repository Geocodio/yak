#if DEBUG
    import Foundation
    import YakKit

    /// The push arguments of test mode: `-yakTestNotifications notDetermined|authorized|denied`,
    /// `-yakTestNotificationRequest granted|denied|failed|slow`, `-yakTestPushToken fail`,
    /// `-yakTestLiveActivities off`, `-yakTestPushBuild <bundle id>/<environment>` and `-yakTestPushTap <base64>`.
    extension TestMode {
        /// A push payload from a base64 text; nil when the text is not one.
        static func pushPayload(base64 text: String) -> PushPayload? {
            guard let data = Data(base64Encoded: text) else { return nil }
            return try? PushPayload(json: data)
        }

        /// `-yakTestPushTap <base64 payload>`: the tap that launched the app. The payload is longer than the other
        /// arguments' values, so it is read directly.
        static func launchPushTap(in defaults: UserDefaults = .standard) -> PushPayload? {
            defaults.string(forKey: "yakTestPushTap").flatMap { pushPayload(base64: $0) }
        }

        /// `yak://test-push?payload=<base64 payload>`: a tap while the app runs.
        static func pushTap(url: URL) -> PushPayload? {
            guard url.scheme?.lowercased() == "yak", url.host?.lowercased() == "test-push",
                let text = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems?
                    .first(where: { $0.name == "payload" })?.value
            else { return nil }
            return pushPayload(base64: text)
        }

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
