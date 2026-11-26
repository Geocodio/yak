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
    /// A short message about a refused or failed action, cleared after six seconds or on the next action.
    private var reportedActionLine: String?
    /// The action line says how things stand and is no failure: no warning haptic goes with it.
    private(set) var isActionLineInformation = false
    /// The send notice already shown as the action line, so one is said once.
    @ObservationIgnored private var shownNoticeID: UUID?
    /// The show a send notice came with. The notice stays on screen until the show changes again after the refresh
    /// that every delivery triggers, or until the next send starts.
    @ObservationIgnored private var noticeShow: ThreadShow?
    @ObservationIgnored private var hasNoticeSeenItsRefresh = false
    @ObservationIgnored private var isNoticeShown = false

    let session: InstanceSession
    @ObservationIgnored private var actionLineTask: Task<Void, Never>?
    @ObservationIgnored private var actionLineSerial = 0
    /// The Files pane's model; it lives as long as the thread and starts when the pane is first drawn.
    @ObservationIgnored private(set) lazy var files = FilesModel(thread: self)
    /// The agents bar above the message field: how many agents run and what "Stop all" is doing.
    @ObservationIgnored private(set) lazy var agents = RunningAgentsModel(
        threadID: threadID, engine: { [weak self] in self?.engine })
    /// A file another pane wants open in Files; the Files pane takes it and clears it.
    var fileOpenRequest: FileOpenRequest?
    /// The last request whose file the Files pane could not open.
    var fileOpenFailure: FileOpenRequest?
    /// The drawer row's title, shown until the thread's own arrives.
    /// The unsent messages of the instance and the progress of their background uploads, for the upload chips and the
    /// page's unsent rows.
    private(set) var unsentItems: [OutboxItem] = []
    private(set) var uploadSummary = UploadSummary()
    @ObservationIgnored private var outboxTask: Task<Void, Never>?
    @ObservationIgnored private var uploadTask: Task<Void, Never>?
    /// What VoiceOver says once when a turn ends; `announcementSerial` changes with each one.
    private(set) var announcement: String?
    private(set) var announcementSerial = 0
    private var initialTitle: String?
    private var isStarted = false
    private var hasNotedOpened = false
    private var isVisible = false
    private var readingTask: Task<Void, Never>?
    /// Counts opens and closes, so an open that finishes after a stop closes its own engine again.
    private var generation = 0

    /// The sandbox restore the Terminal and Files panes share, so two taps in two panes send one request.
    private(set) var restore: SandboxRestore = SandboxRestore { .failed(.offline) }

    /// Told each time the thread starts or stops counting as seen, so a push for it can stay quiet.
    private let onVisibilityChange: @MainActor (Bool) -> Void
    /// Told each snapshot, so a running turn can start a Live Activity.
    private let onSnapshot: @MainActor (ConversationSnapshot) -> Void

    /// `composer` is the one an earlier model of the same thread made, so a thread never has two.
    init(
        threadID: String, session: InstanceSession, composer: ComposerModel? = nil,
        onVisibilityChange: @escaping @MainActor (Bool) -> Void = { _ in },
        onSnapshot: @escaping @MainActor (ConversationSnapshot) -> Void = { _ in }
    ) {
        self.threadID = threadID
        self.session = session
        self.onVisibilityChange = onVisibilityChange
        self.onSnapshot = onSnapshot
        self.composer =
            composer
            ?? ComposerModel(
                key: .thread(threadID),
                backend: LiveComposerBackend(session: session, engine: nil, threadID: threadID), clock: SystemClock())
        restore = SandboxRestore { [weak self] in
            guard let engine = await self?.engine else { return .failed(.offline) }
            return await engine.retryRestore()
        }
    }

    /// The words for a refused or failed action; while an action waits out the server's rate limit for more than five
    /// seconds, the line that says so.
    var actionLine: String? {
        reportedActionLine ?? (snapshot?.isActionSlow == true ? String(localized: "chat.action.slow") : nil)
    }

    var title: String { snapshot?.show?.thread.title ?? initialTitle ?? "" }
    var panes: [PaneID] { Panes.available(server: snapshot?.show?.panes) }
    var connectionLine: ConnectionLine? { snapshot.flatMap(ConnectionLine.make) }
    var statusDot: ThreadStatusDot { ThreadStatusDot.make(snapshot) }
    var statusTone: Tone? { snapshot?.show?.request?.status.tone }
    var loadPresentation: ThreadLoadPresentation {
        if couldNotOpen, snapshot == nil { return .serverError(message: String(localized: "load.openFailed")) }
        return ThreadLoadPresentation.make(snapshot?.load ?? .loading)
    }
    var hasOpenQuestion: Bool { snapshot?.hasOpenQuestion ?? false }
    /// The thread was on screen and the server now says it does not exist: it was deleted elsewhere. A thread that
    /// never loaded keeps the not-found screen.
    var isGone: Bool { snapshot?.show != nil && snapshot?.problem == .notFound }
    /// The thread `/clear` started; the screen opens it.
    var replacementThreadID: String? { snapshot?.replacementThreadID }
    var instanceURL: URL { session.instance.url }
    var isOffline: Bool { snapshot?.isOffline ?? false }
    /// The upload chips of this thread's unsent messages.
    var uploadChips: [UploadChip] {
        UploadChips.make(items: unsentItems, threadID: threadID, summary: uploadSummary, isOffline: isOffline)
    }

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
        onVisibilityChange(false)
        await files.stop()
        await composer.flush()
        await close()
    }

    /// Saves the draft now; the screen calls it when the app goes to the background.
    func flushDraft() async {
        await composer.flush()
    }

    func setVisible(_ visible: Bool) async {
        isVisible = visible
        onVisibilityChange(visible)
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
        outboxTask = Task { [weak self] in
            guard let outbox = await self?.session.outbox() else { return }
            self?.unsentItems = await outbox.items()
            for await update in await outbox.updates() {
                guard !Task.isCancelled else { return }
                if case .items(let items) = update { self?.unsentItems = items }
            }
        }
        uploadTask = Task { [weak self] in
            guard let summaries = await self?.session.uploadSummaries() else { return }
            for await next in summaries {
                guard !Task.isCancelled else { return }
                self?.uploadSummary = next
            }
        }
        readingTask = Task { [weak self] in
            for await next in await opened.snapshots() {
                guard let self, !Task.isCancelled else { return }
                self.apply(next)
                // The thread can close while `apply` waits; the composer then keeps what it has.
                guard !Task.isCancelled else { return }
                self.onSnapshot(next)
                self.noteOpenedForSharing(next)
                self.composer.update(snapshot: next)
                self.agents.showReported(next.show?.agents)
            }
        }
    }

    private func close() async {
        generation += 1
        readingTask?.cancel()
        readingTask = nil
        outboxTask?.cancel()
        uploadTask?.cancel()
        outboxTask = nil
        uploadTask = nil
        unsentItems = []
        uploadSummary = UploadSummary()
        actionLineTask?.cancel()
        actionLineTask = nil
        reportedActionLine = nil
        guard engine != nil else { return }
        engine = nil
        await session.closeThread(threadID)
    }
}

