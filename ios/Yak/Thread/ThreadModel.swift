import Foundation
import Observation
import YakKit

/// What the chat shows at the top of its rows about earlier messages.
enum HistoryState: Equatable {
    /// There is nothing earlier.
    case hidden
    /// There is more, and the chat asks for it when the top is on screen.
    case available
    case loading
    /// The last request for earlier messages failed; the person can ask again.
    case failed
}

/// One open thread on screen: reads the engine's snapshots and hands the screen finished values.
@MainActor @Observable
final class ThreadModel {
    let threadID: String
    private(set) var snapshot: ConversationSnapshot?
    private(set) var engine: ThreadEngine?
    /// Lives as long as the model, so typed text survives a retry and a slow load.
    let composer: ComposerModel
    /// The card the server names above the bar, other than the question.
    @ObservationIgnored private(set) lazy var cards = ThreadCardModel(thread: self)
    /// The open question round, answered in the card above the bar.
    let question = QuestionModel(canDrive: nil)
    /// The session had no engine to give, so there is nothing to wait for.
    private(set) var couldNotOpen = false
    /// The rows of the conversation, with the day dividers, from the newest snapshot.
    private(set) var rows: [ConversationRow] = []
    /// The parsed Markdown of the text items, by item key. An item has none until its first parse is done.
    private(set) var documents: [String: AppMarkdownRenderer.Document] = [:]
    /// A short message about a refused or failed action, cleared after six seconds or on the next action.
    private var reportedActionLine: String?
    /// The action line says how things stand and is no failure: no warning haptic goes with it.
    private(set) var isActionLineInformation = false
    /// What VoiceOver says once when a turn ends; `announcementSerial` changes with each one.
    private(set) var announcement: String?
    private(set) var announcementSerial = 0
    /// A notice's button (Continue or Retry) has a request in flight.
    private(set) var isNoticeActionRunning = false
    /// The name of the signed-in person, to tell their messages from other people's.
    private(set) var ownName: String?
    /// The send notice already shown as the action line, so one is said once.
    @ObservationIgnored private var shownNoticeID: UUID?
    /// The show a send notice came with. The notice stays on screen until the show changes again after the refresh
    /// that every delivery triggers, or until the next send starts.
    @ObservationIgnored private var noticeShow: ThreadShow?
    @ObservationIgnored private var hasNoticeSeenItsRefresh = false
    @ObservationIgnored private var isNoticeShown = false

    let session: InstanceSession
    /// Day dividers follow this calendar; a test sets another.
    var calendar = Calendar.current
    @ObservationIgnored var buildRows: RowsBuilder = ThreadModel.defaultRowsBuilder
    @ObservationIgnored private var markdownCache: MarkdownCache<AppMarkdownRenderer>?
    @ObservationIgnored private var markdownItems: [String: ConversationItem] = [:]
    @ObservationIgnored private var pendingMarkdown: [ConversationItem]?
    @ObservationIgnored private var markdownTask: Task<Void, Never>?
    /// Which task `markdownTask` holds, so an older task cannot clear a newer one's handle.
    @ObservationIgnored private var markdownTaskToken: UUID?
    @ObservationIgnored private var dirtyMarkdownKeys: Set<String> = []
    @ObservationIgnored private var flushTask: Task<Void, Never>?
    @ObservationIgnored private var actionLineTask: Task<Void, Never>?
    @ObservationIgnored private var actionLineSerial = 0
    /// The Files pane's model; it lives as long as the thread and starts when the pane is first drawn.
    @ObservationIgnored private(set) lazy var files = FilesModel(thread: self)
    /// A file another pane wants open in Files; the Files pane takes it and clears it.
    var fileOpenRequest: FileOpenRequest?
    /// The last request whose file the Files pane could not open.
    var fileOpenFailure: FileOpenRequest?
    /// The drawer row's title, shown until the thread's own arrives.
    /// Progress and failures of this thread's background uploads.
    let uploadWatch = UploadWatch()
    /// The loader of this thread's attachment thumbnails and files, made when the first one is asked for.
    let attachmentLoading = AttachmentLoading()
    private var initialTitle: String?
    private var isStarted = false
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
        markdownCache = MarkdownCache(renderer: AppMarkdownRenderer(), clock: session.clock) { [weak self] key in
            self?.markdownChanged(key)
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
    /// "Step 5 of 7: CI failed" for the failed step, else the current one.
    var stepLine: String? {
        guard let progress = snapshot?.show?.progress, let step = progress.attentionStep,
            let index = progress.steps.firstIndex(of: step)
        else { return nil }
        return String(localized: "thread.step \(index + 1) \(progress.steps.count) \(step.label)")
    }
    var loadPresentation: ThreadLoadPresentation {
        if couldNotOpen, snapshot == nil { return .serverError(message: String(localized: "load.openFailed")) }
        return ThreadLoadPresentation.make(snapshot?.load ?? .loading)
    }
    var hasOpenQuestion: Bool { snapshot?.hasOpenQuestion ?? false }
    /// The thread `/clear` started; the screen opens it.
    var replacementThreadID: String? { snapshot?.replacementThreadID }
    var instanceURL: URL { session.instance.url }
    var isOffline: Bool { snapshot?.isOffline ?? false }
    var notice: ThreadNotice? { snapshot?.show?.notice }
    var historyState: HistoryState {
        guard let snapshot, snapshot.hasOlderHistory else { return .hidden }
        if snapshot.isLoadingOlder { return .loading }
        return snapshot.didOlderFail ? .failed : .available
    }
    var isTurnActive: Bool { Self.isTurnActive(snapshot) }

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
        await question.flush()
        await close()
    }

