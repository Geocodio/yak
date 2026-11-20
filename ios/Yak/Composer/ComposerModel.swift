import Foundation
import Observation
import UniformTypeIdentifiers
import YakKit

/// What the composer needs from the session and the engine, so its rules are tested without them.
protocol ComposerBackend: Sendable {
    func draft(_ key: ComposerDraftKey) async -> ComposerDraft?
    func saveDraft(_ draft: ComposerDraft?, for key: ComposerDraftKey) async
    func send(_ draft: OutboxDraft) async throws(SendProblem) -> OutboxItem
    func stop() async -> ThreadActionOutcome
    func storeAttachment(_ prepared: PreparedAttachment, reference: String, sendKey: UUID) async throws(FileStoreError)
        -> OutboxAttachment
    func attachmentData(_ attachment: OutboxAttachment) async -> Data?
    func attachmentExists(_ attachment: OutboxAttachment) async -> Bool
    func discardAttachment(_ attachment: OutboxAttachment) async
    func limits() async -> Me.AttachmentLimits?
    /// Gives a refused or waiting item up with its files.
    func takeOutboxItem(_ id: UUID) async -> OutboxTakeOutcome
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

    func storeAttachment(_ prepared: PreparedAttachment, reference: String, sendKey: UUID) async throws(FileStoreError)
        -> OutboxAttachment
    {
        try await session.storeAttachment(
            prepared.data, fileName: prepared.fileName, contentType: prepared.contentType, reference: reference,
            sendKey: sendKey)
    }

    func attachmentData(_ attachment: OutboxAttachment) async -> Data? { await session.attachmentData(attachment) }

    func attachmentExists(_ attachment: OutboxAttachment) async -> Bool { await session.attachmentExists(attachment) }

    func discardAttachment(_ attachment: OutboxAttachment) async { await session.discardAttachment(attachment) }

    func limits() async -> Me.AttachmentLimits? { await session.me()?.attachments }

    func takeOutboxItem(_ id: UUID) async -> OutboxTakeOutcome { await session.takeOutboxItem(id) }
}

/// The text, attachments and model of one message being written, and the rules for sending, stopping and keeping
/// the draft. The bar's controls come from `bar`; the typed text is never cleared by anything but a send.
///
/// A draft has a send key from its first character or attachment. A send takes the draft out of the field before
/// anything is awaited, so what is typed or added next belongs to a new draft with a new key; when the outbox
/// refuses the send, the taken draft comes back in front of that. Saves run one after another.
@MainActor @Observable
final class ComposerModel {
    let key: ComposerDraftKey
    private(set) var model: String?
    private(set) var effort: EffortLevel?
    private(set) var attachments: [OutboxAttachment] = []
    private(set) var problem: String?
    private(set) var isStopping = false
    private(set) var isSending = false
    /// Counts refused sends, so the view can play the error haptic once per refusal.
    private(set) var refusalCount = 0
    /// Counts sends the outbox accepted, so the view can play the success haptic once per send.
    private(set) var acceptedSendCount = 0
    /// Files being read and stored, which the strip shows as placeholders.
    private(set) var preparingCount = 0
    /// The server's limits once `me` is known; the defaults until then.
    private(set) var maximumFiles = 8
    private(set) var maximumFileBytes = 10 * 1024 * 1024
    /// Small pictures for the strip, by reference. A restored draft's are made from its stored files.
    private(set) var thumbnails: [String: Data] = [:]

