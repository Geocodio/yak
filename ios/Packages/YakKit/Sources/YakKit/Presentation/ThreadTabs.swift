import Foundation

/// Which tabs and filters the drawer offers for what the server accepts.
public struct ThreadTabs: Equatable, Sendable {
    /// The tabs in the web's order.
    public let tabs: [ThreadListFilter]
    /// False on an older server: "Started by Yak" is then asked as `filter=yak`, and the server lists no facets.
    public let supportsFacets: Bool

    /// `serverFilters` is `me.threadFilters`: nil when the server sent no list (an older server), which offers the
    /// tabs the app offered before. A list is followed as given, so the app never sends a filter the server did not
    /// list; `mine` is always there.
    public init(serverFilters: [String]?) {
        guard let serverFilters else {
            tabs = [.mine, .everyone]
            supportsFacets = false
            return
        }
        let order: [ThreadListFilter] = [.mine, .review, .everyone, .closed]
        let offered = order.filter { $0 == .mine || serverFilters.contains($0.rawValue) }
        tabs = offered
        supportsFacets = true
    }

    /// Whether the sheet offers "Started by Yak" on `tab`. An older server knows it only as the old chip's Mine filter.
    public func offersStartedByYak(on tab: ThreadListFilter) -> Bool {
        supportsFacets || tab == .mine
    }

    /// `selection` as this server can show it: an older server keeps its Yak starter only on Mine, and knows no
    /// sources.
    public func normalized(_ selection: ThreadListSelection) -> ThreadListSelection {
        guard !supportsFacets else { return selection }
        var next = selection
        next.sources = []
        if selection.filter != .mine { next.starters = [] }
        return next
    }

    /// The number on a tab: three digits at most, so the row keeps room for the four names.
    public static func badgeText(_ count: Int) -> String {
        count > 99 ? "99+" : String(max(count, 0))
    }

    /// The tab to show when `filter` is not offered (a saved Review on a server that dropped it).
    public func resolved(_ filter: ThreadListFilter) -> ThreadListFilter {
        tabs.contains(filter) ? filter : .mine
    }

    /// The filter word and starters to send for the person's choice. An older server takes the Yak starter as the
    /// `yak` filter and no starters.
    public func request(filter: ThreadListFilter, starters: [String]) -> (filter: ThreadListFilter, starters: [String])
    {
        if supportsFacets { return (resolved(filter), starters) }
        let yak = filter == .mine && starters.contains("yak")
        return (yak ? .yak : resolved(filter), [])
    }
}

/// The person's tab and the filters on it, as one window remembers them.
public struct ThreadListSelection: Codable, Equatable, Sendable {
    public var filter: ThreadListFilter = .mine
    public var sources: [String] = []
    public var starters: [String] = []

    public init(filter: ThreadListFilter = .mine, sources: [String] = [], starters: [String] = []) {
        self.filter = filter
        self.sources = sources
        self.starters = starters
    }

    /// The old chip's "Started by Yak" is Mine with the Yak starter.
    public static func migrated(_ filter: ThreadListFilter) -> ThreadListSelection {
        filter == .yak ? ThreadListSelection(filter: .mine, starters: ["yak"]) : ThreadListSelection(filter: filter)
    }

    /// How many filters are on, for the Filter button's count.
    public var activeCount: Int { sources.count + starters.count }
}

/// One row of the Filter sheet.
public struct FilterChoice: Equatable, Sendable, Identifiable {
    public enum Role: Equatable, Sendable { case person, viewer, yak }
    public let key: String
    /// The server's label; the sheet words `viewer` and `yak` itself.
    public let label: String
    public let count: Int
    public let isPicked: Bool
    public let role: Role
    public var id: String { key }

    public init(key: String, label: String, count: Int, isPicked: Bool, role: Role) {
        self.key = key
        self.label = label
        self.count = count
        self.isPicked = isPicked
        self.role = role
    }
}

extension ThreadTabs {
    /// The rows of one group of the sheet: what the server counted, then any picked key it did not count (shown
    /// with 0 so the person can take it off).
    public static func choices(
        from options: [ThreadFacetOption], picked: [String], viewerKey: String? = nil
    ) -> [FilterChoice] {
        func role(_ key: String) -> FilterChoice.Role {
            key == "yak" ? .yak : (key == viewerKey ? .viewer : .person)
        }
        let counted = options.map {
            FilterChoice(
                key: $0.key, label: $0.label, count: $0.count, isPicked: picked.contains($0.key), role: role($0.key))
        }
        let missing = picked.filter { key in !options.contains { $0.key == key } }
            .map { FilterChoice(key: $0, label: $0, count: 0, isPicked: true, role: role($0)) }
        return counted + missing
    }
}

/// Where the "Link GitHub on the web" button of the Review tab goes.
public enum ReviewLink {
    /// `path` against the instance's address, or nil: the path must start with one `/` (never `//`), the answer
    /// stays on the instance's host, and it passes `WebLinkRule`.
    public static func url(path: String?, instance: URL) -> URL? {
        guard let path, path.hasPrefix("/"), !path.hasPrefix("//"),
            var components = URLComponents(url: instance, resolvingAgainstBaseURL: false)
        else { return nil }
        let target = URLComponents(string: path)
        components.path = target?.path ?? path
        components.query = target?.query
        components.fragment = nil
        return WebLinkRule.safeURL(components.url?.absoluteString)
    }
}

/// Whether the four tab names fit the room the row has, so the row shows them or the menu button.
public enum TabRowFit {
    /// `naturalWidth` is what the names take at the current text size, count and spacing; `availableWidth` is the
    /// room between the row's margins and the Filter button. Unmeasured room (0) shows the names, which are measured
    /// at once.
    public static func segmentsFit(naturalWidth: Double, availableWidth: Double) -> Bool {
        availableWidth <= 0 || naturalWidth <= availableWidth + 0.5
    }
}
