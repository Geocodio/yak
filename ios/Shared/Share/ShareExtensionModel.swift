import Foundation
import Observation
import YakKit

/// The share sheet's state: what was shared, where it goes and whether it can be sent.
@MainActor @Observable
final class ShareExtensionModel {
    enum Phase: Equatable {
        case noInstance
        case signedOut(String)
        /// The Keychain could not be read: not the same as signed out, and worth another try.
        case unreadable
        case loading, ready, saving
    }

    private(set) var phase: Phase = .loading
    /// True after a send that could not be saved; the sheet is ready again with everything kept.
    private(set) var didFailToSave = false
    /// Counts failed sends, so the error haptic fires once per failure.
    private(set) var saveFailureCount = 0
    private(set) var context: ShareContext?
    private(set) var progress: ShareImportProgress?
    private(set) var isOffline = false
    private(set) var importResult = ShareImport()
    private(set) var isRefreshingOptions = false
    private(set) var isSlowToLoadOptions = false
    var typed = ""
    var target: ShareTarget = .newThread
    private(set) var form: NewThreadForm?
    private(set) var model: String?
    private(set) var instance: Instance?
    /// Every instance that can send; the sheet offers a menu when there are several.
    private(set) var readyInstances: [Instance] = []

    private let instances: InstanceStore
    private let tokens: any TokenStore
    private let files: DiskFileStore
    private let sender: any ShareSending
    private let clock: any YakClock
    private let fetchOptions: @Sendable (Instance, String) async throws -> NewThreadOptions
    private var token: String?
    private let stagingFolder: String
    /// One key for every attempt to send this share, so a retry never becomes a second message.
    private let sendKey = UUID()
    private var importTask: Task<Void, Never>?
    private var refreshTask: Task<Void, Never>?
    private var providers: [any ShareItemProvider] = []

    init(
        instances: InstanceStore, tokens: any TokenStore, files: DiskFileStore, sender: any ShareSending,
        clock: any YakClock, fetchOptions: @escaping @Sendable (Instance, String) async throws -> NewThreadOptions
    ) {
        self.instances = instances
        self.tokens = tokens
        self.files = files
        self.sender = sender
        self.clock = clock
        self.fetchOptions = fetchOptions
        self.stagingFolder = ShareStaging.folder(now: clock.now(), id: UUID())
    }

    /// The draft is recomputed from the current choices; nil before an instance is chosen.
    var draft: ShareDraft? {
        guard instance != nil else { return nil }
        return ShareDraft(
            typed: typed, inputs: importResult.inputs, target: target, form: form, model: model, context: context)
    }

    var problem: ShareProblem? { draft?.problem }
    var notices: [ShareNotice] { draft?.notices ?? [] }
    var isPreparing: Bool { progress != nil }
    var canSend: Bool { phase == .ready && !isPreparing && problem == nil }

    // MARK: Start

    func start(_ providers: [any ShareItemProvider]) async {
        self.providers = providers
        let all = await instances.all()
        guard !all.isEmpty else {
            phase = .noInstance
            return
        }
        var ready: [Instance] = []
        var isAnyUnreadable = false
        for candidate in all {
            switch await instances.state(of: candidate.id) {
            case .ready:
                if let token = try? tokens.token(for: candidate.id), !token.isEmpty { ready.append(candidate) }
            case .tokenUnreadable: isAnyUnreadable = true
            default: break
            }
        }
        readyInstances = ready
        let active = await instances.active()
        guard let chosen = ready.first(where: { $0.id == active?.id }) ?? ready.first else {
            instance = active ?? all.first
            phase = isAnyUnreadable ? .unreadable : .signedOut((active ?? all[0]).name)
            return
        }
        await use(chosen)
        await importItems(providers)
        await refreshTask?.value
    }

    /// Starts over after a failed start.
    func retry() async {
        phase = .loading
        await start(providers)
    }

