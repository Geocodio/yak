import Foundation

/// Why a widget cannot show a snapshot.
public enum WidgetState: Equatable, Sendable {
    /// Nothing is paired.
    case noInstance
    /// The configured instance was removed.
    case instanceMissing
    case needsPairing(host: String)
    /// No snapshot yet, and the load failed or the token is unreadable.
    case notLoaded(host: String)
    case content
}

public enum WidgetFamilySize: Sendable { case small, medium, accessoryRectangular, accessoryCircular }

public struct WidgetRowContent: Equatable, Sendable, Identifiable {
    /// "<kind>:<id>".
    public let id: String
    public let title: WidgetRowTitle
    public let detail: String?
    /// Nil for a running row.
    public let reason: NeedsYouReason?
    public let dot: WidgetRowDot
    public let lastActivityAt: Date?
    /// `yak://thread` or `yak://task` with the instance's paired address.
    public let link: URL
}

/// `.task` is a public task's title; views show it as "Task: <title>". `.unknown` is only used by `LastThread`
/// when the title cannot be read, and is shown as "Your last thread".
public enum WidgetRowTitle: Equatable, Sendable {
    case text(String)
    case task(String)
    case privateThread, unknown
}

/// accent: a question, a block, a co-owner request or any reason the app does not know; info: unseen (finished);
/// success: a running row. Chosen by reason, never by privacy.
public enum WidgetRowDot: Equatable, Sendable { case accent, info, success }

struct WidgetContentParts {
    let state: WidgetState
    let instanceName: String
    let count: Int
    let rows: [WidgetRowContent]
    let updatedAt: Date?
    let isStale: Bool

    static let staleAfter: TimeInterval = 3600

    /// `running` selects the snapshot's running list; `countLabel` is chosen by the caller.
    static func make(
        snapshot: WidgetSnapshot?, state: WidgetState, instance: Instance?, size: WidgetFamilySize, now: Date,
        running: Bool
    ) -> WidgetContentParts {
        guard state == .content, let snapshot else {
            let name = instance.map { WidgetText.bounded($0.name, limit: WidgetText.instanceNameLimit) } ?? ""
            return WidgetContentParts(
                state: state, instanceName: name, count: 0, rows: [], updatedAt: nil, isStale: false)
        }
        let items = running ? snapshot.running : snapshot.needsYou
        let wanted = size == .medium ? 3 : 1
        let rows = items.lazy.compactMap { row($0, instance: instance, running: running) }.prefix(wanted)
        let count = running ? min(items.count, WidgetSnapshot.itemLimit) : WidgetText.clamped(snapshot.needsYouCount)
        return WidgetContentParts(
            state: state, instanceName: snapshot.instanceName, count: count, rows: Array(rows),
            updatedAt: snapshot.updatedAt, isStale: now.timeIntervalSince(snapshot.updatedAt) > staleAfter)
    }

    static func row(_ item: WidgetItem, instance: Instance?, running: Bool) -> WidgetRowContent? {
        guard let link = link(for: item, instance: instance) else { return nil }
        let title: WidgetRowTitle
        if !item.isPrivate, let text = item.title {
            title = item.kind == .task ? .task(text) : .text(text)
        } else {
            title = .privateThread
        }
        let reason = running ? nil : item.needsYouReason.map { NeedsYouReason(rawValue: $0) }
        let dot: WidgetRowDot = running ? .success : (reason == .unseen ? .info : .accent)
        return WidgetRowContent(
            id: "\(item.kind.rawValue):\(item.id)", title: title, detail: item.detail, reason: reason, dot: dot,
            lastActivityAt: item.lastActivityAt, link: link)
    }

    /// The paired address as a query value.
    static func addressQueryValue(_ instance: Instance) -> String? {
        let unreserved = CharacterSet(
            charactersIn: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~")
        return instance.url.absoluteString.addingPercentEncoding(withAllowedCharacters: unreserved)
    }

    /// Built from parts, so a hostile id cannot add a query item; an id that is not exactly 8 ASCII letters or
    /// digits gives no link.
    static func link(for item: WidgetItem, instance: Instance?) -> URL? {
        guard let instance, item.id.utf8.count == 8,
            item.id.utf8.allSatisfy({ ($0 >= 48 && $0 <= 57) || ($0 >= 65 && $0 <= 90) || ($0 >= 97 && $0 <= 122) })
        else { return nil }
        var components = URLComponents()
        components.scheme = "yak"
        components.host = item.kind == .task ? "task" : "thread"
        guard let address = addressQueryValue(instance) else { return nil }
        components.percentEncodedQuery = "instance=\(address)&id=\(item.id)"
        return components.url
    }
}

public struct NeedsYouWidgetContent: Equatable, Sendable {
    public let state: WidgetState
    public let instanceName: String
    /// "0" ... "99+".
    public let countLabel: String
    public let count: Int
    /// 1 for small and both accessory sizes, 3 for medium.
    public let rows: [WidgetRowContent]
    /// The first row's link, nil when there is none.
    public let link: URL?
    public let updatedAt: Date?
    /// `updatedAt` is older than one hour at `now`.
    public let isStale: Bool

    public static func make(
        snapshot: WidgetSnapshot?, state: WidgetState, instance: Instance?, size: WidgetFamilySize, now: Date
    ) -> NeedsYouWidgetContent {
        let parts = WidgetContentParts.make(
            snapshot: snapshot, state: state, instance: instance, size: size, now: now, running: false)
        return NeedsYouWidgetContent(
            state: state, instanceName: parts.instanceName, countLabel: WidgetText.countLabel(parts.count),
            count: parts.count, rows: parts.rows, link: parts.rows.first?.link, updatedAt: parts.updatedAt,
            isStale: parts.isStale)
    }
}

public struct RunningWidgetContent: Equatable, Sendable {
    public let state: WidgetState
    public let instanceName: String
    /// "0" ... "8", then "8+": the snapshot's running list is cut at 8.
    public let countLabel: String
    public let count: Int
    /// 1 for small, 3 for medium.
    public let rows: [WidgetRowContent]
    public let link: URL?
    public let updatedAt: Date?
    public let isStale: Bool

    public static func make(
        snapshot: WidgetSnapshot?, state: WidgetState, instance: Instance?, size: WidgetFamilySize, now: Date
    ) -> RunningWidgetContent {
        let parts = WidgetContentParts.make(
            snapshot: snapshot, state: state, instance: instance, size: size, now: now, running: true)
        let full = parts.count >= WidgetSnapshot.itemLimit
        return RunningWidgetContent(
            state: state, instanceName: parts.instanceName, countLabel: full ? "\(parts.count)+" : "\(parts.count)",
            count: parts.count, rows: parts.rows, link: parts.rows.first?.link, updatedAt: parts.updatedAt,
            isStale: parts.isStale)
    }
}
