import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ action: ThreadLifecycleAction, _ failure: APIFailure) {
    #if canImport(os)
        let reason: String
        switch failure {
        case .server(let error): reason = "server \(error.code)"
        case .network(let code): reason = "network \(code.rawValue)"
        default: reason = "\(failure)"
        }
        Logger(subsystem: "YakKit", category: "ThreadLifecycle")
            .error("\(action.rawValue, privacy: .public) failed: \(reason, privacy: .public)")
    #endif
}

public enum ThreadLifecycleAction: String, Sendable, Hashable { case star, close, delete }

/// Why a star, close or delete did not happen. Each is a designed answer on screen.
public enum ThreadLifecycleFailure: Equatable, Sendable {
    /// The request never reached the server.
    case offline
    /// 401: the device must pair again.
    case unauthorized
    /// 403: the person may not drive this thread.
    case forbidden
    /// 404: the thread is gone. It has been removed from the lists and the disk.
    case gone
    /// 409 `sandbox_unavailable` on a delete: a sandbox could not be destroyed and nothing was deleted. The server's
    /// own sentence.
    case sandboxUnavailable(message: String)
    /// A timeout, a 5xx answer or anything else.
    case failed

    init(_ failure: APIFailure) {
        switch failure {
        case .unauthorized: self = .unauthorized
        case .network(let code): self = code == .timedOut ? .failed : .offline
        case .server(let error):
            switch (error.status, error.code) {
            case (403, _): self = .forbidden
            case (404, _): self = .gone
            case (409, "sandbox_unavailable"): self = .sandboxUnavailable(message: error.message)
            default: self = .failed
            }
        default: self = .failed
        }
    }
}

public enum ThreadLifecycleOutcome: Equatable, Sendable {
    case done
    case failed(ThreadLifecycleFailure)
}

/// What the actions changed, for the screens that react (leave a thread, say "Thread deleted.").
public enum ThreadLifecycleChange: Equatable, Sendable {
    case starred(id: String, isStarred: Bool)
    case closed(id: String)
    /// The thread was deleted by this device. It is gone from every list and from the disk.
    case deleted(id: String)
    /// The server says the thread does not exist (any action answered 404). It is removed like a deleted one.
    case gone(id: String)
}

/// The requests that run now, by thread.
public struct ThreadLifecycleState: Equatable, Sendable {
    public var running: [String: Set<ThreadLifecycleAction>] = [:]

    public init(running: [String: Set<ThreadLifecycleAction>] = [:]) { self.running = running }

    public func isDeleting(_ threadID: String) -> Bool { running[threadID]?.contains(.delete) == true }
    /// Any request is running for the thread, so no other action may start on it.
    public func isBusy(_ threadID: String) -> Bool { !(running[threadID]?.isEmpty ?? true) }
}

