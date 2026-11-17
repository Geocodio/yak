import Foundation
import WidgetKit
import YakKit

struct YakEntry: TimelineEntry {
    let date: Date
    let refresh: WidgetRefresh
    let isPlaceholder: Bool
}

/// Both widgets use it. A timeline loads the instance's list, resumes any pending widget token registration and asks
/// for the next reload in 15 minutes; a widget push reloads sooner.
struct YakTimelineProvider: AppIntentTimelineProvider {
    typealias Entry = YakEntry
    typealias Intent = SelectInstanceIntent

    static let refreshBudget: Duration = .seconds(8)
    static let totalBudget: Duration = .seconds(10)
    static let reloadInterval: TimeInterval = 15 * 60

    func placeholder(in context: Context) -> YakEntry { Self.placeholderEntry(now: Date()) }

    /// The gallery and the edit sheet: the saved snapshot only, never the network.
    func snapshot(for configuration: SelectInstanceIntent, in context: Context) async -> YakEntry {
        if context.isPreview { return Self.placeholderEntry(now: Date()) }
        guard let services = WidgetServices.open() else { return Self.unavailableEntry(now: Date()) }
        let refresh = await services.refresher.saved(instance: configuration.instance?.id)
        return YakEntry(date: Date(), refresh: refresh, isPlaceholder: false)
    }

    func timeline(for configuration: SelectInstanceIntent, in context: Context) async -> Timeline<YakEntry> {
        let now = Date()
        let next = now.addingTimeInterval(Self.reloadInterval)
        guard let services = WidgetServices.open() else {
            return Timeline(entries: [Self.unavailableEntry(now: now)], policy: .after(next))
        }
        let started = ContinuousClock.now
        let refresh = await services.refresher.refresh(
            instance: configuration.instance?.id, budget: Self.refreshBudget)
        let remaining = Self.totalBudget - (ContinuousClock.now - started)
        if remaining > .zero { _ = await services.registration.registerPending(budget: remaining) }
        return Timeline(entries: Self.entries(now: now, refresh: refresh), policy: .after(next))
    }

    /// The same content at later dates, so ages and "Updated" lines advance without a reload.
    static func entries(now: Date, refresh: WidgetRefresh) -> [YakEntry] {
        [0, 1, 2, 3, 6].map {
            YakEntry(date: now.addingTimeInterval(TimeInterval($0) * 3600), refresh: refresh, isPlaceholder: false)
        }
    }

    /// Neutral sample rows; the views redact them.
    static func placeholderEntry(now: Date) -> YakEntry {
        let items = [
            WidgetItem(kind: .thread, id: "aaaaaaaa", title: "Example thread", isPrivate: false, detail: "Working"),
            WidgetItem(kind: .thread, id: "bbbbbbbb", title: "Another thread", isPrivate: false, detail: "Working"),
            WidgetItem(kind: .thread, id: "cccccccc", title: "A third thread", isPrivate: false, detail: "Working"),
        ]
        let instance = Instance(
            url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person", lastUsedAt: now)
        let snapshot = WidgetSnapshot(
            instanceName: "Example Yak", needsYouCount: 3, needsYou: items, running: items, updatedAt: now)
        return YakEntry(
            date: now, refresh: WidgetRefresh(instance: instance, snapshot: snapshot, state: .content, isFresh: false),
            isPlaceholder: true)
    }

    /// The shared storage could not be opened, so nothing can be loaded.
    static func unavailableEntry(now: Date) -> YakEntry {
        YakEntry(
            date: now,
            refresh: WidgetRefresh(instance: nil, snapshot: nil, state: .notLoaded(host: ""), isFresh: false),
            isPlaceholder: false)
    }
}
