import Foundation

private struct RerouteBody: Encodable, Sendable { let repo: String }
private struct FeedbackBody: Encodable, Sendable { let feedback: String }
private struct TagBody: Encodable, Sendable {
    let userID: Int
    enum CodingKeys: String, CodingKey { case userID = "user_id" }
}

private struct TaskAnswersBody: Encodable, Sendable {
    let answers: [String: QuestionAnswer]
    let note: String?

    enum CodingKeys: String, CodingKey { case answers, note }

    /// `note` is always present in the body, `null` when there is none.
    func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(answers, forKey: .answers)
        try container.encode(note, forKey: .note)
    }
}

/// A trimmed value cut to `limit` characters, or nil when nothing is left.
private func cleaned(_ value: String?, limit: Int) -> String? {
    guard let trimmed = value?.trimmingCharacters(in: .whitespacesAndNewlines), !trimmed.isEmpty else { return nil }
    return String(trimmed.prefix(limit))
}

private func pageItem(_ page: Int) -> URLQueryItem {
    URLQueryItem(name: "page", value: String(min(max(page, 1), 1000)))
}

extension Endpoint where Response == TaskListPage {
    /// Query items in the contract's order, only the ones that are set.
    public static func tasks(_ query: TaskListQuery, page: Int) -> Endpoint<TaskListPage> {
        var items = [URLQueryItem(name: "tab", value: query.tab.rawValue)]
        if let status = cleaned(query.status?.rawValue, limit: 32) {
            items.append(URLQueryItem(name: "status", value: status))
        }
        if query.tab == .tasks, let source = cleaned(query.source, limit: 64) {
            items.append(URLQueryItem(name: "source", value: source))
        }
        if let repo = cleaned(query.repo, limit: 255) { items.append(URLQueryItem(name: "repo", value: repo)) }
        if let pullRequest = cleaned(query.pullRequest, limit: 16) {
            items.append(URLQueryItem(name: "pr", value: pullRequest))
        }
        if let search = cleaned(query.search, limit: 100) { items.append(URLQueryItem(name: "search", value: search)) }
        items.append(pageItem(page))
        return Endpoint(method: .get, path: "tasks", query: items)
    }
}

extension Endpoint where Response == AppTask {
    public static func task(_ id: String) -> Endpoint<AppTask> {
        Endpoint(method: .get, path: "tasks/\(segment(id))")
    }
}

extension Endpoint where Response == StatusResponse {
    public static func taskAction(_ id: String, _ action: TaskAction) -> Endpoint<StatusResponse> {
        let base = "tasks/\(segment(id))"
        switch action {
        case .retry: return Endpoint(method: .post, path: "\(base)/retry")
        case .cancel: return Endpoint(method: .post, path: "\(base)/cancel")
        case .rerunReview: return Endpoint(method: .post, path: "\(base)/rerun-review")
        case .retryRender: return Endpoint(method: .post, path: "\(base)/retry-render")
        case .reroute(let repo):
            return Endpoint(
                method: .post, path: "\(base)/reroute", body: RerouteBody(repo: cleaned(repo, limit: 255) ?? ""))
        case .approve:
            // Approve opens the pull request before it answers, which can take longer than 15 seconds.
            return Endpoint(method: .post, path: "\(base)/co-owner/approve", timeout: .seconds(60))
        case .requestChanges(let feedback):
            return Endpoint(
                method: .post, path: "\(base)/co-owner/request-changes",
                body: FeedbackBody(feedback: cleaned(feedback, limit: 5000) ?? ""))
        case .reject: return Endpoint(method: .post, path: "\(base)/co-owner/reject")
        case .withdraw: return Endpoint(method: .post, path: "\(base)/co-owner/withdraw")
        case .tag(let userID):
            return Endpoint(method: .post, path: "\(base)/co-owner/tag", body: TagBody(userID: userID))
        case .decline: return Endpoint(method: .post, path: "\(base)/co-owner/decline")
        }
    }

    public static func answerTaskQuestions(_ id: String, answers: [String: QuestionAnswer], note: String?)
        -> Endpoint<StatusResponse>
    {
        Endpoint(
            method: .post, path: "tasks/\(segment(id))/clarification-answers",
            body: TaskAnswersBody(answers: answers, note: note))
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
