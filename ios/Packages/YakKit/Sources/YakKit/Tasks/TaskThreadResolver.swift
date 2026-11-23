/// Where a task id leads now that every piece of work is a thread.
public enum TaskTarget: Equatable, Sendable {
    /// The work lives on this thread.
    case thread(String)
    /// The task has no thread (a review, a setup run); its web page is the only place that shows it.
    case webPage
    /// The server does not know the task, or the person may not see it.
    case notFound
    /// The phone has no connection to the server.
    case offline
    /// The server answered with an error or with something that is not a task.
    case failed
    /// The device is not signed in any more; the session already moves the app to the pairing screen.
    case needsPairing
}

/// The one field of a task the app reads to find its thread.
struct TaskThreadRef: Decodable, Sendable {
    let threadId: String?
}

extension Endpoint where Response == TaskThreadRef {
    static func taskThread(_ id: String) -> Endpoint<TaskThreadRef> {
        Endpoint(method: .get, path: "tasks/\(segment(id))")
    }
}

public enum TaskThreadResolver {
    /// Asks the server for the task and reads the thread it belongs to.
    public static func resolve(taskID: String, client: APIClient) async -> TaskTarget {
        do {
            let task = try await client.send(.taskThread(taskID))
            guard let threadID = task.threadId, !threadID.isEmpty else { return .webPage }
            return .thread(threadID)
        } catch {
            switch error {
            case .unauthorized: return .needsPairing
            case .network: return .offline
            case .server(let failure): return failure.status == 404 || failure.status == 403 ? .notFound : .failed
            case .appTooOld, .serverTooOld, .notYak, .encodingFailed: return .failed
            }
        }
    }
}
