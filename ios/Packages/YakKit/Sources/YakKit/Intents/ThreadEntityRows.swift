import Foundation

/// A thread or task as a Shortcuts entity or an intent answer. A private title never gets here.
public struct ThreadEntityRow: Equatable, Sendable {
    /// "<instance uuid>/<thread|task>/<id>".
    public let entityID: String
    public let title: WidgetRowTitle
    public let detail: String?
    public let instanceName: String
    public let link: URL

    static func make(item: WidgetItem, instance: Instance) -> ThreadEntityRow? {
        guard let row = WidgetContentParts.row(item, instance: instance, running: false) else { return nil }
        return ThreadEntityRow(
            entityID: "\(instance.id.uuidString)/\(item.kind.rawValue)/\(item.id)", title: row.title,
            detail: row.detail, instanceName: WidgetText.bounded(instance.name, limit: WidgetText.instanceNameLimit),
            link: row.link)
    }
}

public enum ThreadEntityRows {
    public static func needsYou(_ snapshot: WidgetSnapshot, instance: Instance) -> [ThreadEntityRow] {
        snapshot.needsYou.compactMap { ThreadEntityRow.make(item: $0, instance: instance) }
    }

    /// Nil for anything that is not exactly "<uuid>/<thread|task>/<8 letters or digits>".
    public static func parse(_ entityID: String) -> (instance: UUID, kind: WidgetItem.Kind, id: String)? {
        guard entityID.utf8.count <= 64 else { return nil }
        let parts = entityID.split(separator: "/", omittingEmptySubsequences: false)
        guard parts.count == 3, let instance = UUID(uuidString: String(parts[0])),
            let kind = WidgetItem.Kind(rawValue: String(parts[1])), parts[2].utf8.count == 8,
            parts[2].utf8.allSatisfy({ ($0 >= 48 && $0 <= 57) || ($0 >= 65 && $0 <= 90) || ($0 >= 97 && $0 <= 122) })
        else { return nil }
        return (instance, kind, String(parts[2]))
    }
}
