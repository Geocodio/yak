import Foundation

private struct StartThreadBody: Encodable, Sendable {
    let repo: String
    let mode: String
    let description: String
    let repositories: [NewThreadDraft.ExtraRepository]
    let claudeAccountId: Int?
    let model: String?
    let effort: EffortLevel?
    let sendKey: String

    enum CodingKeys: String, CodingKey {
        case repo, mode, description, repositories, model, effort
        case claudeAccountId = "claude_account_id"
        case sendKey = "send_key"
    }
}

private struct SendMessageBody: Encodable, Sendable {
    let message: String
    let model: String?
    let effort: EffortLevel?
    let sendKey: String
    let delivery: MessageDelivery
    let intent: MessageIntent?

    enum CodingKeys: String, CodingKey {
        case message, model, effort, delivery, intent
        case sendKey = "send_key"
    }
}

private struct UpdateQueuedMessageBody: Encodable, Sendable {
    let mode: MessageMode?
    let text: String?
}

private struct ChannelAuthorizationBody: Encodable, Sendable {
    let socketID: String
    let channelName: String

    enum CodingKeys: String, CodingKey {
        case socketID = "socket_id"
        case channelName = "channel_name"
    }
}

/// File parts named `attachments[]`, each followed by its `attachment_refs[]` field.
private func attachmentParts(_ attachments: [MultipartBody.Part], references: [String]) -> [MultipartBody.Part] {
    var parts: [MultipartBody.Part] = []
    var fileIndex = 0
    for attachment in attachments {
        guard case .file(_, let fileName, let contentType, let data) = attachment else { continue }
        parts.append(.file(name: "attachments[]", fileName: fileName, contentType: contentType, data: data))
        if fileIndex < references.count {
            parts.append(.field(name: "attachment_refs[]", value: references[fileIndex]))
        }
        fileIndex += 1
    }
    return parts
}

extension Endpoint where Response == ThreadList {
    public static func threads(
        filter: ThreadListFilter, search: String?, sources: [String] = [], starters: [String] = []
    ) -> Endpoint<ThreadList> {
        var query = [URLQueryItem(name: "filter", value: filter.rawValue)]
        query += sources.map { URLQueryItem(name: "sources[]", value: $0) }
        query += starters.map { URLQueryItem(name: "starters[]", value: $0) }
        let trimmed = search?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if !trimmed.isEmpty {
            // The server's ListThreadsRequest allows at most 100 characters.
            query.append(URLQueryItem(name: "search", value: String(trimmed.prefix(100))))
        }
        return Endpoint(method: .get, path: "threads", query: query)
    }
}

extension Endpoint where Response == ThreadShow {
    public static func thread(_ id: String) -> Endpoint<ThreadShow> {
        Endpoint(method: .get, path: "threads/\(segment(id))")
    }
}

extension Endpoint where Response == EmptyResponse {
    public static func markSeen(_ id: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .post, path: "threads/\(segment(id))/seen")
    }

    public static func withdrawQueuedMessage(_ id: String, uuid: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "threads/\(segment(id))/queued-messages/\(segment(uuid))")
    }
}

extension Endpoint where Response == NewThreadOptions {
    public static func newThreadOptions() -> Endpoint<NewThreadOptions> {
        Endpoint(method: .get, path: "threads/new")
    }
}

