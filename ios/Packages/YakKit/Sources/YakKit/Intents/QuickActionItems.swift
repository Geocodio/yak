import Foundation

public struct QuickActionItem: Equatable, Sendable {
    public enum Kind: Equatable, Sendable {
        case newThread
        case open(WidgetRowTitle)
    }
    public let kind: Kind
    /// The instance name, bounded.
    public let subtitle: String
    /// `yak://new?instance=...` or the item's link.
    public let link: URL
}

public enum QuickActionItems {
    public static let titleLimit = 60

    /// "New thread" always; "Open <title>" for the first needs-you item of the instance when there is one.
    public static func make(instance: Instance?, snapshot: WidgetSnapshot?) -> [QuickActionItem] {
        guard let instance, let address = WidgetContentParts.addressQueryValue(instance),
            let newLink = URL(string: "yak://new?instance=\(address)")
        else { return [] }
        let subtitle = WidgetText.bounded(instance.name, limit: WidgetText.instanceNameLimit)
        var items = [QuickActionItem(kind: .newThread, subtitle: subtitle, link: newLink)]
        if let snapshot,
            let row = snapshot.needsYou.lazy.compactMap({
                WidgetContentParts.row($0, instance: instance, running: false)
            })
            .first
        {
            let title: WidgetRowTitle =
                switch row.title {
                case .text(let text): .text(WidgetText.bounded(text, limit: titleLimit))
                case .task(let text): .task(WidgetText.bounded(text, limit: titleLimit))
                case .privateThread, .unknown: row.title
                }
            items.append(QuickActionItem(kind: .open(title), subtitle: subtitle, link: row.link))
        }
        return items
    }
}
