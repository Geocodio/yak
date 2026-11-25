import Foundation

/// The thread list of the `drawer-tabs` scenarios: rows that read like the boards, one list per tab, narrowed by the
/// sources, starters and search the app sends.
extension Scenario {
    private var isDrawerTabs: Bool {
        self == .drawerTabs || self == .drawerTabsQuiet || self == .drawerTabsOlder || self == .drawerTabsLink
    }

    func drawerTabsList(filter: String, query: [String: String], _ fixtures: StubFixtures, _ baseURL: String)
        -> [String: Any]?
    {
        guard isDrawerTabs else { return nil }
        var list: [String: Any]
        switch filter {
        case "review":
            list = fixtures.files["threads-review"].map { fixtures.object($0, baseURL: baseURL) } ?? [:]
            list["reviewGroups"] = reviewGroups(list["reviewGroups"] as? [[String: Any]] ?? [])
            list["reviewCount"] = self == .drawerTabsQuiet ? 0 : 2
            if self == .drawerTabsLink {
                list["reviewGroups"] = (list["reviewGroups"] as? [[String: Any]] ?? []).map { group -> [String: Any] in
                    var group = group
                    group["rows"] = [[String: Any]]()
                    return group
                }
                list["reviewCount"] = 0
            }
        case "closed":
            list = emptyList(threads: Self.closedRows)
        case "everyone":
            list = emptyList(threads: Self.todayRows + Self.everyoneRows)
        case "yak":
            list = emptyList(
                threads: Self.todayRows.filter { ($0["starter"] as? [String: Any])?["key"] as? String == "yak" })
        default:
            list = emptyList(threads: Self.todayRows)
            list["needsYou"] = Self.needsYouRows
            list["needsYouCount"] = Self.needsYouRows.count
        }
        let counted = (list["needsYou"] as? [[String: Any]] ?? []) + (list["threads"] as? [[String: Any]] ?? [])
        if self != .drawerTabsOlder {
            list["facets"] = Self.facets(of: counted)
            list["reviewCount"] = self == .drawerTabsQuiet ? 0 : 2
            list["needsGitHubLink"] = self == .drawerTabsLink && filter == "review"
            list["gitHubLinkPath"] = "/settings/profile"
        } else {
            list["reviewGroups"] = nil
            list["reviewCount"] = nil
            list["facets"] = nil
        }
        let sources = (query["sources[]"] ?? "").split(separator: ",").map(String.init)
        let starters = (query["starters[]"] ?? "").split(separator: ",").map(String.init)
        let needle = (query["search"] ?? "").trimmingCharacters(in: .whitespaces).lowercased()
        func keeps(_ row: [String: Any]) -> Bool {
            let source = (row["source"] as? [String: Any])?["key"] as? String ?? ""
            let starter = (row["starter"] as? [String: Any])?["key"] as? String ?? ""
            return (sources.isEmpty || sources.contains(source)) && (starters.isEmpty || starters.contains(starter))
                && (needle.isEmpty || (row["title"] as? String ?? "").lowercased().contains(needle))
        }
        for key in ["needsYou", "threads"] {
            list[key] = (list[key] as? [[String: Any]] ?? []).filter(keeps)
        }
        list["reviewGroups"] = (list["reviewGroups"] as? [[String: Any]])?.map { group -> [String: Any] in
            var group = group
            group["rows"] = (group["rows"] as? [[String: Any]] ?? []).filter(keeps)
            return group
        }
        return list
    }

    private func emptyList(threads: [[String: Any]]) -> [String: Any] {
        [
            "starred": [[String: Any]](), "needsYou": [[String: Any]](), "threads": threads, "needsYouCount": 0,
            "hasAnyThreads": true,
        ]
    }

    /// The fixture's four groups, with a row in "Approved by you" so the folded group shows.
    private func reviewGroups(_ groups: [[String: Any]]) -> [[String: Any]] {
        let template = groups.lazy.compactMap { ($0["rows"] as? [[String: Any]])?.first }.first ?? [:]
        return groups.map { group in
            guard group["key"] as? String == "approved" else { return group }
            var approved = template
            approved["id"] = "appr0ved"
            approved["title"] = "Add the export button"
            var group = group
            group["rows"] = [approved]
            return group
        }
    }

