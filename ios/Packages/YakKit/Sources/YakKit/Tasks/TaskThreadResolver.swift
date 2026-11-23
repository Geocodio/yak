/// Where a task id leads now that every piece of work is a thread.
public enum TaskTarget: Equatable, Sendable {
    /// The work lives on this thread.
    case thread(String)
    /// The task has no thread (a review, a setup run); its web page is the only place that shows it.
    case webPage
    /// The server does not know the task, or the person may not see it.
    case notFound
    /// The server could not be asked or did not answer well.
    case failed
}

public enum TaskThreadResolver {
    /// Asks the server for the task and reads the thread it belongs to.
    public static func resolve(taskID: String, client: APIClient) async -> TaskTarget {
        do {
            let task: AppTask = try await client.send(.task(taskID))
            guard let threadID = task.threadId, !threadID.isEmpty else { return .webPage }
            return .thread(threadID)
        } catch {
            if case .server(let failure) = error, failure.status == 404 || failure.status == 403 { return .notFound }
            return .failed
        }
    }
}
