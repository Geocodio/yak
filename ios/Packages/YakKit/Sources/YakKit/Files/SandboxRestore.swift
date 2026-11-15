import Foundation

public enum RestoreOutcome: Equatable, Sendable {
    /// 200 restoring, or the sandbox needed no restore (409 nothing_to_restore).
    case started
    /// forbidden, thread_closed, thread_owned_by_device
    case refused(message: String)
    case failed(ThreadProblem)
}

/// One per thread, shared by the Terminal and Files panes. Calls made while one runs wait for it and get its outcome.
public actor SandboxRestore {
    private let perform: @Sendable () async -> ThreadActionOutcome
    private var current: (id: Int, task: Task<RestoreOutcome, Never>)?
    private var nextID = 0

    public init(perform: @escaping @Sendable () async -> ThreadActionOutcome) {
        self.perform = perform
    }

    public var isRunning: Bool { current != nil }

    public func run() async -> RestoreOutcome {
        if let current { return await current.task.value }
        nextID += 1
        let id = nextID
        let perform = perform
        // Unstructured, so one caller giving up does not cancel the request the others wait for.
        let task = Task { Self.outcome(await perform()) }
        current = (id, task)
        let outcome = await task.value
        if current?.id == id { current = nil }
        return outcome
    }

    private static func outcome(_ result: ThreadActionOutcome) -> RestoreOutcome {
        switch result {
        case .done, .alreadySent: .started
        case .refused(let code, let message, _): code == "nothing_to_restore" ? .started : .refused(message: message)
        case .failed(let problem): .failed(problem)
        }
    }
}