    /// Sends to another instance: its saved context and repositories replace the current ones.
    func choose(_ chosen: Instance) async {
        guard chosen.id != instance?.id, phase == .ready else { return }
        refreshTask?.cancel()
        await use(chosen)
        await refreshTask?.value
    }

    private func use(_ chosen: Instance) async {
        guard let token = try? tokens.token(for: chosen.id) else { return }
        instance = chosen
        self.token = token
        isOffline = false
        context = ShareContextStore.read(instance: chosen.id, files: files)
        rebuildForm()
        phase = .ready
        refreshTask = Task { await refreshOptions(chosen, token: token) }
    }

    private func importItems(_ providers: [any ShareItemProvider]) async {
        let maximumBytes = context?.limits.maxFileBytes ?? 10_485_760
        let importer = ShareItemImporter(
            files: files, staging: stagingFolder, clock: clock, maximumBytes: { maximumBytes })
        progress = ShareImportProgress(current: 0, total: providers.count, isSlow: false)
        let task = Task { [weak self] in
            let result = await importer.load(providers) { update in
                Task { @MainActor in self?.progress = update }
            }
            guard !Task.isCancelled, let self else { return }
            self.importResult = result
            self.progress = nil
        }
        importTask = task
        await task.value
    }

    private func refreshOptions(_ chosen: Instance, token: String) async {
        isRefreshingOptions = true
        let slow = Task { [weak self] in
            do { try await self?.clock.sleep(for: .seconds(8)) } catch { return }
            self?.isSlowToLoadOptions = true
        }
        defer {
            slow.cancel()
            isRefreshingOptions = false
            isSlowToLoadOptions = false
        }
        do {
            let options = try await fetchOptions(chosen, token)
            guard instance?.id == chosen.id else { return }
            isOffline = false
            let isSaved = context != nil
            var current =
                context
                ?? ShareContext(
                    instanceName: chosen.name, limits: ShareLimits(maxFiles: 8, maxFileBytes: 10_485_760),
                    updatedAt: clock.now())
            current.options = options
            current.updatedAt = clock.now()
            context = current
            if isSaved {
                do { try ShareContextStore.write(current, instance: chosen.id, files: files) } catch {
                    // The sheet still works from memory.
                }
            }
            rebuildForm()
        } catch {
            if case .network? = error as? APIFailure { isOffline = true }
        }
    }

    /// Retry after "Slow connection. Still loading your repositories."
    func retryOptions() async {
        guard let instance, let token else { return }
        await refreshOptions(instance, token: token)
    }

    private func rebuildForm() {
        let options = context?.options
        form = options.map {
            NewThreadForm(options: $0, saved: nil, lastUsedRepo: context?.lastRepo, linkRepo: nil)
        }
        if let model, options?.models.isEmpty == false { self.model = model }
    }

    // MARK: Choices

    func selectRepository(_ slug: String) {
        guard let options = context?.options else { return }
        form?.selectRepository(slug, options: options)
    }

    func selectMode(_ mode: NewThreadMode) { form?.mode = mode }

    func selectModel(_ value: String?) {
        model = value
        form?.selectModel(value, families: context?.options?.models ?? [])
    }

    // MARK: Send

    /// True: the sheet may close.
    func send() async -> Bool {
        guard canSend, let instance, let token, let draft else { return false }
        let key = sendKey
        guard
            let outbox = draft.outboxDraft(
                sendKey: key,
                attachmentPath: { name in
                    OutboxInbox.attachmentPath(instance: instance.id, item: key, fileName: name)
                })
        else { return false }
        phase = .saving
        didFailToSave = false
        do {
            try await sender.send(outbox, stagingFolder: stagingFolder, instance: instance.id, token: token)
            return true
        } catch {
            phase = .ready
            didFailToSave = true
            saveFailureCount += 1
            return false
        }
    }

    func cancel() {
        importTask?.cancel()
        importTask = nil
        progress = nil
        do { try files.remove(stagingFolder) } catch {
            // A leftover folder is removed by the app after an hour.
        }
    }
}
