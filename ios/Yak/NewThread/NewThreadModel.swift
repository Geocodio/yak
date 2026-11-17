import Foundation
import Observation
import YakKit

/// The new-thread screen's state: the repositories and choices, the text and attachments, the saved draft and the
/// item a send leaves in the outbox until the server has started the thread.
@MainActor @Observable
final class NewThreadModel {
    /// How long the repositories may take before the screen offers Retry.
    static let slowAfter: Duration = .seconds(8)
    /// How long the typing must stop before the draft is saved.
    static let saveDelay: Duration = .milliseconds(500)
    static let draftKey = ComposerDraftKey.newThread

    private(set) var options: NewThreadOptions?
    private(set) var load: ThreadLoadState = .loading
    var form: NewThreadForm {
        didSet { if form != oldValue { scheduleSave() } }
    }
    var text: String {
        didSet { if text != oldValue { scheduleSave() } }
    }
    private(set) var attachments: [OutboxAttachment] = []
    private(set) var problem: NewThreadProblem?
    /// Counts the sends the form refused, for the haptic.
    private(set) var refusedSendCount = 0
    private(set) var sendFailure: SendProblem?
    private(set) var attachmentFailure: AttachmentFailure?
    /// The item the last send left in the outbox, until its delivery opens the new thread.
    private(set) var pending: OutboxItem?
    private(set) var isSending = false
    /// What the server said about each extra repository, once asked.
    private(set) var access: [String: RepositoryAccess] = [:]
    private(set) var checkingAccess: Set<String> = []
    private(set) var accessCheckFailed: Set<String> = []
    /// Set by the screen from its size class, so a delivery closes the drawer only where it covers the thread.
    var isCompactWidth = true

    enum AttachmentFailure: Equatable {
        case tooMany(maximum: Int)
        case tooLarge(maximumBytes: Int)
        case unreadable
        case notSaved
    }

    @ObservationIgnored private let session: InstanceSession
    @ObservationIgnored private let scene: SceneModel
    @ObservationIgnored private let prefill: (repo: String?, text: String?)?
    @ObservationIgnored private let itemID: UUID?
    @ObservationIgnored private let defaults: UserDefaults
    @ObservationIgnored private let slowAfter: Duration
    @ObservationIgnored private var sendKey: UUID?
    @ObservationIgnored private var submittedKey: UUID?
    @ObservationIgnored private var isStarted = false
    /// True once `start()` ran, so the screen can keep a model that only collected typing.
    @ObservationIgnored private(set) var hasStarted = false
    @ObservationIgnored private var loadTask: Task<Void, Never>?
    @ObservationIgnored private var isFinished = false
    @ObservationIgnored private var hasLoadedDraft = false
    @ObservationIgnored private var saveTask: Task<Void, Never>?
    @ObservationIgnored private var slowTask: Task<Void, Never>?
    @ObservationIgnored private var watchTasks: [Task<Void, Never>] = []
    @ObservationIgnored private var accessTasks: [String: Task<Void, Never>] = [:]

    init(
        session: InstanceSession, scene: SceneModel, prefill: (repo: String?, text: String?)?, item: UUID?,
        defaults: UserDefaults = .standard, slowAfter: Duration = NewThreadModel.slowAfter
    ) {
        self.session = session
        self.scene = scene
        self.prefill = prefill
        self.itemID = item
        self.defaults = defaults
        self.slowAfter = slowAfter
        let lastRepo = defaults.string(forKey: Self.lastRepositoryKey(session.instance.id))
        form = NewThreadForm(options: nil, saved: nil, lastUsedRepo: lastRepo, linkRepo: prefill?.repo)
        text = prefill?.text ?? ""
    }

    static func lastRepositoryKey(_ instance: UUID) -> String { "newThread.lastRepo.\(instance.uuidString)" }

    private var lastRepository: String? { defaults.string(forKey: Self.lastRepositoryKey(session.instance.id)) }

    var isOffline: Bool {
        if case .failed(.offline) = load { return true }
        return false
    }

    /// The field and the chips are locked while an item waits in the outbox.
    var isLocked: Bool { pending != nil || isSending }

    // MARK: Lifecycle

    /// Shows the saved draft, follows the outbox and asks for the repositories. A second call changes nothing.
    func start() async {
        guard !isStarted else { return }
        isStarted = true
        hasStarted = true
        await loadDraft()
        await watchOutbox()
        await loadOptions()
    }

    /// Saves the draft and stops every task. The item a send left in the outbox stays there.
    func stop() async {
        guard isStarted else { return }
        isStarted = false
        saveTask?.cancel()
        saveTask = nil
        slowTask?.cancel()
        loadTask?.cancel()
        loadTask = nil
        for task in watchTasks + Array(accessTasks.values) { task.cancel() }
        watchTasks = []
        accessTasks = [:]
        await saveDraftNow()
    }

