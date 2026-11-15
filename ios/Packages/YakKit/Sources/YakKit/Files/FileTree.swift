import Foundation

public struct FileTimings: Equatable, Sendable {
    public var wakePoll: Duration
    public var wakeCap: Duration
    /// The cap while the answers say `restoring`: the restore job itself allows 900 seconds.
    public var restoreCap: Duration
    public var slowAfter: Duration

    public init(
        wakePoll: Duration = .seconds(2), wakeCap: Duration = .seconds(120),
        restoreCap: Duration = .seconds(900), slowAfter: Duration = .seconds(8)
    ) {
        self.wakePoll = wakePoll
        self.wakeCap = wakeCap
        self.restoreCap = restoreCap
        self.slowAfter = slowAfter
    }

    public static let standard = FileTimings()

    /// The app's Debug test mode: the same rules with a 6-second wake cap and a 12-second restore cap.
    public static let shortWaits = FileTimings(wakeCap: .seconds(6), restoreCap: .seconds(12))
}

public enum WorkspaceAvailability: Equatable, Sendable {
    case waking(waitingForSlot: Bool, restoring: Bool, since: Date)
    /// The wake cap passed.
    case stillWaking
    /// The restore cap passed.
    case stillRestoring
    case asleep(message: String)
    case removed(canRestore: Bool, message: String)
    case closedRemoved(message: String)
    case onComputer(message: String)
    /// An unknown `details.state`.
    case other(message: String)
}

public enum FolderState: Equatable, Sendable {
    case notLoaded, loading
    case loaded(truncated: Bool)
    case failed(ThreadProblem)
}

public enum FileMark: Equatable, Sendable { case modified, added, containsChanges }

public struct FileTreeRow: Equatable, Sendable, Identifiable {
    /// The path.
    public let id: String
    public let name: String
    public let depth: Int
    public let isDirectory: Bool
    public let isExpanded: Bool
    /// Folders only.
    public let folderState: FolderState?
    /// An expanded folder whose listing has no entries.
    public let isEmptyFolder: Bool
    public let mark: FileMark?

    public init(
        id: String, name: String, depth: Int, isDirectory: Bool, isExpanded: Bool, folderState: FolderState?,
        isEmptyFolder: Bool, mark: FileMark?
    ) {
        self.id = id
        self.name = name
        self.depth = depth
        self.isDirectory = isDirectory
        self.isExpanded = isExpanded
        self.folderState = folderState
        self.isEmptyFolder = isEmptyFolder
        self.mark = mark
    }
}

public struct FileTreeSnapshot: Equatable, Sendable {
    public var root: FolderState
    /// Nil when the sandbox serves files.
    public var availability: WorkspaceAvailability?
    /// The visible rows, depth first, in the server's order.
    public var rows: [FileTreeRow]
    public var isSlow: Bool
    /// The last request failed for the network; the rows stay.
    public var isOffline: Bool
    public var changedCount: Int
    public var changedPaths: [String], addedPaths: [String]
    /// The state of the find field's search.
    public var find: FindState

    public init(
        root: FolderState = .notLoaded, availability: WorkspaceAvailability? = nil, rows: [FileTreeRow] = [],
        isSlow: Bool = false, isOffline: Bool = false, changedCount: Int = 0, changedPaths: [String] = [],
        addedPaths: [String] = [], find: FindState = .idle
    ) {
        self.root = root
        self.availability = availability
        self.rows = rows
        self.isSlow = isSlow
        self.isOffline = isOffline
        self.changedCount = changedCount
        self.changedPaths = changedPaths
        self.addedPaths = addedPaths
        self.find = find
    }
}

extension Duration {
    fileprivate var seconds: TimeInterval {
        let parts = components
        return TimeInterval(parts.seconds) + TimeInterval(parts.attoseconds) / 1e18
    }
}

