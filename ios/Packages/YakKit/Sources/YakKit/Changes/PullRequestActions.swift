import Foundation

/// How opening a pull request ended, each case a designed state for the Open PR button.
public enum PullRequestSendOutcome: Equatable, Sendable {
    /// The server accepted it (opening, open or held): refresh the show.
    case started(PullRequestStatus)
    /// A 422 on `acknowledged_private_publish`: ask the person, then send again with it set.
    case needsConfirmation
    /// A 4xx answer such as `nothing_to_publish`, `forbidden` or `not_found`.
    case refused(code: String, message: String)
    /// A network failure or a 5xx answer: refresh the show, never resend.
    case failed(ThreadProblem)
}

/// The pull request requests of one thread. A request that is in flight is joined, never repeated, and
/// nothing is retried here: after a failure the model refreshes the show and the person decides.
public actor PullRequestActions {
    private let threadID: String
    private let client: APIClient
    private var openTask: Task<PullRequestSendOutcome, Never>?
    private var actionTasks: [String: Task<ThreadActionOutcome, Never>] = [:]

    public init(threadID: String, client: APIClient) {
        self.threadID = threadID
        self.client = client
    }

    /// One request at a time; a second call while one runs returns that call's outcome.
    public func open(acknowledgedPrivatePublish: Bool) async -> PullRequestSendOutcome {
        if let running = openTask { return await running.value }
        let task = Task { [threadID, client] () -> PullRequestSendOutcome in
            do throws(APIFailure) {
                let response = try await client.send(
                    .openPullRequest(threadID, acknowledgedPrivatePublish: acknowledgedPrivatePublish))
                return .started(PullRequestStatus(rawValue: response.status))
            } catch {
                switch ThreadActionOutcome(error) {
                case .refused(_, _, let fieldErrors) where fieldErrors["acknowledged_private_publish"] != nil:
                    return .needsConfirmation
                case .refused(let code, let message, _):
                    return .refused(code: code, message: message)
                case .failed(let problem):
                    return .failed(problem)
                case .done, .alreadySent:
                    return .failed(.server(code: "unexpected", message: ""))
                }
            }
        }
        openTask = task
        let outcome = await task.value
        openTask = nil
        return outcome
    }

    public func askToFix(rowID: Int) async -> ThreadActionOutcome {
        await perform("fix:\(rowID)", .askToFix(threadID, rowID: rowID))
    }

    public func reorder(_ order: [Int]) async -> ThreadActionOutcome {
        await perform("reorder:\(order)", .reorderPullRequests(threadID, order: order))
    }

    private func perform(_ key: String, _ endpoint: Endpoint<StatusResponse>) async -> ThreadActionOutcome {
        if let running = actionTasks[key] { return await running.value }
        let client = client
        let task = Task { () -> ThreadActionOutcome in
            do throws(APIFailure) {
                _ = try await client.send(endpoint)
                return .done
            } catch {
                return ThreadActionOutcome(error)
            }
        }
        actionTasks[key] = task
        let outcome = await task.value
        actionTasks[key] = nil
        return outcome
    }
}
