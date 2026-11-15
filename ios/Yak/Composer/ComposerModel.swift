import Foundation
import Observation
import YakKit

/// What the composer needs from the session and the engine, so its rules are tested without them.
protocol ComposerBackend: Sendable {
    func draft(_ key: ComposerDraftKey) async -> ComposerDraft?
    func saveDraft(_ draft: ComposerDraft?, for key: ComposerDraftKey) async
    func send(_ draft: OutboxDraft) async throws(SendProblem) -> OutboxItem
    func stop() async -> ThreadActionOutcome
}

struct LiveComposerBackend: ComposerBackend {
    let session: InstanceSession
    let engine: ThreadEngine?
    let threadID: String?

    func draft(_ key: ComposerDraftKey) async -> ComposerDraft? { await session.composerDraft(key) }

    func saveDraft(_ draft: ComposerDraft?, for key: ComposerDraftKey) async {
        await session.saveComposerDraft(draft, for: key)
    }

    func send(_ draft: OutboxDraft) async throws(SendProblem) -> OutboxItem { try await session.send(draft) }

    func stop() async -> ThreadActionOutcome {
        guard let engine else { return .failed(.offline) }
        return await engine.stopTurn()
    }
}

/// The text, attachments and model of one message being written, and the rules for sending, stopping and keeping
/// the draft. The bar's controls come from `bar`; the typed text is never cleared by anything but a send.
@MainActor @Observable
final class ComposerModel {
    let key: ComposerDraftKey
    private(set) var model: String?
    private(set) var attachments: [OutboxAttachment] = []
    private(set) var problem: String?
    private(set) var isStopping = false
    private(set) var isSending = false
    /// Counts refused sends, so the view can play the error haptic once per refusal.
    private(set) var refusalCount = 0

    private var typedText = ""
    private var snapshot: ConversationSnapshot?
    private var sendKey: UUID?
    private var hasLoaded = false
    private var hasEdited = false
    private var saveTask: Task<Void, Never>?
    @ObservationIgnored private var backend: any ComposerBackend
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private static let saveDelay = Duration.milliseconds(500)

    init(key: ComposerDraftKey, backend: any ComposerBackend, clock: any YakClock) {
        self.key = key
        self.backend = backend
        self.clock = clock
    }

    /// Saved 500 ms after the last change.
    var text: String {
        get { typedText }
        set {
            guard newValue != typedText else { return }
            typedText = newValue
            hasEdited = true
            problem = nil
            scheduleSave()
        }
    }

    var bar: ComposerBarState {
        let show = snapshot?.show
        return ComposerBarState.make(
            composer: show?.composer.state, canDrive: show?.canDrive, turn: snapshot?.turn, notice: show?.notice,
            typedText: typedText, attachmentCount: attachments.count)
    }

    /// True when there is something to send.
    var canSend: Bool {
        !isSending && (!typedText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || !attachments.isEmpty)
    }

    /// Reads the saved draft. Text typed before it arrives wins over the saved text.
    func load() async {
        guard !hasLoaded else { return }
        let saved = await backend.draft(key)
        guard !hasLoaded else { return }
        hasLoaded = true
        guard let saved else { return }
        if !hasEdited { typedText = saved.text }
        if attachments.isEmpty { attachments = saved.attachments }
        if model == nil { model = saved.model }
        sendKey = sendKey ?? saved.sendKey
    }

    func update(snapshot: ConversationSnapshot?) { self.snapshot = snapshot }

    /// A thread that opened a new engine hands the composer a backend that stops that engine.
    func use(backend: any ComposerBackend) { self.backend = backend }

    func send(delivery: MessageDelivery) async {
        guard canSend, case .thread(let threadID) = key else { return }
        isSending = true
        defer { isSending = false }
        let trimmed = typedText.trimmingCharacters(in: .whitespacesAndNewlines)
        let draft = OutboxDraft(
            target: .thread(threadID), text: trimmed, model: model, delivery: delivery, attachments: attachments,
            sendKey: sendKey)
        do {
            _ = try await backend.send(draft)
        } catch {
            problem =
                error == .notSaved
                ? String(localized: "composer.problem.notSaved") : String(localized: "composer.problem.notActive")
            refusalCount += 1
            return
        }
        saveTask?.cancel()
        typedText = ""
        attachments = []
        model = nil
        sendKey = nil
        hasEdited = true
        problem = nil
        await backend.saveDraft(nil, for: key)
    }

    func stop() async {
        guard !isStopping else { return }
        isStopping = true
        defer { isStopping = false }
        switch await backend.stop() {
        case .done, .alreadySent: problem = nil
        case .refused(_, let message, _):
            problem = message.isEmpty ? String(localized: "composer.problem.stopFailed") : message
        case .failed: problem = String(localized: "composer.problem.stopFailed")
        }
    }

    /// A refused item's Edit: its text, model and attachments come back into the field.
    func restore(_ item: OutboxItem) {
        typedText = item.text
        model = item.model
        attachments = item.attachments
        sendKey = item.id
        hasEdited = true
        problem = nil
        scheduleSave()
    }

    /// Saves now; called when the thread closes or the app goes to the background.
    func flush() async {
        saveTask?.cancel()
        saveTask = nil
        await backend.saveDraft(currentDraft, for: key)
    }

    private var currentDraft: ComposerDraft? {
        let draft = ComposerDraft(text: typedText, model: model, sendKey: sendKey, attachments: attachments)
        return draft.isEmpty ? nil : draft
    }

    private func scheduleSave() {
        saveTask?.cancel()
        saveTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.saveDelay) } catch { return }
            guard let self, !Task.isCancelled else { return }
            await self.backend.saveDraft(self.currentDraft, for: self.key)
        }
    }
}