    private var typedText = ""
    private var snapshot: ConversationSnapshot?
    private(set) var sendKey: UUID?
    /// The draft a send has taken out of the field and the outbox has not accepted yet.
    private var inFlight: ComposerDraft?
    private var inFlightThumbnails: [String: Data] = [:]
    private var hasLoaded = false
    private var hasEdited = false
    /// The highest attachment number handed out, so two adds in flight never share one.
    private var highestReservedNumber = 0
    private var saveTask: Task<Void, Never>?
    private var saveChain: Task<Void, Never>?
    @ObservationIgnored private var backend: any ComposerBackend
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private static let saveDelay = Duration.milliseconds(500)
    private static func message(for problem: AttachmentProblem) -> String {
        switch problem {
        case .tooMany(let maximum): String(localized: "composer.problem.tooMany \(maximum)")
        case .tooLarge(let bytes):
            String(
                localized:
                    "composer.problem.tooLarge \(ByteCountFormatter.string(fromByteCount: Int64(bytes), countStyle: .file))"
            )
        case .unreadable: String(localized: "composer.problem.unreadable")
        case .notStored: String(localized: "composer.problem.notStored")
        }
    }

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
            refreshSendKey()
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
        !isSending && preparingCount == 0
            && (!typedText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || !attachments.isEmpty)
    }

    /// Reads the saved draft. Text typed before it arrives wins over the saved text.
    func load() async {
        guard !hasLoaded else { return }
        await readLimits()
        let saved = await backend.draft(key)
        guard !hasLoaded else { return }
        hasLoaded = true
        guard let saved else { return }
        if !hasEdited { typedText = saved.text }
        var kept: [OutboxAttachment] = []
        for attachment in saved.attachments where await backend.attachmentExists(attachment) {
            kept.append(attachment)
        }
        let known = Set(attachments.map(\.path))
        attachments = kept.filter { !known.contains($0.path) } + attachments
        if model == nil {
            model = saved.model
            effort = saved.effort
        }
        sendKey = sendKey ?? saved.sendKey
        refreshSendKey()
        if kept.count != saved.attachments.count {
            problem = String(localized: "composer.problem.filesGone")
            await saveNow()
        }
        await fillMissingThumbnails()
    }

    func update(snapshot: ConversationSnapshot?) { self.snapshot = snapshot }

    /// A thread that opened a new engine hands the composer a backend that stops that engine.
    func use(backend: any ComposerBackend) { self.backend = backend }

    func send(delivery: MessageDelivery) async {
        guard canSend, preparingCount == 0, bar.unavailable == nil, delivery == .steer || bar.offersSendAfterTurn,
            case .thread(let threadID) = key
        else { return }
        isSending = true
        defer { isSending = false }
        let taken = takeDraft()
        let draft = OutboxDraft(
            target: .thread(threadID), text: taken.text.trimmingCharacters(in: .whitespacesAndNewlines),
            model: taken.model, delivery: delivery, attachments: taken.attachments, sendKey: taken.sendKey,
            effort: taken.effort)
        do {
            _ = try await backend.send(draft)
        } catch {
            putBack(taken)
            problem =
                error == .notSaved
                ? String(localized: "composer.problem.notSaved") : String(localized: "composer.problem.notActive")
            refusalCount += 1
            await saveNow()
            return
        }
        // The outbox owns the sent draft's files now.
        inFlight = nil
        inFlightThumbnails = [:]
        problem = nil
        acceptedSendCount += 1
        await saveNow()
    }

    /// Empties the field and hands its draft over: what is typed or added next starts a new draft.
    private func takeDraft() -> ComposerDraft {
        let taken = ComposerDraft(
            text: typedText, model: model, effort: effort, sendKey: sendKey ?? UUID(), attachments: attachments)
        saveTask?.cancel()
        hasEdited = true
        inFlight = taken
        inFlightThumbnails = thumbnails
        typedText = ""
        attachments = []
        thumbnails = [:]
        model = nil
        effort = nil
        sendKey = nil
        problem = nil
        return taken
    }

    /// The draft a refused send took comes back in front of whatever was typed or added meanwhile.
    private func putBack(_ taken: ComposerDraft) {
        let merged = merge(front: taken, with: field)
        typedText = merged.text
        attachments = merged.attachments
        model = merged.model
        effort = merged.effort
        sendKey = merged.sendKey
        thumbnails.merge(inFlightThumbnails) { current, _ in current }
        inFlight = nil
        inFlightThumbnails = [:]
    }

    private var field: ComposerDraft {
        ComposerDraft(text: typedText, model: model, effort: effort, sendKey: sendKey, attachments: attachments)
    }

    private func merge(front: ComposerDraft, with back: ComposerDraft) -> ComposerDraft {
        let frontIsBlank = front.text.allSatisfy(\.isWhitespace)
        let backIsBlank = back.text.allSatisfy(\.isWhitespace)
        let text: String
        if frontIsBlank {
            text = back.text
        } else if backIsBlank {
            text = front.text
        } else if front.text.last?.isWhitespace == true || back.text.first?.isWhitespace == true {
            text = front.text + back.text
        } else {
            text = front.text + "\n" + back.text
        }
        // A model and its effort travel together: the later pick wins as a pair.
        let backChose = back.model != nil || back.effort != nil
        return ComposerDraft(
            text: text, model: backChose ? back.model : front.model, effort: backChose ? back.effort : front.effort,
            sendKey: front.sendKey ?? back.sendKey,
            attachments: front.attachments + back.attachments)
    }

    func stop() async {
        guard bar.showsStop, !isStopping else { return }
        isStopping = true
        defer { isStopping = false }
        switch await backend.stop() {
        case .done, .alreadySent: problem = nil
        case .refused(_, let message, _, _):
            problem = message.isEmpty ? String(localized: "composer.problem.stopFailed") : message
        case .failed: problem = String(localized: "composer.problem.stopFailed")
        }
    }

    #if DEBUG
        private var hasAddedTestFixtures = false

        /// Test mode only: attaches generated pictures once, through `add`.
        func addTestFixtures(count: Int) async {
            guard count > 0, !hasAddedTestFixtures else { return }
            hasAddedTestFixtures = true
            for index in 0..<count {
                await add(TestMode.fixtureImageData(), fileName: "Photo \(index + 1).jpg", contentType: "image/jpeg")
            }
        }
    #endif

    /// Edit on a refused or waiting item: the outbox gives the item up with its files, and its text and attachments
    /// go in front of what the field already holds. False, with a line above the field, when it cannot be edited.
    @discardableResult
    func restore(_ item: OutboxItem) async -> Bool {
        let taken: OutboxItem
        switch await backend.takeOutboxItem(item.id) {
        case .taken(let found): taken = found
        case .unavailable:
            problem = String(localized: "composer.problem.notEditable")
            refusalCount += 1
            return false
        case .notSaved:
            problem = String(localized: "composer.problem.editNotSaved")
            refusalCount += 1
            return false
        }
        let ownKey = attachments.isEmpty ? taken.id : sendKey
        let merged = merge(
            front: ComposerDraft(
                text: taken.text, model: taken.model, effort: taken.effort, sendKey: ownKey,
                attachments: taken.attachments),
            with: field)
        typedText = merged.text
        attachments = merged.attachments
        model = merged.model
        effort = merged.effort
        sendKey = merged.sendKey
        hasEdited = true
        problem = nil
        await saveNow()
        await fillMissingThumbnails()
        return true
    }

    /// Pictures of images that came back from disk: made from the stored file, off the main actor.
    private func fillMissingThumbnails() async {
        for attachment in attachments where attachment.contentType.hasPrefix("image/") {
            guard thumbnails[attachment.reference] == nil, let data = await backend.attachmentData(attachment),
                let thumbnail = await AttachmentPreparer.thumbnail(of: data),
                attachments.contains(attachment)
            else { continue }
            thumbnails[attachment.reference] = thumbnail
        }
    }

    /// The next free "#N": past the server's number and every number in the draft or already handed out.
    private func reserveAttachmentNumber() -> Int {
        let inDraft = attachments.compactMap { $0.reference.split(separator: "#").last.flatMap { Int($0) } }.max() ?? 0
        let number = max(snapshot?.show?.composer.nextAttachmentNumber ?? 1, inDraft + 1, highestReservedNumber + 1)
        highestReservedNumber = number
        return number
    }

    /// The send key was made for an add that failed: it goes when nothing else holds the draft. The caller's own
    /// place still counts in `preparingCount` unless `isCallerCounted` is false.
    private func releaseUnusedSendKey(isCallerCounted: Bool = true) {
        let others = preparingCount - (isCallerCounted ? 1 : 0)
        if attachments.isEmpty && typedText.allSatisfy(\.isWhitespace) && others <= 0 { sendKey = nil }
    }

    /// A draft has a key from its first character or attachment, and none while it is empty.
    private func refreshSendKey() {
        if typedText.allSatisfy(\.isWhitespace) && attachments.isEmpty && preparingCount == 0 {
            sendKey = nil
        } else if sendKey == nil {
            sendKey = UUID()
        }
    }

    /// Takes the places of picked items before anything is awaited, so Send stays off from the pick until each one is
    /// added or has failed. Each item then ends in `add(isReserved: true)`, `addFile(at:isReserved: true)` or
    /// `releasePick()`.
    func reservePicks(_ count: Int) {
        preparingCount += count
        problem = nil
        refreshSendKey()
    }

    /// A reserved item that could not be loaded.
    func releasePick() {
        preparingCount = max(preparingCount - 1, 0)
        reportUnreadable()
        releaseUnusedSendKey(isCallerCounted: false)
    }

    /// Files and photos are checked against the server's limits, prepared off the main actor, stored under the
    /// draft's send key and numbered from the server's next number. A problem leaves the draft as it was.
    func add(_ data: Data, fileName: String, contentType: String, isReserved: Bool = false) async {
        if !isReserved { preparingCount += 1 }
        defer { preparingCount -= 1 }
        refreshSendKey()
        await readLimits()
        guard hasRoomForAnother() else {
            releaseUnusedSendKey()
            return
        }
        problem = nil
        let number = reserveAttachmentNumber()
        let key = sendKey ?? UUID()
        sendKey = key
        let prepared: PreparedAttachment
        do {
            prepared = try await AttachmentPreparer.prepare(
                data, fileName: fileName, contentType: contentType, maximumBytes: maximumFileBytes)
        } catch {
            if highestReservedNumber == number { highestReservedNumber = number - 1 }
            releaseUnusedSendKey()
            problem = Self.message(for: error)
            refusalCount += 1
            return
        }
        let thumbnail = prepared.isImage ? await AttachmentPreparer.thumbnail(of: prepared.data) : nil
        let reference = ComposerAttachments.references(isImage: [prepared.isImage], next: number)[0]
        do {
            let stored = try await backend.storeAttachment(prepared, reference: reference, sendKey: key)
            guard attachments.count < maximumFiles else {
                await backend.discardAttachment(stored)
                releaseUnusedSendKey()
                problem = Self.message(for: .tooMany(maximum: maximumFiles))
                refusalCount += 1
                return
            }
            attachments.append(stored)
            thumbnails[stored.reference] = thumbnail
        } catch {
            if highestReservedNumber == number { highestReservedNumber = number - 1 }
            releaseUnusedSendKey()
            problem = Self.message(for: .notStored)
            refusalCount += 1
            return
        }
        hasEdited = true
        await saveNow()
    }

    /// A file from the file importer: its size is checked before it is read.
    func addFile(at url: URL, isReserved: Bool = false) async {
        if !isReserved { preparingCount += 1 }
        refreshSendKey()
        await readLimits()
        guard hasRoomForAnother() else {
            preparingCount -= 1
            releaseUnusedSendKey(isCallerCounted: false)
            return
        }
        let data: Data
        do {
            data = try await AttachmentPreparer.read(url, maximumBytes: maximumFileBytes)
        } catch {
            preparingCount -= 1
            releaseUnusedSendKey(isCallerCounted: false)
            problem = Self.message(for: error)
            refusalCount += 1
            return
        }
        let name = url.lastPathComponent
        let type = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream"
        await add(data, fileName: name, contentType: type, isReserved: true)
    }

    /// A picker that could not hand over its file.
    func reportUnreadable() {
        problem = Self.message(for: .unreadable)
        refusalCount += 1
    }

    /// How many more files the draft takes.
    var attachmentSlotsLeft: Int { max(maximumFiles - attachments.count - preparingCount, 0) }

    private func readLimits() async {
        guard let limits = await backend.limits() else { return }
        maximumFiles = limits.maxFiles
        maximumFileBytes = limits.maxFileBytes
    }

    /// Items being added take their places in the order they finish, so only the files already in the draft count.
    private func hasRoomForAnother() -> Bool {
        guard attachments.count < maximumFiles else {
            problem = Self.message(for: .tooMany(maximum: maximumFiles))
            refusalCount += 1
            return false
        }
        problem = nil
        return true
    }

    func remove(_ attachment: OutboxAttachment) async {
        guard let index = attachments.firstIndex(of: attachment) else { return }
        attachments.remove(at: index)
        thumbnails[attachment.reference] = nil
        hasEdited = true
        problem = nil
        refreshSendKey()
        await backend.discardAttachment(attachment)
        await saveNow()
    }

    /// nil sends with the thread's own model.
    func selectModel(_ value: String?) {
        guard value != model else { return }
        let supported = ModelChoices.efforts(for: value ?? snapshot?.show?.composer.model, in: modelFamilies)
        effort = effectiveEffort?.clamped(to: supported)
        model = value
        hasEdited = true
        scheduleSave()
    }

    /// An effort choice is explicit: the model it applies to is sent with it, so nil means the model's default.
    func selectEffort(_ value: EffortLevel?) {
        guard value != effectiveEffort else { return }
        model = effectiveModel
        effort = value
        hasEdited = true
        scheduleSave()
    }

    /// The effort the next message uses: the chosen one, else the thread's own while no model was chosen.
    var effectiveEffort: EffortLevel? { model == nil ? (effort ?? snapshot?.show?.composer.effort) : effort }

    /// The levels the chosen model supports.
    var supportedEfforts: [EffortLevel] { ModelChoices.efforts(for: effectiveModel, in: modelFamilies) }

    /// The label of the chosen model, or of the thread's own when none was chosen.
    var modelLabel: String? {
        ModelChoices.label(for: effectiveModel, effort: effectiveEffort, in: modelFamilies)
    }

    var modelFamilies: [ModelFamily] { snapshot?.show?.composer.models ?? [] }

    /// The model the next message uses: the chosen one, else the thread's own.
    var effectiveModel: String? { model ?? snapshot?.show?.composer.model }

    /// Saves now; called when the thread closes or the app goes to the background.
    func flush() async {
        // Before the saved draft is read and before any edit, the field only looks empty; writing it would delete the draft.
        guard hasLoaded || hasEdited else { return }
        await saveNow()
    }

    /// What is on disk if the app stops now: a draft the outbox has not accepted yet is still part of it.
    private var currentDraft: ComposerDraft? {
        let draft = inFlight.map { merge(front: $0, with: field) } ?? field
        return draft.isEmpty ? nil : draft
    }

    private func scheduleSave() {
        saveTask?.cancel()
        saveTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.saveDelay) } catch { return }
            guard let self, !Task.isCancelled else { return }
            await self.enqueueSave().value
        }
    }

    private func saveNow() async {
        saveTask?.cancel()
        saveTask = nil
        await enqueueSave().value
    }

    /// Saves run one after another, and each writes the draft as it is when its turn comes, so a save that was
    /// slow cannot land after a later one and bring a sent draft back.
    private func enqueueSave() -> Task<Void, Never> {
        let previous = saveChain
        let next = Task { [weak self] in
            await previous?.value
            guard let self, self.hasLoaded || self.hasEdited else { return }
            await self.backend.saveDraft(self.currentDraft, for: self.key)
        }
        saveChain = next
        return next
    }
}
