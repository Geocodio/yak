import Foundation
import Observation
import YakKit

/// Which control of the Overview is waiting for the server.
enum OverviewRunningAction: Equatable {
    case add, stop, limit
    case override(String)
    case retry(LenientID)
    case share
}

/// The Overview of one open thread: reads the thread's snapshots and the Overview feed and hands the pane
/// finished values. Hidden, it asks the server for nothing.
@MainActor @Observable
final class OverviewModel {
    static let skeletonDelay: Duration = .milliseconds(300)
    static let actionLineLifetime: Duration = .seconds(6)
    static let copiedLifetime: Duration = .seconds(2)
    static let enginePollInterval: Duration = .milliseconds(250)

    let threadID: String
    private(set) var state: OverviewState?
    /// A refused or failed action, in place; it leaves after six seconds or with the next action.
    private(set) var actionLine: String?
    private(set) var runningAction: OverviewRunningAction?
    /// True once a group that is still waiting may show skeleton rows.
    private(set) var showsSkeleton = false
    /// What was copied last, so its row shows "Copied" for two seconds.
    private(set) var copiedKey: String?
    private(set) var couldNotOpen = false

    private let session: InstanceSession
    private let thread: ThreadModel
    private let clock: any YakClock
    private var viewerName: String?
    private var feed: OverviewFeed?
    private var actions: OverviewActions?
    private var isStarted = false
    private var isVisible = false
    private var readingTask: Task<Void, Never>?
    private var skeletonTask: Task<Void, Never>?
    private var lineTask: Task<Void, Never>?
    private var copiedTask: Task<Void, Never>?

    init(threadID: String, session: InstanceSession, thread: ThreadModel, clock: any YakClock) {
        self.threadID = threadID
        self.session = session
        self.thread = thread
        self.clock = clock
    }

    /// The thread's own snapshot and the feed's values as the Overview's groups.
    var content: OverviewContent? {
        let snapshot = thread.snapshot
        return OverviewContent.make(
            OverviewInputs(
                show: snapshot?.show, liveThread: snapshot?.thread, liveTurn: snapshot?.turn,
                stack: state?.stack.value, environment: state?.environment.value, viewerName: viewerName,
                previewHost: nil))
    }

    var isOffline: Bool { state?.isOffline ?? false }

    /// A problem the feed has while it still shows values: the line at the top.
    var hasStaleValues: Bool {
        guard let state else { return false }
        let stackStale = state.stack.value != nil && state.stack.problem != nil
        let environmentStale = state.environment.value != nil && state.environment.problem != nil
        return stackStale || environmentStale
    }

    /// Opens the feed once the thread's engine is open and reads its states until `stop()`. A second call changes
    /// nothing.
    func start() async {
        guard !isStarted else { return }
        isStarted = true
        skeletonTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.skeletonDelay) } catch { return }
            self?.showsSkeleton = true
        }
        viewerName = await session.me()?.user.name
        while thread.engine == nil, isStarted {
            do { try await clock.sleep(for: Self.enginePollInterval) } catch { return }
        }
        guard isStarted else { return }
        guard let opened = await session.openOverview(threadID) else {
            couldNotOpen = true
            return
        }
        guard isStarted else {
            await session.closeOverview(threadID)
            return
        }
        feed = opened
        actions = await session.overviewActions()
        readingTask = Task { [weak self] in
            for await next in await opened.states() {
                guard let self, !Task.isCancelled else { return }
                self.state = next
            }
        }
        await opened.setVisible(isVisible)
    }

    func stop() async {
        guard isStarted else { return }
        isStarted = false
        for task in [readingTask, skeletonTask, lineTask, copiedTask] { task?.cancel() }
        readingTask = nil
        skeletonTask = nil
        lineTask = nil
        copiedTask = nil
        if feed != nil {
            feed = nil
            actions = nil
            await session.closeOverview(threadID)
        }
    }

    func setVisible(_ visible: Bool) async {
        isVisible = visible
        await feed?.setVisible(visible)
    }

    /// Asks the server again for what failed or is old.
    func retry() async {
        couldNotOpen = false
        await feed?.refresh()
    }

    func retryRepositorySetup(_ id: LenientID) async {
        guard let actions, runningAction == nil else { return }
        runningAction = .retry(id)
        setActionLine(nil)
        let outcome = await actions.retryRepositorySetup(threadID, repository: id)
        await feed?.refresh([.environment])
        runningAction = nil
        let stillFailed =
            state?.environment.value?.repositories.contains {
                $0.id == id && $0.setupStatus == .failed
            } ?? false
        switch outcome {
        case .done, .alreadySent:
            if stillFailed { setActionLine(String(localized: "overview.retry.notStarted")) }
        case .refused(let code, let message, _):
            // The server's "nothing to retry" would contradict a row that reads "setup failed".
            if code == "nothing_to_retry" {
                if stillFailed { setActionLine(String(localized: "overview.retry.notStarted")) }
            } else {
                setActionLine(message)
            }
        case .failed(let problem):
            setActionLine(Self.sentence(for: problem))
        }
    }

    func noteCopied(_ key: String) {
        copiedKey = key
        copiedTask?.cancel()
        copiedTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.copiedLifetime) } catch { return }
            self?.copiedKey = nil
        }
    }

    private func setActionLine(_ text: String?) {
        actionLine = text
        lineTask?.cancel()
        guard text != nil else { return }
        lineTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.actionLineLifetime) } catch { return }
            self?.actionLine = nil
        }
    }

    /// The sentence for a failure that is not a refusal.
    static func sentence(for problem: ThreadProblem) -> String {
        switch problem {
        case .offline: String(localized: "overview.action.offline")
        case .server(_, let message): message
        default: String(localized: "overview.action.failed")
        }
    }
}