extension Endpoint where Response == ThreadCreated {
    public static func startThread(
        _ draft: NewThreadDraft, description: String, sendKey: UUID, attachments: [MultipartBody.Part],
        references: [String]
    ) -> Endpoint<ThreadCreated> {
        if attachments.isEmpty {
            let body = StartThreadBody(
                repo: draft.repo, mode: draft.mode, description: description, repositories: draft.repositories,
                claudeAccountId: draft.claudeAccountId, model: draft.model, effort: draft.effort,
                sendKey: sendKey.uuidString)
            return Endpoint(method: .post, path: "threads", body: body)
        }
        var parts: [MultipartBody.Part] = [
            .field(name: "repo", value: draft.repo),
            .field(name: "mode", value: draft.mode),
            .field(name: "description", value: description),
        ]
        for (index, extra) in draft.repositories.enumerated() {
            parts.append(.field(name: "repositories[\(index)][repo]", value: extra.repo))
            parts.append(.field(name: "repositories[\(index)][role]", value: extra.role))
        }
        if let accountID = draft.claudeAccountId {
            parts.append(.field(name: "claude_account_id", value: String(accountID)))
        }
        if let model = draft.model { parts.append(.field(name: "model", value: model)) }
        if let effort = draft.effort { parts.append(.field(name: "effort", value: effort.rawValue)) }
        parts.append(.field(name: "send_key", value: sendKey.uuidString))
        parts += attachmentParts(attachments, references: references)
        return Endpoint(
            method: .post, path: "threads",
            multipart: MultipartBody(parts: parts, boundary: "yak-\(sendKey.uuidString)"))
    }
}

extension Endpoint where Response == EventsPage {
    public static func events(_ id: String, after: Int, limit: Int) -> Endpoint<EventsPage> {
        Endpoint(
            method: .get, path: "threads/\(segment(id))/events",
            query: [
                URLQueryItem(name: "after", value: String(max(after, 0))),
                URLQueryItem(name: "limit", value: String(min(max(limit, 1), 500))),
            ])
    }
}

extension Endpoint where Response == ThreadEvent {
    public static func event(_ id: String, sequence: Int) -> Endpoint<ThreadEvent> {
        Endpoint(method: .get, path: "threads/\(segment(id))/events/\(sequence)")
    }
}

extension Endpoint where Response == MessageSendResult {
    public static func sendMessage(
        _ id: String, text: String, model: String?, effort: EffortLevel? = nil, delivery: MessageDelivery,
        sendKey: UUID,
        attachments: [MultipartBody.Part], references: [String], intent: MessageIntent? = nil
    ) -> Endpoint<MessageSendResult> {
        let path = "threads/\(segment(id))/messages"
        if attachments.isEmpty {
            let body = SendMessageBody(
                message: text, model: model, effort: effort, sendKey: sendKey.uuidString, delivery: delivery,
                intent: intent)
            return Endpoint(method: .post, path: path, body: body)
        }
        var parts: [MultipartBody.Part] = [.field(name: "message", value: text)]
        if let model { parts.append(.field(name: "model", value: model)) }
        if let effort { parts.append(.field(name: "effort", value: effort.rawValue)) }
        parts.append(.field(name: "send_key", value: sendKey.uuidString))
        parts.append(.field(name: "delivery", value: delivery.rawValue))
        if let intent { parts.append(.field(name: "intent", value: intent.rawValue)) }
        parts += attachmentParts(attachments, references: references)
        return Endpoint(
            method: .post, path: path, multipart: MultipartBody(parts: parts, boundary: "yak-\(sendKey.uuidString)"))
    }
}

extension Endpoint where Response == StatusResponse {
    public static func updateQueuedMessage(_ id: String, uuid: String, mode: MessageMode?, text: String?)
        -> Endpoint<StatusResponse>
    {
        Endpoint(
            method: .patch, path: "threads/\(segment(id))/queued-messages/\(segment(uuid))",
            body: UpdateQueuedMessageBody(mode: mode, text: text))
    }

    public static func stopTurn(_ id: String) -> Endpoint<StatusResponse> {
        Endpoint(method: .post, path: "threads/\(segment(id))/stop")
    }

    public static func retryRestore(_ id: String) -> Endpoint<StatusResponse> {
        Endpoint(method: .post, path: "threads/\(segment(id))/restore/retry")
    }
}

extension Endpoint where Response == ChannelAuthorization {
    public static func authorizeChannel(socketID: String, channelName: String) -> Endpoint<ChannelAuthorization> {
        Endpoint(
            method: .post, path: "broadcasting/auth",
            body: ChannelAuthorizationBody(socketID: socketID, channelName: channelName))
    }
}