public actor FileTree {
    private struct Folder {
        var state: FolderState = .notLoaded
        var entries: [FileTreeEntry] = []
    }

    static let unavailableSentence = "The sandbox did not answer. Try again in a moment."

    private let threadID: String
    private let scope: FileScope
    private let client: APIClient
    private let clock: any YakClock
    private let timings: FileTimings
    private let broadcast = Broadcast(FileTreeSnapshot())

    private var folders: [String: Folder] = ["": Folder()]
    private var expanded: Set<String> = []
    private var changedPaths: [String] = []
    private var addedPaths: [String] = []
    private var availability: WorkspaceAvailability?
    private var isOffline = false
    /// Folders whose last load failed while they kept their rows; they are asked again by `retry()`.
    private var staleFolders: Set<String> = []
    private var hasStarted = false
    private var isStopped = false
    private var inFlight: [String: (id: Int, task: Task<Void, Never>)] = [:]
    private var nextLoadID = 0
    private var slowTimers: [Int: Task<Void, Never>] = [:]
    private var slowRequests: Set<Int> = []
    private var nextRequestID = 0
    private var find: FindState = .idle
    private var searchTask: Task<Void, Never>?
    private var searchGeneration = 0

    public init(
        threadID: String, scope: FileScope, client: APIClient, clock: any YakClock,
        timings: FileTimings = .standard
    ) {
        self.threadID = threadID
        self.scope = scope
        self.client = client
        self.clock = clock
        self.timings = timings
    }

    public func snapshots() -> AsyncStream<FileTreeSnapshot> { broadcast.stream() }

    /// Every entry of every loaded folder, for `FileFind.local`.
    public func loadedEntries() -> [FileTreeEntry] {
        folders.values.flatMap(\.entries)
    }

    /// Loads the root once; a repeated call changes nothing.
    public func start() async {
        guard !hasStarted, !isStopped else { return }
        hasStarted = true
        await load("", quiet: false)
    }

    /// Expands a folder (loading it once) or collapses it (keeping its children).
    public func toggle(_ path: String) async {
        guard !isStopped, isDirectory(path) else { return }
        if expanded.remove(path) != nil {
            publish()
            return
        }
        expanded.insert(path)
        publish()
        switch folders[path]?.state ?? .notLoaded {
        case .notLoaded, .failed, .loading: await load(path, quiet: false)
        case .loaded: break
        }
    }

    /// The root when it is not loaded or the sandbox does not serve it, then every failed folder.
    public func retry() async {
        guard !isStopped else { return }
        if case .loaded = folders[""]?.state ?? .notLoaded, availability == nil, !staleFolders.contains("") {
        } else {
            await load("", quiet: true)
        }
        for path in staleFolders.sorted() where path != "" { await load(path, quiet: true) }
        for path in failedFolders() { await load(path, quiet: false) }
    }

    /// After a turn ends or a restore started: reloads the root and every expanded folder, keeping expansion.
    /// A folder that is loaded but collapsed is forgotten, so opening it later asks again.
    public func refreshQuietly() async {
        guard !isStopped else { return }
        await load("", quiet: true)
        guard case .loaded = folders[""]?.state ?? .notLoaded, !staleFolders.contains("") else { return }
        for path in folders.keys where path != "" && !expanded.contains(path) {
            if case .loaded = folders[path]?.state ?? .notLoaded { folders[path] = Folder() }
        }
        publish()
        for path in expanded.sorted() where folders[path] != nil { await load(path, quiet: true) }
    }

    public func networkChanged(isOnline: Bool) async {
        guard isOnline, !isStopped, isOffline || !staleFolders.isEmpty || !failedFolders().isEmpty else { return }
        await retry()
    }

    public func stop() async {
        guard !isStopped else { return }
        isStopped = true
        for (_, entry) in inFlight { entry.task.cancel() }
        for (_, timer) in slowTimers { timer.cancel() }
        searchTask?.cancel()
        inFlight = [:]
        slowTimers = [:]
        slowRequests = []
        publish()
        broadcast.finish()
    }

    // MARK: Search

    /// Waits 300 ms after the last call, then asks the server; the snapshot's `find` carries the state. A
    /// query of one character or less is idle, one over 200 characters is cut. A failed search falls back to
    /// the loaded folders and says the result is partial.
    public func search(_ query: String) async {
        guard !isStopped else { return }
        searchTask?.cancel()
        searchGeneration += 1
        let generation = searchGeneration
        let trimmed = String(query.trimmingCharacters(in: .whitespacesAndNewlines).prefix(Self.searchLengthLimit))
        guard trimmed.count > 1 else {
            searchTask = nil
            find = .idle
            publish()
            return
        }
        find = .searching
        publish()
        let task = Task { await self.performSearch(trimmed, generation: generation) }
        searchTask = task
        await task.value
    }

    private static let searchLengthLimit = 200

    private func performSearch(_ query: String, generation: Int) async {
        do { try await clock.sleep(for: .milliseconds(300)) } catch { return }
        guard generation == searchGeneration, !isStopped else { return }
        let outcome: FindState
        do {
            let answer = try await client.send(.searchFiles(threadID, query: query))
            let marked = Set(addedPaths)
            let changed = Set(changedPaths)
            let results = answer.matches.map { match in
                FileFindResult(
                    name: match.name, path: match.path,
                    mark: marked.contains(match.path) ? .added : (changed.contains(match.path) ? .modified : nil))
            }
            outcome =
                results.isEmpty
                ? .noMatch(query: query, isPartial: false)
                : .results(results, truncated: answer.truncated, isPartial: false)
        } catch {
            if case .network(.cancelled) = error { return }
            outcome = Self.partial(FileFind.local(query, in: currentSnapshot(), loaded: loadedEntries()))
        }
        guard generation == searchGeneration, !isStopped, !Task.isCancelled else { return }
        find = outcome
        publish()
    }

    private static func partial(_ state: FindState) -> FindState {
        switch state {
        case .results(let results, let truncated, _): .results(results, truncated: truncated, isPartial: true)
        case .noMatch(let query, _): .noMatch(query: query, isPartial: true)
        default: state
        }
    }

    // MARK: Loading

    private func load(_ path: String, quiet: Bool) async {
        if let existing = inFlight[path] {
            await existing.task.value
            return
        }
        nextLoadID += 1
        let id = nextLoadID
        let task = Task { await self.performLoad(path, quiet: quiet, id: id) }
        inFlight[path] = (id, task)
        await task.value
    }

    private func performLoad(_ path: String, quiet: Bool, id: Int) async {
        defer { if inFlight[path]?.id == id { inFlight[path] = nil } }
        var folder = folders[path] ?? Folder()
        if case .loaded = folder.state, quiet {
        } else {
            folder.state = .loading
            folders[path] = folder
            publish()
        }
        var wakeStart: Date?
        var restoreStart: Date?
        while true {
            let requestID = beginRequest()
            let failure: APIFailure
            do {
                let answer = try await client.send(.fileTree(threadID, scope: scope, path: path))
                endRequest(requestID)
                guard !isStopped, !Task.isCancelled else { return }
                apply(answer, to: path)
                return
            } catch {
                failure = error
                endRequest(requestID)
            }
            guard !isStopped, !Task.isCancelled else { return }
            if case .network(.cancelled) = failure { return }

            if case .server(let error) = failure, let unavailable = SandboxUnavailable(error) {
                let now = clock.now()
                guard unavailable.state == .waking else {
                    availability = Self.availability(for: unavailable)
                    fail(path, .server(code: "sandbox_unavailable", message: unavailable.message))
                    return
                }
                let since: Date
                let cap: Duration
                if unavailable.restoring {
                    wakeStart = nil
                    since = restoreStart ?? now
                    restoreStart = since
                    cap = timings.restoreCap
                } else {
                    restoreStart = nil
                    since = wakeStart ?? now
                    wakeStart = since
                    cap = timings.wakeCap
                }
                if now.timeIntervalSince(since) >= cap.seconds {
                    availability = unavailable.restoring ? .stillRestoring : .stillWaking
                    fail(path, .server(code: "sandbox_unavailable", message: unavailable.message))
                    return
                }
                availability = .waking(
                    waitingForSlot: unavailable.waitingForSlot, restoring: unavailable.restoring, since: since)
                publish()
                do { try await clock.sleep(for: timings.wakePoll) } catch { return }
                guard !isStopped, !Task.isCancelled else { return }
                continue
            }
            handle(failure, path: path)
            return
        }
    }

    private func apply(_ answer: FileTreeFolder, to path: String) {
        folders[path] = Folder(state: .loaded(truncated: answer.truncated), entries: answer.entries)
        staleFolders.remove(path)
        changedPaths = answer.changedPaths
        addedPaths = answer.addedPaths
        availability = nil
        isOffline = false
        publish()
    }

    private func handle(_ failure: APIFailure, path: String) {
        switch failure {
        case .network(.timedOut):
            fail(path, .server(code: "unavailable", message: Self.unavailableSentence))
        case .network:
            isOffline = true
            fail(path, .offline)
        case .server(let error) where error.status == 503:
            fail(path, .server(code: error.code, message: error.message))
        case .server(let error) where error.status == 422:
            let detail = error.details["errors"]?["path"]
            var message = error.message
            if case .array(let messages)? = detail, let first = messages.first?.stringValue { message = first }
            if case .string(let text)? = detail { message = text }
            fail(path, .server(code: "validation_failed", message: message))
        case .server(let error) where error.status == 404:
            folders[path] = Folder(state: .failed(.notFound))
            publish()
        default:
            fail(path, ThreadProblem(failure))
        }
    }

    /// A folder that already shows entries keeps them and its loaded state.
    private func fail(_ path: String, _ problem: ThreadProblem) {
        var folder = folders[path] ?? Folder()
        if case .loaded = folder.state {
            staleFolders.insert(path)
        } else {
            folder.state = .failed(problem)
            folders[path] = folder
        }
        publish()
    }

    static func availability(for unavailable: SandboxUnavailable) -> WorkspaceAvailability {
        switch unavailable.state {
        case .asleep: .asleep(message: unavailable.message)
        case .destroyed: .removed(canRestore: unavailable.canRestore, message: unavailable.message)
        case .closedDestroyed: .closedRemoved(message: unavailable.message)
        case .onDevice: .onComputer(message: unavailable.message)
        default: .other(message: unavailable.message)
        }
    }

    private func failedFolders() -> [String] {
        folders.compactMap { path, folder in
            if path != "", case .failed = folder.state { return path }
            return nil
        }.sorted()
    }

    private func isDirectory(_ path: String) -> Bool {
        folders.values.contains { $0.entries.contains { $0.path == path && $0.isDirectory } }
    }

    // MARK: Slow requests

    private func beginRequest() -> Int {
        nextRequestID += 1
        let id = nextRequestID
        let threshold = timings.slowAfter
        slowTimers[id] = Task {
            do { try await clock.sleep(for: threshold) } catch { return }
            self.markSlow(id)
        }
        return id
    }

    private func markSlow(_ id: Int) {
        guard slowTimers[id] != nil, !isStopped else { return }
        slowRequests.insert(id)
        publish()
    }

    private func endRequest(_ id: Int) {
        slowTimers.removeValue(forKey: id)?.cancel()
        slowRequests.remove(id)
    }

    // MARK: Snapshot

    private func publish() { broadcast.send(currentSnapshot()) }

    private func currentSnapshot() -> FileTreeSnapshot {
        let marked = Set(changedPaths).union(addedPaths)
        return FileTreeSnapshot(
            root: folders[""]?.state ?? .notLoaded, availability: availability, rows: rows(marked: marked),
            isSlow: !slowRequests.isEmpty, isOffline: isOffline, changedCount: marked.count,
            changedPaths: changedPaths, addedPaths: addedPaths, find: find)
    }

    private func rows(marked: Set<String>) -> [FileTreeRow] {
        var result: [FileTreeRow] = []
        // A folder being listed is never listed again below itself, so a listing that names an ancestor
        // (or the root, path "") as a child cannot recurse without end.
        var visiting: Set<String> = [""]
        func visit(_ path: String, depth: Int) {
            guard let folder = folders[path] else { return }
            visiting.insert(path)
            defer { visiting.remove(path) }
            for entry in folder.entries where !(entry.isDirectory && visiting.contains(entry.path)) {
                let isExpanded = entry.isDirectory && expanded.contains(entry.path)
                let state = entry.isDirectory ? (folders[entry.path]?.state ?? .notLoaded) : nil
                var isEmpty = false
                if isExpanded, case .loaded = state { isEmpty = folders[entry.path]?.entries.isEmpty ?? false }
                result.append(
                    FileTreeRow(
                        id: entry.path, name: entry.name, depth: depth, isDirectory: entry.isDirectory,
                        isExpanded: isExpanded, folderState: state, isEmptyFolder: isEmpty,
                        mark: mark(entry, marked: marked)))
                if isExpanded { visit(entry.path, depth: depth + 1) }
            }
        }
        visit("", depth: 0)
        return result
    }

    private func mark(_ entry: FileTreeEntry, marked: Set<String>) -> FileMark? {
        if entry.isDirectory {
            let prefix = entry.path + "/"
            return marked.contains { $0.hasPrefix(prefix) } ? .containsChanges : nil
        }
        if addedPaths.contains(entry.path) { return .added }
        return changedPaths.contains(entry.path) ? .modified : nil
    }
}