    /// Saves the draft now; the screen calls it when the app goes to the background.
    func flushDraft() async {
        await composer.flush()
        await question.flush()
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
        question.use(backend: LiveQuestionBackend(engine: opened))
        await opened.setVisible(isVisible)
        uploadWatch.start(summaries: await session.uploadSummaries(), clock: session.clock)
        if ownName == nil { ownName = await session.me()?.user.name }
        readingTask = Task { [weak self] in
            for await next in await opened.snapshots() {
                guard let self, !Task.isCancelled else { return }
                await self.apply(next)
                // The thread can close while `apply` waits; the composer then keeps what it has.
                guard !Task.isCancelled else { return }
                self.onSnapshot(next)
                self.noteOpenedForSharing(next)
                self.composer.update(snapshot: next)
                self.question.canDrive = next.show?.canDrive
                if let show = next.show { await self.question.update(question: show.question) }
                if !next.isOffline { self.question.connectionRestored() }
            }
        }
    }

    private func close() async {
        generation += 1
        readingTask?.cancel()
        readingTask = nil
        uploadWatch.stop()
        markdownTask?.cancel()
        flushTask?.cancel()
        actionLineTask?.cancel()
        markdownTask = nil
        flushTask = nil
        actionLineTask = nil
        reportedActionLine = nil
        dirtyMarkdownKeys = []
        await markdownCache?.clear()
        guard engine != nil else { return }
        engine = nil
        await session.closeThread(threadID)
    }
}

// MARK: Chat

extension ThreadModel {
    private static let announcementLength = 200

    private static func isTurnActive(_ snapshot: ConversationSnapshot?) -> Bool {
        guard let snapshot else { return false }
        return snapshot.turn?.state.isActive ?? (snapshot.show?.composer.state == .working)
    }

    /// Builds the rows of a snapshot; a test replaces it to see where it runs.
    typealias RowsBuilder = @Sendable (ConversationSnapshot, Date, Calendar) -> [ConversationRow]

    nonisolated static let defaultRowsBuilder: RowsBuilder = { snapshot, now, calendar in
        ConversationRows.make(
            entries: snapshot.entries, currentStepKey: snapshot.currentStepKey, waiting: snapshot.waiting,
            now: now, calendar: calendar)
    }

    /// Takes a newer snapshot: the rows, the Markdown parses and the announcement when a turn has just ended.
    /// The rows are built before the main actor takes the snapshot, so a long history never holds up scrolling.
    func apply(_ next: ConversationSnapshot) async {
        let builtRows = await Self.makeRows(next, now: session.clock.now(), calendar: calendar, using: buildRows)
        guard !Task.isCancelled else { return }
        let wasActive = Self.isTurnActive(snapshot)
        snapshot = next
        rows = builtRows
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
        if wasActive, !Self.isTurnActive(next), next.load == .ready { announceTurnEnd(next) }
        requestMarkdown(for: next.entries)
    }

