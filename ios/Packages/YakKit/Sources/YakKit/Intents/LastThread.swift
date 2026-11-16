import Foundation

public enum LastThread {
    /// The last opened thread of an instance (`Cache.lastOpenedThreadID()`) and its link; the title is
    /// `.privateThread` for a private one and `.unknown` when the cached show is missing or unreadable.
    public static func find(cache: Cache, instance: Instance) async -> ThreadEntityRow? {
        guard let id = await cache.lastOpenedThreadID() else { return nil }
        let title: WidgetRowTitle
        if let show = await cache.thread(id)?.show {
            title =
                show.thread.isPrivate
                ? .privateThread : .text(WidgetText.bounded(show.thread.title, limit: WidgetText.titleLimit))
        } else {
            title = .unknown
        }
        guard
            let link = WidgetContentParts.link(
                for: WidgetItem(kind: .thread, id: id, title: nil, isPrivate: true), instance: instance)
        else { return nil }
        return ThreadEntityRow(
            entityID: "\(instance.id.uuidString)/thread/\(id)", title: title, detail: nil,
            instanceName: WidgetText.bounded(instance.name, limit: WidgetText.instanceNameLimit), link: link)
    }
}