// MARK: Snapshots

extension ThreadModel {
    private static let announcementLength = 200

    nonisolated static func isTurnActive(_ snapshot: ConversationSnapshot?) -> Bool {
        guard let snapshot else { return false }
        return snapshot.turn?.state.isActive ?? (snapshot.show?.composer.state == .working)
    }

    private func announceTurnEnd(_ snapshot: ConversationSnapshot) {
        let lastText = snapshot.entries.reversed().lazy.compactMap { entry -> String? in
            guard case .event(let item) = entry, item.kind == .text, !item.isStreaming else { return nil }
            return item.text?.trimmingCharacters(in: .whitespacesAndNewlines)
        }.first { !$0.isEmpty }
        if let lastText {
            announcement = String(
                localized: "chat.announce.finishedWith \(String(lastText.prefix(Self.announcementLength)))")
        } else {
            announcement = String(localized: "chat.announce.finished")
        }
        announcementSerial += 1
    }
}

extension ThreadModel {
    /// Takes a newer snapshot: the send notice and the action line that follows it.
    func apply(_ next: ConversationSnapshot) {
        guard !Task.isCancelled else { return }
        let wasActive = Self.isTurnActive(snapshot)
        snapshot = next
        if wasActive, !Self.isTurnActive(next), next.load == .ready { announceTurnEnd(next) }
        if isNoticeShown, next.show != noticeShow {
            if hasNoticeSeenItsRefresh {
                clearActionLine()
            } else {
                hasNoticeSeenItsRefresh = true
                noticeShow = next.show
            }
        }
        if let notice = next.lastSendNotice, notice.id != shownNoticeID {
            shownNoticeID = notice.id
            setActionLine(notice.text, persists: true)
            noticeShow = next.show
            hasNoticeSeenItsRefresh = false
        }
    }

    /// Tells the share sheet which thread was opened last, once per model, when its show first arrives.
    func noteOpenedForSharing(_ snapshot: ConversationSnapshot) {
        guard !hasNotedOpened, let show = snapshot.show else { return }
        hasNotedOpened = true
        Task { await session.noteOpenedThread(show) }
    }

    // MARK: Actions