    @concurrent private nonisolated static func makeRows(
        _ snapshot: ConversationSnapshot, now: Date, calendar: Calendar, using builder: RowsBuilder
    ) async -> [ConversationRow] {
        builder(snapshot, now, calendar)
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

    // MARK: Markdown

    private func requestMarkdown(for entries: [ConversationEntry]) {
        let items = entries.compactMap { entry -> ConversationItem? in
            guard case .event(let item) = entry, item.kind == .text || item.kind == .result else { return nil }
            return item
        }
        markdownItems = Dictionary(items.map { ($0.key, $0) }, uniquingKeysWith: { $1 })
        pendingMarkdown = items
        guard markdownTask == nil else { return }
        let token = UUID()
        markdownTaskToken = token
        markdownTask = Task { [weak self] in
            while let self, !Task.isCancelled, let items = self.pendingMarkdown {
                self.pendingMarkdown = nil
                await self.markdownCache?.update(with: items)
            }
            // A task that `close()` cancelled can end after a newer one started; it clears only its own handle.
            if self?.markdownTaskToken == token { self?.markdownTask = nil }
        }
    }

    /// The cache has a new document for `key`. Parses of a long history finish one after another, so they are
    /// collected and published together.
    private func markdownChanged(_ key: String) {
        dirtyMarkdownKeys.insert(key)
        guard flushTask == nil else { return }
        flushTask = Task { [weak self] in
            do { try await self?.session.clock.sleep(for: .milliseconds(50)) } catch { return }
            await self?.flushMarkdown()
        }
    }

    private func flushMarkdown() async {
        let keys = dirtyMarkdownKeys
        dirtyMarkdownKeys = []
        flushTask = nil
        var updated: [String: AppMarkdownRenderer.Document] = [:]
        for key in keys {
            if let item = markdownItems[key], let document = await markdownCache?.document(for: item) {
                updated[key] = document
            }
        }
        documents.merge(updated) { $1 }
    }

    // MARK: Actions

    /// Lets the turn go on after the guard stopped it.
    func continueTurn() async {
        await performNoticeAction { await $0.continueTurn() }
    }

    func retryRestore() async {
        await performNoticeAction { await $0.retryRestore() }
    }

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

    func loadOlder() async {
        guard let engine else { return }
        await engine.loadOlder()
    }

    /// Saves a queued message's new text. The sheet shows a refusal itself; a message that already went out is
    /// told here, because the sheet closes.
    func editQueued(_ uuid: String, text: String) async -> ThreadActionOutcome {
        guard let engine else { return .failed(.offline) }
        let outcome = await engine.editQueuedMessage(uuid, text: text)
        if outcome == .alreadySent { show(outcome) }
        return outcome
    }

    func setQueuedMode(_ uuid: String, mode: MessageMode) async {
        guard let engine else { return show(.failed(.offline)) }
        show(await engine.setQueuedMessageMode(uuid, mode: mode))
    }

    func withdrawQueued(_ uuid: String) async {
        guard let engine else { return show(.failed(.offline)) }
        show(await engine.withdrawQueuedMessage(uuid))
    }

    /// Sends a refused or waiting message again now.
    func retryUnsent(_ id: UUID) async {
        await session.outbox()?.retry(id)
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

    /// The bytes of an attachment of an unsent message, read off the main actor.
    func attachmentData(_ attachment: OutboxAttachment) async -> Data? {
        await session.attachmentData(attachment)
    }

    private func performNoticeAction(_ action: (ThreadEngine) async -> ThreadActionOutcome) async {
        guard let engine, !isNoticeActionRunning else { return }
        clearActionLine()
        isNoticeActionRunning = true
        let outcome = await action(engine)
        isNoticeActionRunning = false
        show(outcome)
    }

    /// Words for how an action ended: nothing when it worked.
    private func show(_ outcome: ThreadActionOutcome) {
        switch outcome {
        case .done: break
        case .alreadySent: setActionLine(String(localized: "chat.message.alreadySent"))
        case .refused(_, let message, _, _):
            setActionLine(message.isEmpty ? String(localized: "chat.action.refused") : message)
        case .failed(let problem):
            switch problem {
            case .offline: setActionLine(String(localized: "chat.action.offline"))
            case .server(_, let message) where !message.isEmpty: setActionLine(message)
            default: setActionLine(String(localized: "chat.action.failed"))
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
