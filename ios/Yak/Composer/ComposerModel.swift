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
    func discardAttachment(_ attachment: OutboxAttachment) async
    func limits() async -> Me.AttachmentLimits?
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

    func discardAttachment(_ attachment: OutboxAttachment) async { await session.discardAttachment(attachment) }

    func limits() async -> Me.AttachmentLimits? { await session.me()?.attachments }
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
    /// Files being read and stored, which the strip shows as placeholders.
    private(set) var preparingCount = 0
    /// The server's limits once `me` is known; the defaults until then.
    private(set) var maximumFiles = 8
    private(set) var maximumFileBytes = 10 * 1024 * 1024
    /// Small pictures for the strip, by reference. A restored draft's are made from its stored files.
    private(set) var thumbnails: [String: Data] = [:]

    private var typedText = ""
    private var snapshot: ConversationSnapshot?
    private var sendKey: UUID?
    private var hasLoaded = false
    private var hasEdited = false
    /// The highest attachment number handed out, so two adds in flight never share one.
    private var highestReservedNumber = 0
    private var saveTask: Task<Void, Never>?
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
        await readLimits()
        let saved = await backend.draft(key)
        guard !hasLoaded else { return }
        hasLoaded = true
        guard let saved else { return }
        if !hasEdited { typedText = saved.text }
        if attachments.isEmpty { attachments = saved.attachments }
        if model == nil { model = saved.model }
        sendKey = sendKey ?? saved.sendKey
        await fillMissingThumbnails()
    }

    func update(snapshot: ConversationSnapshot?) { self.snapshot = snapshot }

    /// A thread that opened a new engine hands the composer a backend that stops that engine.
    func use(backend: any ComposerBackend) { self.backend = backend }

    func send(delivery: MessageDelivery) async {
        guard canSend, bar.unavailable == nil, delivery == .steer || bar.offersSendAfterTurn,
            case .thread(let threadID) = key
        else { return }
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
        hasEdited = true
        problem = nil
        // Text typed while the send was in flight belongs to the next message and stays.
        let isUnchanged =
            typedText.trimmingCharacters(in: .whitespacesAndNewlines) == trimmed && attachments == draft.attachments
        sendKey = nil
        if isUnchanged {
            typedText = ""
            attachments = []
            thumbnails = [:]
            model = nil
            await backend.saveDraft(nil, for: key)
        } else {
            await backend.saveDraft(currentDraft, for: key)
        }
    }

    func stop() async {
        guard bar.showsStop, !isStopping else { return }
        isStopping = true
        defer { isStopping = false }
        switch await backend.stop() {
        case .done, .alreadySent: problem = nil
        case .refused(_, let message, _):
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

    /// A refused item's Edit: its text, model and attachments come back into the field.
    func restore(_ item: OutboxItem) {
        typedText = item.text
        model = item.model
        attachments = item.attachments
        thumbnails = [:]
        sendKey = item.id
        hasEdited = true
        problem = nil
        scheduleSave()
        Task { await fillMissingThumbnails() }
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

    /// Files and photos are checked against the server's limits, prepared off the main actor, stored under the
    /// draft's send key and numbered from the server's next number. A problem leaves the draft as it was.
    func add(_ data: Data, fileName: String, contentType: String) async {
        await readLimits()
        guard hasRoomForAnother() else { return }
        preparingCount += 1
        defer { preparingCount -= 1 }
        problem = nil
        let number = reserveAttachmentNumber()
        let prepared: PreparedAttachment
        do {
            prepared = try await AttachmentPreparer.prepare(
                data, fileName: fileName, contentType: contentType, maximumBytes: maximumFileBytes)
        } catch {
            if highestReservedNumber == number { highestReservedNumber = number - 1 }
            problem = Self.message(for: error)
            refusalCount += 1
            return
        }
        let thumbnail = prepared.isImage ? await AttachmentPreparer.thumbnail(of: prepared.data) : nil
        let key = sendKey ?? UUID()
        let reference = ComposerAttachments.references(isImage: [prepared.isImage], next: number)[0]
        do {
            let stored = try await backend.storeAttachment(prepared, reference: reference, sendKey: key)
            sendKey = key
            attachments.append(stored)
            thumbnails[stored.reference] = thumbnail
        } catch {
            if highestReservedNumber == number { highestReservedNumber = number - 1 }
            problem = Self.message(for: .notStored)
            refusalCount += 1
            return
        }
        hasEdited = true
        saveTask?.cancel()
        await backend.saveDraft(currentDraft, for: self.key)
    }

    /// A file from the file importer: its size is checked before it is read.
    func addFile(at url: URL) async {
        await readLimits()
        guard hasRoomForAnother() else { return }
        preparingCount += 1
        let data: Data
        do {
            data = try await AttachmentPreparer.read(url, maximumBytes: maximumFileBytes)
        } catch {
            preparingCount -= 1
            problem = Self.message(for: error)
            refusalCount += 1
            return
        }
        preparingCount -= 1
        let name = url.lastPathComponent
        let type = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream"
        await add(data, fileName: name, contentType: type)
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

    private func hasRoomForAnother() -> Bool {
        guard attachmentSlotsLeft > 0 else {
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
        await backend.discardAttachment(attachment)
        saveTask?.cancel()
        await backend.saveDraft(currentDraft, for: key)
    }

    /// nil sends with the thread's own model.
    func selectModel(_ value: String?) {
        guard value != model else { return }
        model = value
        hasEdited = true
        scheduleSave()
    }

    /// The label of the chosen model, or of the thread's own when none was chosen.
    var modelLabel: String? {
        ModelChoices.label(for: model ?? snapshot?.show?.composer.model, in: modelFamilies)
    }

    var modelFamilies: [ModelFamily] { snapshot?.show?.composer.models ?? [] }

    /// The model the next message uses: the chosen one, else the thread's own.
    var effectiveModel: String? { model ?? snapshot?.show?.composer.model }

    /// Saves now; called when the thread closes or the app goes to the background.
    func flush() async {
        // Before the saved draft is read and before any edit, the field only looks empty; writing it would delete the draft.
        guard hasLoaded || hasEdited else { return }
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
            guard let self, !Task.isCancelled, self.hasLoaded || self.hasEdited else { return }
            await self.backend.saveDraft(self.currentDraft, for: self.key)
        }
    }
}
