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
    static let awakeLimitDelay: Duration = .milliseconds(600)

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
    /// The stepper's value while a change waits to be sent or is on its way; nil when the server's value shows.
    private(set) var pendingAwakeLimit: Int?
    /// Counts refused or failed actions, for the error haptic.
    private(set) var refusalCount = 0
    /// Opens another thread of this instance; set by the pane.
    var onOpenThread: (@MainActor (String) -> Void)?

    private let session: InstanceSession
    private let thread: ThreadModel
    private let clock: any YakClock
    private var viewerName: String?
    private var feed: OverviewFeed?
    private var actions: OverviewActions?
    private var isStarted = false
    private var isVisible = false
    /// Tells the shared feed apart from the other views of the same thread, so one hidden view cannot hide it for all.
    private let feedCaller = UUID()
    private var readingTask: Task<Void, Never>?
    private var skeletonTask: Task<Void, Never>?
    private var lineTask: Task<Void, Never>?
    private var copiedTask: Task<Void, Never>?
    private var limitTask: Task<Void, Never>?

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
        await opened.setVisible(isVisible, caller: feedCaller)
    }

    func stop() async {
        guard isStarted else { return }
        isStarted = false
        for task in [readingTask, skeletonTask, lineTask, copiedTask, limitTask] { task?.cancel() }
        limitTask = nil
        pendingAwakeLimit = nil
        readingTask = nil
        skeletonTask = nil
        lineTask = nil
        copiedTask = nil
        if let feed {
            await feed.setVisible(false, caller: feedCaller)
            self.feed = nil
            actions = nil
            await session.closeOverview(threadID)
        }
    }

    func setVisible(_ visible: Bool) async {
        isVisible = visible
        await feed?.setVisible(visible, caller: feedCaller)
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

    // MARK: Sub-threads

    func open(_ childID: String) { onOpenThread?(childID) }

    func openParent() {
        if let parent = content?.subThreads?.parent { onOpenThread?(parent.id) }
    }

    func stopAll() async {
        guard let actions, runningAction == nil else { return }
        runningAction = .stop
        setActionLine(nil)
        let outcome = await actions.stopStack(threadID)
        await feed?.refresh([.stack])
        runningAction = nil
        report(outcome)
    }

    func perform(_ action: SubThreadAction, on childID: String) async {
        guard action != .open else {
            open(childID)
            return
        }
        guard let actions, runningAction == nil else { return }
        runningAction = .override(childID)
        setActionLine(nil)
        let outcome: ThreadActionOutcome
        switch action {
        case .takeOver: outcome = await actions.takeOver(childID)
        case .split: outcome = await actions.split(childID)
        default: outcome = await actions.drop(childID)
        }
        await feed?.refresh([.stack])
        runningAction = nil
        report(outcome)
    }

    /// A tap on the stepper shows at once and is sent 600 ms after the last one.
    func proposeAwakeLimit(_ value: Int) {
        guard let stepper = content?.subThreads?.awake else { return }
        let next = stepper.clamped(value)
        pendingAwakeLimit = next == stepper.value ? nil : next
        limitTask?.cancel()
        guard pendingAwakeLimit != nil else { return }
        limitTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.awakeLimitDelay) } catch { return }
            await self?.sendAwakeLimit()
        }
    }

    private func sendAwakeLimit() async {
        guard let actions, let value = pendingAwakeLimit else { return }
        runningAction = .limit
        setActionLine(nil)
        let answer = await actions.setAwakeLimit(threadID, limit: value)
        await feed?.refresh([.stack])
        runningAction = nil
        // A refusal puts the old value back; a success shows the server's value, which the reload carried.
        if pendingAwakeLimit == value { pendingAwakeLimit = nil }
        report(answer.outcome)
    }

    /// A refused or failed action says why in place and plays the error haptic.
    private func report(_ outcome: ThreadActionOutcome) {
        switch outcome {
        case .done, .alreadySent: break
        case .refused(_, let message, _):
            refusalCount += 1
            setActionLine(message)
        case .failed(let problem):
            refusalCount += 1
            setActionLine(
                problem == .offline ? String(localized: "overview.action.notSent") : Self.sentence(for: problem))
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
