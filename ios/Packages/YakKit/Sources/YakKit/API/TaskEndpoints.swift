import Foundation

private func pageItem(_ page: Int) -> URLQueryItem {
    URLQueryItem(name: "page", value: String(min(max(page, 1), 1000)))
}

extension Endpoint where Response == AppTask {
    public static func task(_ id: String) -> Endpoint<AppTask> {
        Endpoint(method: .get, path: "tasks/\(segment(id))")
    }
}

extension Endpoint where Response == CoOwnerCandidates {
    public static func coOwnerCandidates(_ id: String) -> Endpoint<CoOwnerCandidates> {
        Endpoint(method: .get, path: "tasks/\(segment(id))/co-owner/candidates")
    }
}

extension Endpoint where Response == ObservationPage {
    public static func observations(_ query: ObservationQuery, page: Int) -> Endpoint<ObservationPage> {
        var items: [URLQueryItem] = []
        if let repo = cleaned(query.repo, limit: 255) { items.append(URLQueryItem(name: "repo", value: repo)) }
        if let outcome = cleaned(query.outcome?.rawValue, limit: 16) {
            items.append(URLQueryItem(name: "outcome", value: outcome))
        }
        if let search = cleaned(query.search, limit: 100) { items.append(URLQueryItem(name: "search", value: search)) }
        items.append(pageItem(page))
        return Endpoint(method: .get, path: "observations", query: items)
    }
}
