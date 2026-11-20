import Foundation

/// Every path under the app group, so the app and its extensions agree on where things live.
public enum InstanceFolders {
    public static func cache(_ instance: UUID) -> String { "instances/\(instance.uuidString)/cache" }
    public static func outbox(_ instance: UUID) -> String { "instances/\(instance.uuidString)/outbox" }
    public static func uploads(_ instance: UUID) -> String { "\(outbox(instance))/uploads" }
    /// The web archives of threads, for reading them offline; part of the cache, so erasing the cache erases them.
    public static func offlineCopies(_ instance: UUID) -> String { "\(cache(instance))/offline-copies" }
    public static func widgetSnapshot(_ instance: UUID) -> String { "\(cache(instance))/widget.json" }
    public static func widgetPushRecord(_ instance: UUID) -> String { "\(cache(instance))/widget-push.json" }
    public static func lastOpened(_ instance: UUID) -> String { "\(cache(instance))/last-opened.json" }
    public static func shareContext(_ instance: UUID) -> String { "\(cache(instance))/share-context.json" }
    /// Where the share extension stages prepared images, at the app group root.
    public static let shareStaging = "share-staging"
    /// The device's widget token, at the app group root.
    public static let widgetPushToken = "widget-push-token.json"
}