    /// The work actions of the thread's menu; a refusal or failure is said in the action line.
    func performWork(_ action: ThreadWorkAction) async {
        guard let engine else { return show(.failed(.offline)) }
        clearActionLine()
        let outcome = await engine.perform(action)
        switch (outcome, action) {
        case (.refused("review_not_requested", let message, _, _), _):
            setActionLine(
                message.isEmpty ? String(localized: "chat.work.reviewNotRequested") : message, isInformation: true)
        case (.done, .requestReview): setActionLine(String(localized: "chat.work.reviewRequested"), isInformation: true)
        case (.done, .retry), (.done, .setUpAndContinue):
            setActionLine(String(localized: "chat.work.retried"), isInformation: true)
        case (.done, .retryRender): setActionLine(String(localized: "chat.work.renderRetried"), isInformation: true)
        default: show(outcome)
        }
    }

    /// Saves a queued message's new text. The sheet shows a refusal itself; a message that already went out is
    /// told here, because the sheet closes.
    func editQueued(_ uuid: String, text: String) async -> ThreadActionOutcome {
        guard let engine else { return .failed(.offline) }
        let outcome = await engine.editQueuedMessage(uuid, text: text)
        if outcome == .alreadySent { show(outcome) }
        return outcome
    }

    /// `reportsLine` false leaves the words to the caller, which shows them beside the message itself.
    @discardableResult
    func setQueuedMode(_ uuid: String, mode: MessageMode, reportsLine: Bool = true) async -> ThreadActionOutcome {
        guard let engine else { return finish(.failed(.offline), reportsLine: reportsLine) }
        return finish(await engine.setQueuedMessageMode(uuid, mode: mode), reportsLine: reportsLine)
    }

    @discardableResult
    func withdrawQueued(_ uuid: String, reportsLine: Bool = true) async -> ThreadActionOutcome {
        guard let engine else { return finish(.failed(.offline), reportsLine: reportsLine) }
        return finish(await engine.withdrawQueuedMessage(uuid), reportsLine: reportsLine)
    }

    private func finish(_ outcome: ThreadActionOutcome, reportsLine: Bool) -> ThreadActionOutcome {
        if reportsLine { show(outcome) }
        return outcome
    }

    /// Sends a refused or waiting message again now.
    func retryUnsent(_ id: UUID) async {
        await session.outbox()?.retry(id)
        if uploadSummary.failed.contains(id) { await session.retryFailedUploads() }
    }

    /// Takes an unsent message out of the outbox and returns it; nil when it is gone or is sending right now.
    func removeUnsent(_ id: UUID) async -> OutboxItem? {
        guard let outbox = await session.outbox() else { return nil }
        do throws(OutboxEditProblem) {
            return try await outbox.remove(id)
        } catch {
            if error == .alreadySending { setActionLine(String(localized: "chat.action.sendingNow")) }
            return nil
        }
    }

    /// Hands an unsent message back to the composer: its text, model and files, taken out of the outbox. False when
    /// it could not be taken; the composer then says why.
    func edit(_ item: OutboxItem) async -> Bool {
        await composer.restore(item)
    }

    /// Words for how an action ended: nothing when it worked.
    private func show(_ outcome: ThreadActionOutcome) {
        if let words = Self.words(for: outcome) { setActionLine(words) }
    }

    /// The words for how an action ended, in the server's own where it gave some; nil when it worked.
    nonisolated static func words(for outcome: ThreadActionOutcome) -> String? {
        switch outcome {
        case .done: nil
        case .alreadySent: String(localized: "chat.message.alreadySent")
        case .refused(_, let message, _, _): message.isEmpty ? String(localized: "chat.action.refused") : message
        case .failed(let problem):
            switch problem {
            case .offline: String(localized: "chat.action.offline")
            case .server(_, let message) where !message.isEmpty: message
            default: String(localized: "chat.action.failed")
            }
        }
    }

    /// The composer calls this when a person sends, so a notice about the last send gives way to the next one.
    func sendStarted() {
        if isNoticeShown { clearActionLine() }
    }

    private func setActionLine(_ text: String, persists: Bool = false, isInformation: Bool = false) {
        actionLineSerial += 1
        let mine = actionLineSerial
        reportedActionLine = text
        isActionLineInformation = isInformation
        isNoticeShown = persists
        actionLineTask?.cancel()
        actionLineTask = nil
        guard !persists else { return }
        actionLineTask = Task { [weak self] in
            do { try await self?.session.clock.sleep(for: .seconds(6)) } catch { return }
            guard let self, self.actionLineSerial == mine else { return }
            self.reportedActionLine = nil
        }
    }

    private func clearActionLine() {
        isNoticeShown = false
        isActionLineInformation = false
        actionLineSerial += 1
        actionLineTask?.cancel()
        reportedActionLine = nil
    }
}
