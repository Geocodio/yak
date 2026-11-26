import Foundation

/// One match of `GET search`: a thread, a repository or a pull request.
public struct SearchResult: Equatable, Sendable, Identifiable {
    public enum Kind: Equatable, Hashable, Sendable, CaseIterable {
        case thread, repository, pullRequest
    }

    public let kind: Kind
    public let id: String
    public let title: String
    /// The line under the title: "repository / branch", the repository's slug or "repository #number".
    public let subtitle: String
    /// The thread to open: a thread result's own id, or the thread a pull request belongs to.
    public let threadID: String?
    /// The instance's web page for the result, starting with one `/`; nil for a pull request without a review page.
    public let path: String?
    /// The page on GitHub, when it passes the link rule.
    public let url: URL?

    public init(
        kind: Kind, id: String, title: String, subtitle: String, threadID: String? = nil, path: String? = nil,
        url: URL? = nil
    ) {
        self.kind = kind
        self.id = id
        self.title = title
        self.subtitle = subtitle
        self.threadID = threadID
        self.path = path
        self.url = url
    }

    /// Unique across the kinds, so a list of mixed results has stable row identities.
    public var identity: String {
        switch kind {
        case .thread: "thread:\(id)"
        case .repository: "repository:\(id)"
        case .pullRequest: "pullRequest:\(id)"
        }
    }
}

/// The results of one search, in the three groups the drawer shows.
public struct SearchGroup: Equatable, Sendable, Identifiable {
    public let kind: SearchResult.Kind
    public let results: [SearchResult]
    public var id: SearchResult.Kind { kind }

    /// The groups that have results, in the order threads, repositories, pull requests.
    public static func groups(of results: [SearchResult]) -> [SearchGroup] {
        SearchResult.Kind.allCases.compactMap { kind in
            let matching = results.filter { $0.kind == kind }
            return matching.isEmpty ? nil : SearchGroup(kind: kind, results: matching)
        }
    }
}

/// The answer to `GET search`. A result of a kind this build does not know, or without an id or a title, is left out.
public struct SearchAnswer: Decodable, Sendable, Equatable {
    public let results: [SearchResult]

    public init(results: [SearchResult]) { self.results = results }

    private struct Item: Decodable {
        let type: String?
        let id: String?
        let title: String?
        let subtitle: String?
        let threadId: String?
        let path: String?
        let url: String?

        var result: SearchResult? {
            let kind: SearchResult.Kind
            switch type {
            case "thread": kind = .thread
            case "repository": kind = .repository
            case "pull_request": kind = .pullRequest
            default: return nil
            }
            guard let id, !id.isEmpty, let title, !title.isEmpty else { return nil }
            return SearchResult(
                kind: kind, id: id, title: title, subtitle: subtitle ?? "", threadID: threadId?.nilIfEmpty,
                path: path.flatMap { $0.hasPrefix("/") && !$0.hasPrefix("//") ? $0 : nil },
                url: WebLinkRule.safeURL(url))
        }
    }

    private struct Skippable: Decodable {
        let item: Item?
        init(from decoder: any Decoder) throws { item = try? Item(from: decoder) }
    }

    private enum CodingKeys: String, CodingKey { case results }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        let items = (try? container.decode([Skippable].self, forKey: .results)) ?? []
        results = items.compactMap { $0.item?.result }
    }

    public var groups: [SearchGroup] { SearchGroup.groups(of: results) }
}

extension String {
    fileprivate var nilIfEmpty: String? { isEmpty ? nil : self }
}