    private static func facets(of rows: [[String: Any]]) -> [String: Any] {
        func tally(_ field: String, name: String) -> [[String: Any]] {
            var order: [String] = []
            var counts: [String: (label: String, count: Int)] = [:]
            for row in rows {
                guard let item = row[field] as? [String: Any], let key = item["key"] as? String else { continue }
                if counts[key] == nil { order.append(key) }
                counts[key, default: (item[name] as? String ?? key, 0)].count += 1
            }
            return order.map {
                ["key": $0, name == "label" ? "label" : "name": counts[$0]!.label, "count": counts[$0]!.count]
            }
        }
        return ["sources": tally("source", name: "label"), "starters": tally("starter", name: "name")]
    }

    // MARK: Rows

    private static func row(
        _ id: String, _ title: String, source: String?, repo: String = "example/web", status: String = "stopped",
        group: String = "today", minutesAgo: Int, starter: String = "user:1", reason: String? = nil,
        label: String? = nil
    ) -> [String: Any] {
        let labels = ["slack": "Slack", "linear": "Linear", "sentry": "Sentry", "dashboard": "Dashboard"]
        let when = Date().addingTimeInterval(-Double(minutesAgo) * 60)
        var row: [String: Any] = [
            "type": "thread", "id": id, "title": title, "repo": repo, "status": status, "isPrivate": false,
            "isShared": false, "isUnattended": false, "isStarred": false, "canDelete": true, "isUnread": false,
            "group": group, "children": [[String: Any]](), "hasRepository": true,
            "lastActivityAt": ISO8601DateFormatter().string(from: when),
            "starter": ["key": starter, "name": starter == "yak" ? "Yak" : "Test person", "avatarUrl": NSNull()],
            "needsYouReason": reason ?? NSNull(), "needsYouLabel": label ?? NSNull(),
        ]
        row["source"] =
            source.map { ["key": $0, "label": labels[$0] ?? $0, "reference": NSNull(), "url": NSNull()] } ?? NSNull()
        return row
    }

    private static var needsYouRows: [[String: Any]] {
        [
            row(
                "n33d0001", "Batch geocode retries on timeout", source: "slack", minutesAgo: 2, reason: "question",
                label: "Asked you a question"),
            row(
                "n33d0002", "Null spreadsheet in BatchJob", source: "sentry", repo: "example/api", status: "blocked",
                minutesAgo: 40, starter: "yak", reason: "blocked", label: "Failed"),
        ]
    }

    private static var todayRows: [[String: Any]] {
        [
            row("t0day001", "Retry failed rows when the geocoder times out", source: "slack", minutesAgo: 0),
            row("t0day002", "Move search to the new index", source: "linear", minutesAgo: 60, starter: "yak"),
            row("t0day003", "Dark mode for the docs site", source: "dashboard", repo: "example/docs", minutesAgo: 180),
        ]
    }

    private static var everyoneRows: [[String: Any]] {
        [
            row(
                "e0ne0001", "Nightly import webhook", source: "dashboard", repo: "example/api", minutesAgo: 25,
                starter: "user:2"),
            row("e0ne0002", "Answer the billing question", source: "dashboard", minutesAgo: 190, starter: "user:2"),
        ]
    }

    private static var closedRows: [[String: Any]] {
        [
            row(
                "c10sed01", "Typo on the pricing page", source: "dashboard", status: "closed", group: "this_week",
                minutesAgo: 60 * 30),
            row(
                "c10sed02", "Retry the export on a 502", source: "sentry", repo: "example/api", status: "closed",
                group: "this_week", minutesAgo: 60 * 50),
            row(
                "c10sed03", "Old CSV upload limits", source: "slack", status: "closed", group: "older",
                minutesAgo: 60 * 24 * 12),
        ]
    }
}
