import Foundation

/// Everything the Files pane draws in Changed mode, as one value.
public struct ChangesSnapshot: Equatable, Sendable {
    /// Of the diff list.
    public var load: ThreadLoadState
    /// Nil with `load` `.ready` means "no changes yet".
    public var diff: ThreadDiff?
    public var pager: DiffPager
    public var marks: ViewedMarks
    /// Only the current file and its neighbors.
    public var pages: [DiffFileKey: FilePageState]
    /// `.movedBecauseGone` shows a one-time line.
    public var lastMove: DiffPager.Move
    public var isShowingCachedCopy: Bool
    public var isOffline: Bool
    /// A reload failed while a list stays on screen.
    public var problem: ThreadProblem?

    public init(
        load: ThreadLoadState = .loading, diff: ThreadDiff? = nil,
        pager: DiffPager = DiffPager(files: [], restoring: nil), marks: ViewedMarks = ViewedMarks(),
        pages: [DiffFileKey: FilePageState] = [:], lastMove: DiffPager.Move = .kept,
        isShowingCachedCopy: Bool = false, isOffline: Bool = false, problem: ThreadProblem? = nil
    ) {
        self.load = load
        self.diff = diff
        self.pager = pager
        self.marks = marks
        self.pages = pages
        self.lastMove = lastMove
        self.isShowingCachedCopy = isShowingCachedCopy
        self.isOffline = isOffline
        self.problem = problem
    }
}

