import UIKit

/// Receives the quick action of a running app. A cold start's action arrives in the connection options.
final class QuickActionSceneDelegate: NSObject, UIWindowSceneDelegate {
    func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options: UIScene.ConnectionOptions) {
        guard let item = options.shortcutItem else { return }
        Task { @MainActor in QuickActions.deliver(item) }
    }

    func windowScene(
        _ windowScene: UIWindowScene, performActionFor shortcutItem: UIApplicationShortcutItem
    ) async -> Bool {
        await MainActor.run { QuickActions.deliver(shortcutItem) }
    }
}
