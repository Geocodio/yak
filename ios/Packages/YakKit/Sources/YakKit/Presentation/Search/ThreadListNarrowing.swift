import Foundation

extension ThreadList {
    /// The rows of this list that match `text`, for the search field while the server is not asked: below two
    /// characters and offline. A root row matches by its own title, repository or source, or by a sub-thread's
    /// title; a matching row keeps all its sub-threads. Counts and facets stay the list's own.
    public func narrowed(to text: String) -> ThreadList {
        let needle = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !needle.isEmpty else { return self }
        func matches(_ row: ThreadRow) -> Bool {
            [row.title, row.repo, row.source?.displayName ?? ""].contains {
                $0.localizedStandardContains(needle)
            } || row.children.contains(where: matches)
        }
        return ThreadList(
            starred: starred.filter(matches),
            needsYou: needsYou.filter {
                switch $0 {
                case .thread(let row): matches(row)
                case .task(let task): task.title.localizedStandardContains(needle)
                case .unknown: false
                }
            },
            threads: threads.filter(matches), needsYouCount: needsYouCount, hasAnyThreads: hasAnyThreads,
            reviewCount: reviewCount, needsGitHubLink: needsGitHubLink, gitHubLinkPath: gitHubLinkPath,
            reviewGroups: reviewGroups.map { ReviewGroup(key: $0.key, label: $0.label, rows: $0.rows.filter(matches)) },
            facets: facets)
    }
}
