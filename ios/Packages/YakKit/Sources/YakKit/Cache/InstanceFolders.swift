import Foundation

/// Every path under the app group, so the app and its extensions agree on where things live.
public enum InstanceFolders {
    public static func cache(_ instance: UUID) -> String { "instances/\(instance.uuidString)/cache" }
    public static func outbox(_ instance: UUID) -> String { "instances/\(instance.uuidString)/outbox" }
    public static func widgetSnapshot(_ instance: UUID) -> String { "\(cache(instance))/widget.json" }
    public static func widgetPushRecord(_ instance: UUID) -> String { "\(cache(instance))/widget-push.json" }
    public static func lastOpened(_ instance: UUID) -> String { "\(cache(instance))/last-opened.json" }
    /// The device's widget token, at the app group root.
    public static let widgetPushToken = "widget-push-token.json"
}