/// Star, close and delete a thread. Star is optimistic and rolls back; close and delete wait for the server. One
/// request per thread and action runs at a time: a repeat joins the one in flight. After a success the thread list,
/// the saved copies and the unsent messages reflect it, with the server's row where the answer carries one.
public actor ThreadLifecycle {
    private let client: APIClient
    private let cache: Cache
    private let feed: ThreadListFeed
    private let offlineCopies: OfflineCopyStore
    private let outbox: Outbox?
    private let stateBroadcast = Broadcast(ThreadLifecycleState())
    private var subscribers: [Int: AsyncStream<ThreadLifecycleChange>.Continuation] = [:]
    private var nextSubscriber = 0
    private var tasks: [String: (target: Bool, task: Task<ThreadLifecycleOutcome, Never>)] = [:]

    public init(client: APIClient, cache: Cache, feed: ThreadListFeed, offlineCopies: OfflineCopyStore, outbox: Outbox?)
    {
        self.client = client
        self.cache = cache
        self.feed = feed
        self.offlineCopies = offlineCopies
        self.outbox = outbox
    }

    public func states() -> AsyncStream<ThreadLifecycleState> { stateBroadcast.stream() }

    public func changes() -> AsyncStream<ThreadLifecycleChange> {
        let (stream, continuation) = AsyncStream.makeStream(of: ThreadLifecycleChange.self, bufferingPolicy: .unbounded)
        nextSubscriber += 1
        let identifier = nextSubscriber
        subscribers[identifier] = continuation
        continuation.onTermination = { [weak self] _ in Task { await self?.removeSubscriber(identifier) } }
        return stream
    }

    private func removeSubscriber(_ identifier: Int) { subscribers[identifier] = nil }

    private func emit(_ change: ThreadLifecycleChange) {
        for continuation in subscribers.values { continuation.yield(change) }
    }

    private func mark(_ action: ThreadLifecycleAction, _ threadID: String, running isRunning: Bool) {
        var state = stateBroadcast.value
        if isRunning {
            state.running[threadID, default: []].insert(action)
        } else {
            state.running[threadID]?.remove(action)
            if state.running[threadID]?.isEmpty == true { state.running[threadID] = nil }
        }
        stateBroadcast.send(state)
    }

    /// Runs `work` as the one request of `action` for the thread; a repeat with the same target joins it, and one with
    /// another target runs after it.
    private func single(
        _ action: ThreadLifecycleAction, _ threadID: String, target: Bool = true,
        _ work: @escaping @Sendable (ThreadLifecycle) async -> ThreadLifecycleOutcome
    ) async -> ThreadLifecycleOutcome {
        let key = "\(action.rawValue):\(threadID)"
        while let running = tasks[key] {
            let outcome = await running.task.value
            if running.target == target { return outcome }
        }
        let task = Task { () -> ThreadLifecycleOutcome in
            self.mark(action, threadID, running: true)
            let outcome = await work(self)
            self.mark(action, threadID, running: false)
            self.tasks[key] = nil
            return outcome
        }
        tasks[key] = (target, task)
        return await task.value
    }

    // MARK: Star

    /// The star changes in the list at once and goes back when the server refuses.
    public func setStar(_ threadID: String, to isStarred: Bool) async -> ThreadLifecycleOutcome {
        await single(.star, threadID, target: isStarred) { lifecycle in
            await lifecycle.performStar(threadID, to: isStarred)
        }
    }

    private func performStar(_ threadID: String, to isStarred: Bool) async -> ThreadLifecycleOutcome {
        await feed.apply(.setStar(id: threadID, isStarred: isStarred))
        do throws(APIFailure) {
            let row = try await client.send(.starThread(threadID, starred: isStarred))
            await feed.apply(.replace(row))
            emit(.starred(id: threadID, isStarred: row.isStarred))
            return .done
        } catch {
            logFailure(.star, error)
            let failure = ThreadLifecycleFailure(error)
            if failure == .gone {
                await purge(threadID)
                emit(.gone(id: threadID))
            } else {
                await feed.apply(.setStar(id: threadID, isStarred: !isStarred))
            }
            return .failed(failure)
        }
    }

    // MARK: Close

    /// Waits for the server. The row of the answer takes the thread's place; one list refresh follows, because
    /// the sub-threads close with it.
    public func close(_ threadID: String) async -> ThreadLifecycleOutcome {
        await single(.close, threadID) { lifecycle in await lifecycle.performClose(threadID) }
    }

    private func performClose(_ threadID: String) async -> ThreadLifecycleOutcome {
        do throws(APIFailure) {
            let row = try await client.send(.closeThread(threadID))
            await feed.apply(.replace(row))
            emit(.closed(id: threadID))
            await feed.refresh()
            return .done
        } catch {
            logFailure(.close, error)
            let failure = ThreadLifecycleFailure(error)
            if failure == .gone {
                await purge(threadID)
                emit(.gone(id: threadID))
            }
            return .failed(failure)
        }
    }

    // MARK: Delete

    /// Waits for the server, which destroys the sandboxes first. Nothing is removed locally until it says so.
    public func delete(_ threadID: String) async -> ThreadLifecycleOutcome {
        await single(.delete, threadID) { lifecycle in await lifecycle.performDelete(threadID) }
    }

    private func performDelete(_ threadID: String) async -> ThreadLifecycleOutcome {
        do throws(APIFailure) {
            _ = try await client.send(.deleteThread(threadID))
            await purge(threadID)
            emit(.deleted(id: threadID))
            return .done
        } catch {
            logFailure(.delete, error)
            let failure = ThreadLifecycleFailure(error)
            if failure == .gone {
                await purge(threadID)
                emit(.gone(id: threadID))
            }
            return .failed(failure)
        }
    }

    /// Removes what the device holds for a thread that no longer exists: its row in every tab, its saved page, its
    /// saved offline copy, its draft and its unsent messages with their files and uploads.
    fileprivate func forget(_ threadID: String) async {
        await purge(threadID)
        emit(.gone(id: threadID))
    }

    private func purge(_ threadID: String) async {
        await feed.apply(.remove(id: threadID))
        await cache.removeThread(threadID)
        await cache.saveComposerDraft(nil, for: .thread(threadID))
        await offlineCopies.remove(thread: threadID)
        guard let outbox else { return }
        for item in await outbox.items() where item.target == .thread(threadID) {
            // An item that is sending right now stays; the server refuses it and it shows as not sent.
            _ = try? await outbox.remove(item.id)
        }
    }
}

extension ThreadLifecycle {
    /// A page or an engine learned that the thread does not exist any more (deleted on another device). The device
    /// forgets it the same way as after its own delete.
    public func noteGone(_ threadID: String) async {
        await forget(threadID)
    }
}
