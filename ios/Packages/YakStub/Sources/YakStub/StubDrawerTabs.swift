import Foundation

/// The thread list of the `drawer-tabs` scenarios: rows that read like the boards, one list per tab, narrowed by the
/// sources, starters and search the app sends.
extension Scenario {
    private var isDrawerTabs: Bool {
        self == .drawerTabs || self == .drawerTabsQuiet || self == .drawerTabsOlder || self == .drawerTabsLink
            || self == .reviewLine || self == .reviewLineFew || self == .reviewLineEmpty
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
            if self == .reviewLine { list["reviewGroups"] = Self.reviewLineGroups }
            if self == .reviewLineFew {
                let keep = ["rv1c0001", "rv1c0004", "rv1c0008"]
                list["reviewGroups"] = Self.reviewLineGroups.map { group -> [String: Any] in
                    var group = group
                    group["rows"] = (group["rows"] as? [[String: Any]] ?? []).filter {
                        keep.contains($0["id"] as? String ?? "")
                    }
                    return group
                }
            }
            if self == .reviewLineEmpty {
                list["reviewGroups"] = Self.reviewLineGroups.map { group -> [String: Any] in
                    var group = group
                    group["rows"] = [[String: Any]]()
                    return group
                }
                list["reviewCount"] = 0
            }
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
            if self == .reviewLine {
                // The failed row carries the server's 200 characters of error.
                list["needsYou"] = Self.needsYouRows.map { row -> [String: Any] in
                    guard row["needsYouLabel"] as? String == "Failed" else { return row }
                    var row = row
                    row["needsYouDetail"] = Self.longError
                    return row
                }
            }
        }
        let counted = (list["needsYou"] as? [[String: Any]] ?? []) + (list["threads"] as? [[String: Any]] ?? [])
        if self != .drawerTabsOlder {
            list["facets"] = Self.facets(of: counted)
            list["reviewCount"] = self == .drawerTabsQuiet || self == .reviewLineEmpty ? 0 : 2
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

    /// The error the server sends for a failed thread: its first 200 characters.
    static let longError =
        "CI failed twice on yak/null-spreadsheet: BatchJobTest, 3 tests failed. Expected 200 rows, got 0. The import stops at the first empty sheet."

    private static func pullRequest(
        _ number: Int, repository: String = "example/web", draft: Bool = false, mergeability: String = "no_conflicts",
        checks: String? = nil
    ) -> [String: Any] {
        [
            "number": number, "url": "https://github.com/\(repository)/pull/\(number)", "repository": repository,
            "isDraft": draft, "mergeability": mergeability, "checks": checks ?? NSNull(), "approvedByYou": false,
        ]
    }

    private static func reviewRow(
        _ id: String, _ title: String, source: String, repo: String = "example/web", minutesAgo: Int,
        pullRequests: [[String: Any]], newCommits: Bool = false, others: Int = 0, status: String = "stopped"
    ) -> [String: Any] {
        var result = row(id, title, source: source, repo: repo, status: status, minutesAgo: minutesAgo)
        result["review"] = [
            "pullRequests": pullRequests, "hasNewCommitsSinceApproval": newCommits, "otherApprovals": others,
        ]
        return result
    }

    /// Every state of board K2 across the four groups.
    private static var reviewLineGroups: [[String: Any]] {
        [
            [
                "key": "back_to_you", "label": "Back to you",
                "rows": [
                    reviewRow(
                        "rv1c0001", "Approved, then Yak pushed a fix", source: "slack", minutesAgo: 12,
                        pullRequests: [pullRequest(262, checks: "passing")], newCommits: true),
                    reviewRow(
                        "rv1c0002", "Pull sandbox files in chunks", source: "linear", minutesAgo: 60,
                        pullRequests: [pullRequest(264, mergeability: "conflicts", checks: "failing")], others: 1),
                ],
            ],
            [
                "key": "not_reviewed", "label": "Not reviewed",
                "rows": [
                    reviewRow(
                        "rv1c0003", "Queue size and archive answers", source: "dashboard", repo: "example/api",
                        minutesAgo: 120,
                        pullRequests: [pullRequest(267, repository: "example/api", draft: true, checks: "failing")]),
                    reviewRow(
                        "rv1c0004", "Search across two repositories", source: "slack", minutesAgo: 180,
                        pullRequests: [
                            pullRequest(271, checks: "passing"),
                            pullRequest(88, repository: "example/api", checks: "passing"),
                        ], others: 3),
                    reviewRow(
                        "rv1c0005", "Draft with no checks seen yet", source: "slack", minutesAgo: 200,
                        pullRequests: [pullRequest(263, draft: true)]),
                    reviewRow(
                        "rv1c0006", "Approved before the last push, others approved", source: "slack", minutesAgo: 210,
                        pullRequests: [pullRequest(268, checks: "passing")], newCommits: true, others: 2),
                ],
            ],
            [
                "key": "with_yak", "label": "With Yak",
                "rows": [
                    reviewRow(
                        "rv1c0007", "Recover a runner after a restart", source: "sentry", minutesAgo: 0,
                        pullRequests: [pullRequest(261)], status: "running")
                ],
            ],
            [
                "key": "approved", "label": "Approved by you",
                "rows": [
                    reviewRow(
                        "rv1c0008", "Recover failures without a nudge", source: "dashboard", minutesAgo: 1440,
                        pullRequests: [pullRequest(275, checks: "passing")], others: 2),
                    reviewRow(
                        "rv1c0009", "Typo on the pricing page", source: "dashboard", minutesAgo: 2880,
                        pullRequests: [pullRequest(259, checks: "passing")]),
                ],
            ],
        ]
    }

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