extension Endpoint where Response == RepositoryAccess {
    public static func repositoryAccess(repo: String) -> Endpoint<RepositoryAccess> {
        Endpoint(method: .get, path: "threads/new/repository-access", query: [URLQueryItem(name: "repo", value: repo)])
    }
}

/// An action on the work of a thread. Every case but `checkAccess` changes the thread; the answer carries the
/// refreshed show.
public enum ThreadWorkAction: Equatable, Sendable {
    case retry, setUpAndContinue, cancel
    case move(repo: String)
    case requestReview, retryRender
    case answerRepository(repo: String)
    case approve
    case requestChanges(feedback: String)
    case reject, withdraw, checkAccess
    case tag(userID: Int)
    case decline

    /// The request is safe to send again after a lost answer: the server answers the same status. A move, a
    /// review request and a repository answer are not, because a repeat answers a refusal or finds the work gone.
    public var isSafeToResend: Bool {
        switch self {
        case .move, .requestReview, .answerRepository: false
        default: true
        }
    }
}

/// The answer of a thread action: the status word and every key of the thread's show.
public struct ThreadActionAnswer: Decodable, Equatable, Sendable {
    public let status: String
    public let show: ThreadShow

    public init(status: String, show: ThreadShow) {
        self.status = status
        self.show = show
    }

    private enum CodingKeys: String, CodingKey { case status }

    public init(from decoder: any Decoder) throws {
        status = try decoder.container(keyedBy: CodingKeys.self).decode(String.self, forKey: .status)
        show = try ThreadShow(from: decoder)
    }
}

extension Endpoint where Response == ThreadActionAnswer {
    public static func threadAction(_ id: String, _ action: ThreadWorkAction) -> Endpoint<ThreadActionAnswer> {
        let base = "threads/\(segment(id))"
        switch action {
        case .retry: return Endpoint(method: .post, path: "\(base)/retry")
        case .setUpAndContinue: return Endpoint(method: .post, path: "\(base)/setup-and-continue")
        case .cancel: return Endpoint(method: .post, path: "\(base)/cancel")
        case .move(let repo):
            return Endpoint(
                method: .post, path: "\(base)/move", body: RepositoryBody(repo: cleaned(repo, limit: 255) ?? ""))
        case .requestReview: return Endpoint(method: .post, path: "\(base)/request-review")
        case .retryRender: return Endpoint(method: .post, path: "\(base)/retry-render")
        case .answerRepository(let repo):
            return Endpoint(
                method: .post, path: "\(base)/repository-answer",
                body: RepositoryBody(repo: cleaned(repo, limit: 255) ?? ""))
        case .approve:
            // Approve opens the pull request before it answers, which can take longer than 15 seconds.
            return Endpoint(method: .post, path: "\(base)/co-owner/approve", timeout: .seconds(60))
        case .requestChanges(let feedback):
            return Endpoint(
                method: .post, path: "\(base)/co-owner/request-changes",
                body: FeedbackBody(feedback: cleaned(feedback, limit: 5000) ?? ""))
        case .reject: return Endpoint(method: .post, path: "\(base)/co-owner/reject")
        case .withdraw: return Endpoint(method: .post, path: "\(base)/co-owner/withdraw")
        case .checkAccess: return Endpoint(method: .post, path: "\(base)/co-owner/check-access")
        case .tag(let userID):
            return Endpoint(method: .post, path: "\(base)/co-owner/tag", body: TagBody(userID: userID))
        case .decline: return Endpoint(method: .post, path: "\(base)/co-owner/decline")
        }
    }
}

extension Endpoint where Response == CoOwnerCandidates {
    public static func threadCoOwnerCandidates(_ id: String) -> Endpoint<CoOwnerCandidates> {
        Endpoint(method: .get, path: "threads/\(segment(id))/co-owner/candidates")
    }
}