    /// Asks for the repositories again, replacing a request still in flight; the text stays.
    func retry() async {
        guard isStarted else { return }
        await loadOptions()
    }

    // MARK: Draft

    private func loadDraft() async {
        let outbox = await session.outbox()
        if let itemID, let item = await outbox?.items().first(where: { $0.id == itemID }) {
            adopt(item)
        } else if let draft = await session.composerDraft(Self.draftKey) {
            // Text typed before the draft arrived wins.
            if text.isEmpty { text = draft.text }
            attachments = draft.attachments
            sendKey = draft.sendKey
            form = NewThreadForm(
                options: options, saved: draft.newThread, lastUsedRepo: lastRepository, linkRepo: prefill?.repo)
        }
        hasLoadedDraft = true
    }

    /// An item of an earlier send comes back to the screen: its text, its choices and its state.
    private func adopt(_ item: OutboxItem) {
        text = item.text
        attachments = item.attachments
        sendKey = item.id
        submittedKey = item.id
        pending = item
        if case .newThread(let draft) = item.target {
            form = NewThreadForm(options: options, saved: draft, lastUsedRepo: lastRepository, linkRepo: nil)
        }
    }

    private func scheduleSave() {
        guard hasLoadedDraft, isStarted else { return }
        saveTask?.cancel()
        saveTask = Task { [weak self] in
            do { try await Task.sleep(for: Self.saveDelay) } catch { return }
            await self?.saveDraftNow()
        }
    }

    private func saveDraftNow() async {
        guard hasLoadedDraft, !isFinished, pending == nil, !isSending else { return }
        let defaultForm = NewThreadForm(
            options: options, saved: nil, lastUsedRepo: lastRepository, linkRepo: prefill?.repo)
        let hasContent =
            !text.allSatisfy(\.isWhitespace) || !attachments.isEmpty || form.draft != defaultForm.draft
        let draft =
            hasContent
            ? ComposerDraft(
                text: text, model: form.model, sendKey: sendKey, attachments: attachments, newThread: form.draft)
            : nil
        await session.saveComposerDraft(draft, for: Self.draftKey)
    }

    // MARK: Repositories

    /// Runs the request in a task the model owns, so `stop()` and the next request can cancel it.
    private func loadOptions() async {
        loadTask?.cancel()
        let task = Task<Void, Never> { [weak self] in await self?.fetchOptions() }
        loadTask = task
        await task.value
    }

    private func fetchOptions() async {
        load = .loading
        slowTask?.cancel()
        slowTask = Task { [weak self, slowAfter] in
            do { try await Task.sleep(for: slowAfter) } catch { return }
            guard let self, self.load == .loading else { return }
            self.load = .slow
        }
        do {
            let loaded = try await session.newThreadOptions()
            guard !Task.isCancelled else { return }
            options = loaded
            Task { await session.noteNewThreadOptions(loaded, lastRepo: lastRepository) }
            form = NewThreadForm(
                options: loaded, saved: form.draft, lastUsedRepo: lastRepository, linkRepo: prefill?.repo)
            load = .ready
        } catch {
            guard !Task.isCancelled else { return }
            load = .failed(ThreadProblem(error))
        }
        slowTask?.cancel()
    }

    // MARK: Extra repositories

    func addExtraRepository(_ slug: String, role: String) {
        guard !isLocked else { return }
        form.addExtraRepository(slug, role: role)
        checkAccess(slug)
    }

    func removeExtraRepository(_ slug: String) {
        guard !isLocked else { return }
        form.removeExtraRepository(slug)
        access[slug] = nil
        accessCheckFailed.remove(slug)
    }

    /// Asks whether the person may use `slug` as an extra repository.
    func checkAccess(_ slug: String) {
        guard access[slug] == nil, accessTasks[slug] == nil else { return }
        checkingAccess.insert(slug)
        accessCheckFailed.remove(slug)
        accessTasks[slug] = Task { [weak self] in
            guard let self else { return }
            do {
                let answer = try await session.repositoryAccess(slug)
                access[slug] = answer
            } catch {
                if !Task.isCancelled { accessCheckFailed.insert(slug) }
            }
            checkingAccess.remove(slug)
            accessTasks[slug] = nil
        }
    }

    func selectRepository(_ slug: String) {
        guard let options, !isLocked else { return }
        form.selectRepository(slug, options: options)
        problem = nil
    }

    // MARK: Attachments

    /// Stores a picked file next to the draft. Files over the instance's limits are refused with a reason.
    func attach(_ data: Data, fileName: String, contentType: String, isImage: Bool) async {
        guard !isLocked else { return }
        attachmentFailure = nil
        let limits = await session.me()?.attachments
        if let limits {
            guard attachments.count < limits.maxFiles else {
                attachmentFailure = .tooMany(maximum: limits.maxFiles)
                return
            }
            guard data.count <= limits.maxFileBytes else {
                attachmentFailure = .tooLarge(maximumBytes: limits.maxFileBytes)
                return
            }
        }
        let key = sendKey ?? UUID()
        let reference = ComposerAttachments.references(isImage: [isImage], next: attachments.count + 1)[0]
        do {
            let stored = try await session.storeAttachment(
                data, fileName: fileName, contentType: contentType, reference: reference, sendKey: key)
            sendKey = key
            attachments.append(stored)
            scheduleSave()
        } catch {
            attachmentFailure = .notSaved
        }
    }