/// The diff list, one file's page at a time, the viewed marks and the reload rules of one thread. Patches stay in
/// memory only.
public actor ThreadChanges {
    public static let keptPatches = 12

    private let threadID: String
    private let client: APIClient
    private let cache: Cache
    private let clock: any YakClock
    private let broadcast = Broadcast(ChangesSnapshot())

    private var snapshot = ChangesSnapshot()
    /// The state of every file seen, ready patches included.
    private var states: [DiffFileKey: FilePageState] = [:]
    /// Keys whose page was loaded for the summary now in the list.
    private var freshKeys: Set<DiffFileKey> = []
    /// Ready patches, least recently shown first.
    private var patchOrder: [DiffFileKey] = []
    /// The file id that answered 404 for a key; a second 404 for the same id is final.
    private var notFoundIDs: [DiffFileKey: Int] = [:]
    private var pageTasks: [DiffFileKey: Task<Void, Never>] = [:]
    private var slowTasks: [DiffFileKey: Task<Void, Never>] = [:]
    private var reloadTask: Task<Void, Never>?
    private var isRerunRequested = false
    private var settleTask: Task<Void, Never>?
    private var diffSlowTask: Task<Void, Never>?
    private var lastSeenDiffSequence: Int?
    private var hasLoadedDiff = false
    private var hasOpened = false
    /// The file to return to when the first list arrives without a cached copy.
    private var pendingRestore: DiffFileKey?
    private var isClosed = false

    public init(threadID: String, client: APIClient, cache: Cache, clock: any YakClock) {
        self.threadID = threadID
        self.client = client
        self.cache = cache
        self.clock = clock
    }

    // MARK: Lifecycle

    public func snapshots() -> AsyncStream<ChangesSnapshot> {
        broadcast.stream()
    }

    /// Shows the cached list and marks at once, then asks for the diff. Repeating it changes nothing.
    public func open(restoring key: DiffFileKey?) async {
        guard !hasOpened, !isClosed else { return }
        hasOpened = true
        snapshot.marks = await cache.viewedMarks(thread: threadID)
        if let cached = await cache.diff(thread: threadID) {
            snapshot.diff = cached
            snapshot.pager = DiffPager(files: cached.files, restoring: key)
            snapshot.load = .ready
            snapshot.isShowingCachedCopy = true
        } else {
            pendingRestore = key
            startDiffSlowTimer()
        }
        guard !isClosed else { return }
        publish()
        await reload()
    }

    /// Cancels every task, saves the marks and ends the streams. Repeating it changes nothing.
    public func close() async {
        guard !isClosed else { return }
        isClosed = true
        for task in pageTasks.values { task.cancel() }
        for task in slowTasks.values { task.cancel() }
        for task in [reloadTask, settleTask, diffSlowTask] { task?.cancel() }
        pageTasks = [:]
        slowTasks = [:]
        reloadTask = nil
        settleTask = nil
        diffSlowTask = nil
        await cache.saveViewedMarks(snapshot.marks, thread: threadID)
        broadcast.finish()
    }

    // MARK: Input

    /// Pull to refresh and Retry. A call while one runs joins it and asks for one more run.
    public func reload() async {
        guard !isClosed else { return }
        if let running = reloadTask {
            isRerunRequested = true
            await running.value
            return
        }
        let task = Task { await self.runReloads() }
        reloadTask = task
        await task.value
    }

    public func show(_ key: DiffFileKey) async {
        snapshot.pager.show(key)
        await pagerMoved()
    }

    public func next() async {
        snapshot.pager.next()
        await pagerMoved()
    }

    public func previous() async {
        snapshot.pager.previous()
        await pagerMoved()
    }

    /// The current file.
    public func setViewed(_ viewed: Bool) async {
        guard let file = currentFile() else { return }
        snapshot.marks.set(file, viewed: viewed)
        await cache.saveViewedMarks(snapshot.marks, thread: threadID)
        publish()
    }

    /// From the thread's snapshot: reloads when `lastDiffUpdateSequence` moved, and when a turn ends while the diff
    /// says `isTurnRunning`. A sequence seen before the first answer is already part of that answer.
    public func threadChanged(_ conversation: ConversationSnapshot) async {
        guard !isClosed else { return }
        var needsReload = false
        if let sequence = conversation.lastDiffUpdateSequence, sequence > (lastSeenDiffSequence ?? 0) {
            lastSeenDiffSequence = sequence
            needsReload = hasLoadedDiff
        }
        if snapshot.diff?.isTurnRunning == true, conversation.turn?.state.isActive == false {
            needsReload = true
        }
        if needsReload { scheduleSettledReload() }
    }

    // MARK: Diff list

    private func runReloads() async {
        repeat {
            isRerunRequested = false
            await fetchDiff()
            guard !isClosed else { break }
            await loadPages()
        } while isRerunRequested && !isClosed
        reloadTask = nil
    }

    private func scheduleSettledReload() {
        guard settleTask == nil else { return }
        settleTask = Task {
            do { try await clock.sleep(for: ThreadEngine.refreshSettle) } catch { return }
            self.settleTask = nil
            await self.reload()
        }
    }

    private func startDiffSlowTimer() {
        diffSlowTask = Task {
            do { try await clock.sleep(for: ThreadEngine.slowAfter) } catch { return }
            if self.snapshot.load == .loading {
                self.snapshot.load = .slow
                self.publish()
            }
        }
    }

    private func fetchDiff() async {
        let response: ThreadDiffResponse
        do {
            response = try await client.send(.threadDiff(threadID))
        } catch {
            guard !isClosed, !Task.isCancelled, error != .network(.cancelled) else { return }
            diffSlowTask?.cancel()
            let problem = ThreadProblem(error)
            if snapshot.diff == nil, snapshot.load != .ready {
                snapshot.load = .failed(problem)
            } else if problem == .offline {
                snapshot.isOffline = true
            } else {
                snapshot.problem = problem
            }
            publish()
            return
        }
        guard !isClosed else { return }
        diffSlowTask?.cancel()
        diffSlowTask = nil
        hasLoadedDiff = true
        await apply(response.diff)
    }

    private func apply(_ diff: ThreadDiff?) async {
        let previousFiles = snapshot.diff?.files ?? []
        snapshot.diff = diff
        snapshot.load = .ready
        snapshot.isShowingCachedCopy = false
        snapshot.isOffline = false
        snapshot.problem = nil
        if let diff {
            if let key = pendingRestore {
                pendingRestore = nil
                snapshot.pager = DiffPager(files: diff.files, restoring: key)
                snapshot.lastMove = .kept
            } else {
                snapshot.lastMove = snapshot.pager.replace(files: diff.files)
            }
            snapshot.marks.prune(keeping: diff.files)
            let unchanged = Dictionary(
                uniqueKeysWithValues: previousFiles.map { ($0.key, $0) })
            for file in diff.files where unchanged[file.key] != file { freshKeys.remove(file.key) }
            let keys = Set(diff.files.map(\.key))
            for key in states.keys where !keys.contains(key) { forget(key) }
        } else {
            snapshot.pager = DiffPager(files: [], restoring: nil)
            snapshot.lastMove = previousFiles.isEmpty ? .kept : .emptied
            for key in Array(states.keys) { forget(key) }
        }
        await cache.saveDiff(diff, thread: threadID)
        await cache.saveViewedMarks(snapshot.marks, thread: threadID)
        publish()
    }

    private func forget(_ key: DiffFileKey) {
        states[key] = nil
        freshKeys.remove(key)
        patchOrder.removeAll { $0 == key }
        notFoundIDs[key] = nil
        slowTasks[key]?.cancel()
        slowTasks[key] = nil
    }

    // MARK: File pages

    private func pagerMoved() async {
        publish()
        await loadPages()
    }

    private func currentFile() -> DiffFileSummary? {
        guard let key = snapshot.pager.current else { return nil }
        return snapshot.pager.files.first { $0.key == key }
    }

    /// The current file first, then its neighbors, in parallel.
    private func loadPages() async {
        guard !isClosed, let current = currentFile() else { return }
        let targets = [current] + snapshot.pager.neighbors()
        for file in targets { touch(file.key) }
        let tasks = targets.compactMap { startLoad($0) }
        for task in tasks { await task.value }
    }

    private func touch(_ key: DiffFileKey) {
        guard let index = patchOrder.firstIndex(of: key) else { return }
        patchOrder.append(patchOrder.remove(at: index))
    }

    private func startLoad(_ file: DiffFileSummary) -> Task<Void, Never>? {
        if let running = pageTasks[file.key] { return running }
        if freshKeys.contains(file.key), states[file.key] != nil { return nil }
        let task = Task { await self.loadPage(file) }
        pageTasks[file.key] = task
        return task
    }

    private func loadPage(_ file: DiffFileSummary) async {
        defer { pageTasks[file.key] = nil }
        if file.isBinary {
            settle(file, .binary)
        } else if file.isTooLarge {
            settle(file, .tooLarge)
        } else {
            await fetchPage(file)
        }
    }

    private func settle(_ file: DiffFileSummary, _ state: FilePageState) {
        guard !isClosed else { return }
        slowTasks[file.key]?.cancel()
        slowTasks[file.key] = nil
        states[file.key] = state
        freshKeys.insert(file.key)
        if case .ready = state {
            patchOrder.removeAll { $0 == file.key }
            patchOrder.append(file.key)
            while patchOrder.count > Self.keptPatches {
                let dropped = patchOrder.removeFirst()
                states[dropped] = nil
                freshKeys.remove(dropped)
            }
        }
        publish()
    }

    private func fetchPage(_ file: DiffFileSummary) async {
        if case .ready? = states[file.key] {
            // The old patch stays on screen while the new one loads.
        } else {
            states[file.key] = .loading
            startFileSlowTimer(file.key)
            publish()
        }
        let content: DiffFileContent
        do {
            content = try await client.send(.diffFile(threadID, fileID: file.id))
        } catch {
            guard !isClosed, !Task.isCancelled, error != .network(.cancelled) else { return }
            await handlePageFailure(file, error)
            return
        }
        guard !isClosed else { return }
        if content.isBinary {
            settle(file, .binary)
        } else if content.isTooLarge {
            settle(file, .tooLarge)
        } else if let patch = content.patch {
            let parsed = await Task.detached { UnifiedDiff.parse(patch) }.value
            settle(file, parsed.hasContentChanges ? .ready(parsed) : .noContentChanges)
        } else {
            settle(file, .noContentChanges)
        }
    }

    private func startFileSlowTimer(_ key: DiffFileKey) {
        slowTasks[key]?.cancel()
        slowTasks[key] = Task {
            do { try await clock.sleep(for: ThreadEngine.slowAfter) } catch { return }
            if self.states[key] == .loading {
                self.states[key] = .slow
                self.publish()
            }
        }
    }

    private func handlePageFailure(_ file: DiffFileSummary, _ failure: APIFailure) async {
        let problem = ThreadProblem(failure)
        if problem == .notFound {
            await handleMissingFile(file)
            return
        }
        slowTasks[file.key]?.cancel()
        slowTasks[file.key] = nil
        if case .ready? = states[file.key] {
            // The older patch stays; the list says the refresh failed.
            snapshot.problem = problem == .offline ? nil : problem
        } else {
            states[file.key] = problem == .offline ? .notSaved : .failed(problem)
        }
        if problem == .offline { snapshot.isOffline = true }
        publish()
    }

    /// One diff reload, then the file is found again by repository and path. A second 404 for the same id is final.
    private func handleMissingFile(_ file: DiffFileSummary) async {
        if notFoundIDs[file.key] == file.id {
            slowTasks[file.key]?.cancel()
            slowTasks[file.key] = nil
            states[file.key] = .gone
            freshKeys.insert(file.key)
            publish()
            return
        }
        notFoundIDs[file.key] = file.id
        await fetchDiff()
        guard !isClosed else { return }
        if let found = snapshot.pager.files.first(where: { $0.key == file.key }) {
            await fetchPage(found)
        } else {
            await loadPages()
        }
    }

    // MARK: Output

    private func publish() {
        guard !isClosed else { return }
        var pages: [DiffFileKey: FilePageState] = [:]
        if let current = snapshot.pager.current {
            for key in [current] + snapshot.pager.neighbors().map(\.key) {
                pages[key] = states[key]
            }
        }
        snapshot.pages = pages
        broadcast.send(snapshot)
    }

    /// Tests read these.
    var keptPatchCount: Int { patchOrder.count }
    var requestedStates: [DiffFileKey: FilePageState] { states }
}
