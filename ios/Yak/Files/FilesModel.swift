import Foundation
import Observation
import YakKit

/// The Files pane in Changed mode for one thread: reads `ThreadChanges`, sends the person's moves to it and carries
/// the Open PR button's state. The rules (paging by key, reload rules, viewed marks, button state) are YakKit's.
@MainActor @Observable
final class FilesModel {
    /// A list older than this reloads when the pane is shown again.
    static let staleAfter: TimeInterval = 30
    /// How often the show is read while the server opens the pull request, and how many times.
    static let openingPollInterval: Duration = .seconds(2)
    static let openingPollRounds = 15

    let thread: ThreadModel
    private(set) var snapshot: ChangesSnapshot?
    private(set) var isSending = false
    var isConfirmingPrivatePublish = false
    /// The last refusal or failure of Open PR, shown above the bar.
    private(set) var openNotice: String?
    private(set) var loadedAt: Date?
    private(set) var successCount = 0
    private(set) var errorCount = 0
    /// The path whose diff the person asked for from the Everything mode's viewer; the Files pane routes it.
    var requestedDiffPath: String?

    @ObservationIgnored private var changes: ThreadChanges?
    @ObservationIgnored private var actions: PullRequestActions?
    @ObservationIgnored private var readingTask: Task<Void, Never>?
    @ObservationIgnored private var openingTask: Task<Void, Never>?
    @ObservationIgnored private var isStarted = false
    @ObservationIgnored private var restoring: DiffFileKey?
    /// The file each thread's pane was last on; the scene saves it as `positionsData` and gives it back through
    /// `restorePositions`.
    private var filesPosition: [String: DiffFileKey] = [:]

    init(thread: ThreadModel, restoring key: DiffFileKey? = nil) {
        self.thread = thread
        restoring = key
    }

    /// `filesPosition` encoded for the scene's `yak.files` storage; nil while no file has been shown.
    var positionsData: Data? { filesPosition.isEmpty ? nil : try? JSONEncoder().encode(filesPosition) }

    /// Takes the scene's saved positions. Called before `start`; the saved file of this thread is opened first.
    func restorePositions(_ data: Data?) {
        guard let data, let saved = try? JSONDecoder().decode([String: DiffFileKey].self, from: data) else { return }
        filesPosition = saved.merging(filesPosition) { _, current in current }
        if restoring == nil { restoring = saved[thread.threadID] }
    }

    var show: ThreadShow? { thread.snapshot?.show }
    var files: [DiffFileSummary] { snapshot?.pager.files ?? [] }
    var currentKey: DiffFileKey? { snapshot?.pager.current }
    var fileCountLabel: String { Self.countLabel(files.count) }
    var hasChanges: Bool { (show?.diffTotals?.filesCount ?? 0) > 0 }
    var openPullRequest: OpenPullRequestState { OpenPullRequestState.make(show: show, isSending: isSending) }

    static func countLabel(_ count: Int) -> String {
        count == 1 ? String(localized: "files.count.one") : String(localized: "files.count.other \(count)")
    }

    func start() async {
        guard !isStarted else { return }
        isStarted = true
        guard let client = await thread.session.apiClient(), let cache = await thread.session.instanceCache() else {
            snapshot = ChangesSnapshot(load: .failed(.offline))
            return
        }
        let opened = ThreadChanges(
            threadID: thread.threadID, client: client, cache: cache, clock: thread.session.clock)
        changes = opened
        actions = PullRequestActions(threadID: thread.threadID, client: client)
        let stream = await opened.snapshots()
        readingTask = Task { [weak self] in
            for await next in stream {
                guard let self, !Task.isCancelled else { return }
                self.snapshot = next
                if let key = next.pager.current, self.filesPosition[self.thread.threadID] != key {
                    self.filesPosition[self.thread.threadID] = key
                }
                if next.load == .ready, !next.isShowingCachedCopy, !next.isOffline { self.loadedAt = Date() }
            }
        }
        await opened.open(restoring: restoring, baseline: thread.snapshot)
    }

    func stop() async {
        guard isStarted else { return }
        isStarted = false
        readingTask?.cancel()
        readingTask = nil
        openingTask?.cancel()
        openingTask = nil
        let closing = changes
        changes = nil
        actions = nil
        await closing?.close()
    }

    /// Reloads when the pane comes back and the list is older than `staleAfter`.
    func setVisible(_ visible: Bool) async {
        guard visible, isStarted, let loadedAt, Date().timeIntervalSince(loadedAt) > Self.staleAfter else { return }
        await changes?.reload()
    }

    func threadChanged(_ conversation: ConversationSnapshot) async { await changes?.threadChanged(conversation) }
    func next() async { await changes?.next() }
    func previous() async { await changes?.previous() }
    func show(_ key: DiffFileKey) async { await changes?.show(key) }
    func retry() async { await changes?.reload() }

    func toggleViewed() async {
        guard let key = currentKey, let file = files.first(where: { $0.key == key }), let snapshot else { return }
        await changes?.setViewed(!snapshot.marks.isViewed(file))
    }

    // MARK: Open PR

    func tapOpenPullRequest() async {
        switch openPullRequest {
        case .available(let needsConfirmation):
            if needsConfirmation { isConfirmingPrivatePublish = true } else { await send(acknowledged: false) }
        case .failed:
            if show?.publishWarning == true {
                isConfirmingPrivatePublish = true
            } else {
                await send(acknowledged: false)
            }
        default:
            return
        }
    }

    func confirmPrivatePublish() async {
        isConfirmingPrivatePublish = false
        await send(acknowledged: true)
    }

    func cancelPrivatePublish() { isConfirmingPrivatePublish = false }

    private func send(acknowledged: Bool) async {
        guard !isSending, let actions else { return }
        isSending = true
        openNotice = nil
        let outcome = await actions.open(acknowledgedPrivatePublish: acknowledged)
        isSending = false
        switch outcome {
        case .started:
            successCount += 1
            await thread.engine?.refreshShow()
            followOpening()
        case .needsConfirmation:
            isConfirmingPrivatePublish = true
        case .refused(_, let message):
            errorCount += 1
            openNotice = message
        case .failed(let problem):
            errorCount += 1
            openNotice = problem == .offline ? String(localized: "files.open.offline") : Self.words(problem)
            await thread.engine?.refreshShow()
        }
    }

    private static func words(_ problem: ThreadProblem) -> String {
        if case .server(_, let message) = problem, !message.isEmpty { return message }
        return String(localized: "files.open.failed")
    }

    /// Reads the show every few seconds while the server is still opening, in case its event does not arrive.
    private func followOpening() {
        openingTask?.cancel()
        openingTask = Task { [weak self] in
            for _ in 0..<Self.openingPollRounds {
                guard let self, !Task.isCancelled else { return }
                do { try await self.thread.session.clock.sleep(for: Self.openingPollInterval) } catch { return }
                guard self.openPullRequest == .opening else { return }
                await self.thread.engine?.refreshShow()
            }
        }
    }
}
