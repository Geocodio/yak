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