    func removeAttachment(_ attachment: OutboxAttachment) async {
        guard !isLocked, attachments.contains(attachment) else { return }
        attachments.removeAll { $0 == attachment }
        await session.discardAttachment(attachment)
        scheduleSave()
    }

    func reportUnreadableAttachment() { attachmentFailure = .unreadable }

    // MARK: Send

    func send() async {
        guard !isLocked else { return }
        // Locked from the first tap, so a second tap while the access checks run starts nothing.
        isSending = true
        defer { isSending = false }
        sendKey = sendKey ?? UUID()
        for task in Array(accessTasks.values) { await task.value }
        if let blocked = form.problem(text: text, options: options, access: access) {
            problem = blocked
            refusedSendCount += 1
            return
        }
        guard let target = form.draft else {
            problem = .noRepository
            refusedSendCount += 1
            return
        }
        problem = nil
        sendFailure = nil
        let key = sendKey ?? UUID()
        submittedKey = key
        saveTask?.cancel()
        do {
            let item = try await session.send(
                OutboxDraft(
                    target: .newThread(target), text: text.trimmingCharacters(in: .whitespacesAndNewlines),
                    model: form.model, delivery: .steer, attachments: attachments, sendKey: key))
            defaults.set(target.repo, forKey: Self.lastRepositoryKey(session.instance.id))
            // The outbox may already have reported a later state of this item.
            if !isFinished, pending?.id != item.id { pending = item }
            await session.saveComposerDraft(nil, for: Self.draftKey)
        } catch {
            submittedKey = nil
            sendFailure = error
            refusedSendCount += 1
        }
    }

    // MARK: The outbox item

    private func watchOutbox() async {
        guard let outbox = await session.outbox() else { return }
        watchTasks.append(
            Task { [weak self] in
                for await items in await outbox.changes() {
                    guard let self, !Task.isCancelled else { return }
                    if let key = submittedKey, let item = items.first(where: { $0.id == key }) {
                        if case .refused = item.state, !isRefused(pending) { refusedSendCount += 1 }
                        pending = item
                    }
                }
            })
        watchTasks.append(
            Task { [weak self] in
                for await delivery in await outbox.deliveries() {
                    guard let self, !Task.isCancelled else { return }
                    guard delivery.sendKey == submittedKey else { continue }
                    await finish(delivery)
                }
            })
    }

    private func isRefused(_ item: OutboxItem?) -> Bool {
        if case .refused = item?.state { return true }
        return false
    }

    private func finish(_ delivery: OutboxDelivery) async {
        isFinished = true
        pending = nil
        saveTask?.cancel()
        await session.saveComposerDraft(nil, for: Self.draftKey)
        scene.open(thread: delivery.threadID, isCompactWidth: isCompactWidth)
    }

    /// A waiting or refused item is sent again now.
    func retrySend() async {
        guard let pending, let outbox = await session.outbox() else { return }
        await outbox.retry(pending.id)
    }

    /// Takes a refused item back into the form and the field, attachments included.
    func edit() async {
        guard let item = pending, case .refused = item.state, let outbox = await session.outbox() else { return }
        var recovered: [(OutboxAttachment, Data)] = []
        var lost = false
        for attachment in item.attachments {
            if let data = await session.attachmentData(attachment) {
                recovered.append((attachment, data))
            } else {
                lost = true
            }
        }
        do { _ = try await outbox.remove(item.id) } catch { return }
        var restored: [OutboxAttachment] = []
        for (attachment, data) in recovered {
            do {
                restored.append(
                    try await session.storeAttachment(
                        data, fileName: attachment.fileName, contentType: attachment.contentType,
                        reference: attachment.reference, sendKey: item.id))
            } catch {
                lost = true
            }
        }
        submittedKey = nil
        pending = nil
        sendKey = item.id
        text = item.text
        attachments = restored
        attachmentFailure = lost ? .unreadable : nil
        if case .newThread(let draft) = item.target {
            form = NewThreadForm(options: options, saved: draft, lastUsedRepo: lastRepository, linkRepo: nil)
        }
        await saveDraftNow()
    }

    /// Throws a refused or waiting item away, with its text.
    func remove() async {
        guard let item = pending, let outbox = await session.outbox() else { return }
        do { _ = try await outbox.remove(item.id) } catch { return }
        submittedKey = nil
        pending = nil
        sendKey = nil
        attachments = []
        text = ""
        await session.saveComposerDraft(nil, for: Self.draftKey)
    }
}
