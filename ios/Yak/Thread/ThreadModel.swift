import Foundation
import Observation
import YakKit

/// One open thread on screen: reads the engine's snapshots and hands the screen finished values.
@MainActor @Observable
final class ThreadModel {
    let threadID: String
    private(set) var snapshot: ConversationSnapshot?
    private(set) var engine: ThreadEngine?
    /// Lives as long as the model, so typed text survives a retry and a slow load.
    let composer: ComposerModel
    /// The session had no engine to give, so there is nothing to wait for.
    private(set) var couldNotOpen = false

    let session: InstanceSession
    /// The drawer row's title, shown until the thread's own arrives.
    private var initialTitle: String?
    private var isStarted = false
    private var isVisible = false
    private var readingTask: Task<Void, Never>?
    /// Counts opens and closes, so an open that finishes after a stop closes its own engine again.
    private var generation = 0

    init(threadID: String, session: InstanceSession) {
        self.threadID = threadID
        self.session = session
        composer = ComposerModel(
            key: .thread(threadID),
            backend: LiveComposerBackend(session: session, engine: nil, threadID: threadID), clock: SystemClock())
    }

    var title: String { snapshot?.show?.thread.title ?? initialTitle ?? "" }
    var panes: [PaneID] { Panes.available(server: snapshot?.show?.panes) }
    var connectionLine: ConnectionLine? { snapshot.flatMap(ConnectionLine.make) }
    var statusDot: ThreadStatusDot { ThreadStatusDot.make(snapshot) }
    var loadPresentation: ThreadLoadPresentation {
        if couldNotOpen, snapshot == nil { return .serverError(message: String(localized: "load.openFailed")) }
        return ThreadLoadPresentation.make(snapshot?.load ?? .loading)
    }
    var hasOpenQuestion: Bool { snapshot?.hasOpenQuestion ?? false }

    /// Opens the engine and reads its snapshots until `stop()`. A second call changes nothing.
    func start(initialTitle: String?) async {
        if let initialTitle, snapshot?.show == nil { self.initialTitle = initialTitle }
        guard !isStarted else { return }
        isStarted = true
        await composer.load()
        await open()
    }

    func stop() async {
        guard isStarted else { return }
        isStarted = false
        await composer.flush()
        await close()
    }

    /// Saves the draft now; the screen calls it when the app goes to the background.
    func flushDraft() async { await composer.flush() }

    func setVisible(_ visible: Bool) async {
        isVisible = visible
        await engine?.setVisible(visible)
    }

    /// Closes the engine and opens a new one, which asks the server again.
    func retry() async {
        guard isStarted else { return }
        let previous = engine
        await close()
        await open()
        // Another window may hold the same engine; closing and opening then asks the server for nothing, so it is asked.
        if let engine, engine === previous { await engine.requestCatchUp() }
    }

    private func open() async {
        generation += 1
        let mine = generation
        couldNotOpen = false
        guard let opened = await session.openThread(threadID) else {
            if generation == mine { couldNotOpen = true }
            return
        }
        guard generation == mine, isStarted else {
            await session.closeThread(threadID)
            return
        }
        engine = opened
        composer.use(backend: LiveComposerBackend(session: session, engine: opened, threadID: threadID))
        await opened.setVisible(isVisible)
        readingTask = Task { [weak self] in
            for await next in await opened.snapshots() {
                guard let self, !Task.isCancelled else { return }
                self.snapshot = next
                self.composer.update(snapshot: next)
            }
        }
    }

    private func close() async {
        generation += 1
        readingTask?.cancel()
        readingTask = nil
        guard engine != nil else { return }
        engine = nil
        await session.closeThread(threadID)
    }
}
