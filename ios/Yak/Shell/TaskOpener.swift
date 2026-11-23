import Foundation
import Observation
import YakKit

/// Opens the thread a task link, push or row names. One request at a time: a newer one replaces the older, and a
/// person who moved on is never pulled away by an answer that arrives late. A request made before the session can
/// ask the server waits and runs once it can.
@MainActor @Observable
final class TaskOpener {
    /// The task being looked up, for the quiet spinner on its row.
    private(set) var openingID: String?
    /// Why the last request led nowhere, for the alert; nil once the person dismissed it.
    var problem: TaskTarget?

    /// Asks the server where the task lives; nil while there is no signed-in client yet.
    @ObservationIgnored private let resolve: @MainActor (String) async -> TaskTarget?
    @ObservationIgnored private let openThread: @MainActor (String) -> Void
    @ObservationIgnored private let openWebPage: @MainActor (String) -> Bool
    @ObservationIgnored private var pendingID: String?
    @ObservationIgnored private var running: Task<Void, Never>?

    init(
        resolve: @escaping @MainActor (String) async -> TaskTarget?,
        openThread: @escaping @MainActor (String) -> Void,
        openWebPage: @escaping @MainActor (String) -> Bool
    ) {
        self.resolve = resolve
        self.openThread = openThread
        self.openWebPage = openWebPage
    }

    /// Takes a request. The last one wins.
    func open(taskID: String) {
        pendingID = taskID
        openingID = taskID
        problem = nil
        start()
    }

    /// The session became active: a request that waited runs now.
    func sessionBecameActive() {
        if pendingID != nil, running == nil { start() }
    }

    /// The person moved on or left: nothing that is still on its way may navigate or alert.
    func cancel() {
        running?.cancel()
        running = nil
        pendingID = nil
        openingID = nil
    }

    private func start() {
        running?.cancel()
        guard let id = pendingID else { return }
        running = Task { [weak self] in
            guard let self else { return }
            let target = await resolve(id)
            guard !Task.isCancelled, pendingID == id else { return }
            running = nil
            // No client yet: the request stays until the session can ask.
            guard let target else { return }
            pendingID = nil
            openingID = nil
            switch target {
            case .thread(let threadID): openThread(threadID)
            case .webPage: if !openWebPage(id) { problem = .failed }
            case .needsPairing: break
            case .notFound, .offline, .failed: problem = target
            }
        }
    }
}
