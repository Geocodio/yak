import UIKit
import YakKit

/// The Home Screen icon's quick actions: "New thread" and, when something waits, "Open <title>". A tap becomes a
/// `yak://` link that the app's link path opens.
@MainActor
enum QuickActions {
    static let newThreadType = "new-thread"
    static let openType = "open"
    static let linkKey = "link"

    /// How an address in a link is normalized; Debug test mode replaces it to allow the stub's plain http address.
    static var normalizeAddress: @Sendable (String) -> URL? = DeepLink.normalizeHTTPS

    private static var pending: [URL] = []
    private static var continuation: AsyncStream<URL>.Continuation?

    /// The items for an instance, "Open" above "New thread".
    static func shortcutItems(instance: Instance?, snapshot: WidgetSnapshot?) -> [UIApplicationShortcutItem] {
        let items = QuickActionItems.make(instance: instance, snapshot: snapshot)
        let ordered = items.filter { $0.kind != .newThread } + items.filter { $0.kind == .newThread }
        return ordered.map { item in
            let userInfo: [String: NSSecureCoding] = [linkKey: item.link.absoluteString as NSString]
            switch item.kind {
            case .newThread:
                return UIApplicationShortcutItem(
                    type: newThreadType, localizedTitle: String(localized: "quickAction.newThread"),
                    localizedSubtitle: item.subtitle,
                    icon: UIApplicationShortcutIcon(systemImageName: "square.and.pencil"),
                    userInfo: userInfo)
            case .open(let title):
                let words: String =
                    switch title {
                    case .text(let text), .task(let text): String(localized: "quickAction.open \(text)")
                    case .privateThread, .unknown: String(localized: "quickAction.openPrivate")
                    }
                return UIApplicationShortcutItem(
                    type: openType, localizedTitle: words,
                    localizedSubtitle: String(localized: "quickAction.waiting \(item.subtitle)"),
                    icon: UIApplicationShortcutIcon(systemImageName: "bell.badge"), userInfo: userInfo)
            }
        }
    }

    /// Sets the icon's items from the active instance's snapshot.
    static func update(instance: Instance?, snapshot: WidgetSnapshot?) {
        UIApplication.shared.shortcutItems = shortcutItems(instance: instance, snapshot: snapshot)
    }

    /// Links from taps, including the one that launched the app: the first subscriber receives a cold one.
    static func links() -> AsyncStream<URL> {
        AsyncStream { next in
            continuation = next
            for link in pending { next.yield(link) }
            pending = []
        }
    }

    /// Accepts only a link for a thread, a task or a new thread; a forged item (another scheme, a pairing link, a
    /// malformed address) is dropped.
    @discardableResult
    static func deliver(_ item: UIApplicationShortcutItem) -> Bool {
        guard let text = item.userInfo?[linkKey] as? String, text.utf8.count <= 2000, let url = URL(string: text),
            let link = DeepLink(url: url, normalize: normalizeAddress, parsePair: { _ in nil })
        else { return false }
        if case .pair = link { return false }
        if let continuation {
            continuation.yield(url)
        } else {
            pending.append(url)
        }
        return true
    }

    /// Forgets the subscriber and what waited for it; unit tests start from a clean state.
    static func reset() {
        continuation = nil
        pending = []
    }
}
