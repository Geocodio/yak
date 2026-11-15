import Foundation

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

/// Records what it was asked to start and hands out task ids 1, 2, 3.
final class FakeUploadSession: UploadSession, @unchecked Sendable {
    struct Started: Sendable {
        let request: URLRequest
        let bodyPath: String
        let description: UploadTaskDescription
    }

    let identifier: String
    private let lock = NSLock()
    private var startedTasks: [Started] = []
    private var live: Set<UploadTaskDescription> = []
    private var problem: UploadStartProblem?
    private var hook: (@Sendable (UploadTaskDescription) async -> Void)?

    init(identifier: String) { self.identifier = identifier }

    var started: [Started] { lock.withLock { startedTasks } }

    var liveSet: Set<UploadTaskDescription> {
        get { lock.withLock { live } }
        set { lock.withLock { live = newValue } }
    }

    var startProblem: UploadStartProblem? {
        get { lock.withLock { problem } }
        set { lock.withLock { problem = newValue } }
    }

    /// Runs inside `start`, before it returns: a task that answers while the caller still waits.
    var duringStart: (@Sendable (UploadTaskDescription) async -> Void)? {
        get { lock.withLock { hook } }
        set { lock.withLock { hook = newValue } }
    }

    func start(_ request: URLRequest, bodyPath: String, description: UploadTaskDescription)
        async throws(UploadStartProblem) -> Int
    {
        await duringStart?(description)
        let outcome: Result<Int, UploadStartProblem> = lock.withLock {
            if let problem { return .failure(problem) }
            startedTasks.append(Started(request: request, bodyPath: bodyPath, description: description))
            live.insert(description)
            return .success(startedTasks.count)
        }
        return try outcome.get()
    }

    func liveTasks() async -> Set<UploadTaskDescription> { liveSet }
}
