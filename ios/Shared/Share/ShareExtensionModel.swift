import Foundation
import Observation
import YakKit

/// The share sheet's state: what was shared, where it goes and whether it can be sent.
@MainActor @Observable
final class ShareModel {
    enum Phase: Equatable {
        case noInstance
        case signedOut(String)
        case loading, ready, saving, failed
    }

    private(set) var phase: Phase = .loading
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

    private let instances: InstanceStore
    private let tokens: any TokenStore
    private let files: DiskFileStore
    private let sender: any ShareSending
    private let clock: any YakClock
    private let fetchOptions: @Sendable (Instance, String) async throws -> NewThreadOptions
    private var token: String?
    private let stagingFolder: String
    private var importTask: Task<Void, Never>?

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
        var found = await instances.active()
        if found == nil { found = await instances.all().first }
        guard let chosen = found else {
            phase = .noInstance
            return
        }
        instance = chosen
        guard await instances.state(of: chosen.id) == .ready, let token = try? tokens.token(for: chosen.id),
            !token.isEmpty
        else {
            phase = .signedOut(chosen.name)
            return
        }
        self.token = token
        context = ShareContextStore.read(instance: chosen.id, files: files)
        rebuildForm()
        phase = .ready
        let refresh = Task { await refreshOptions(chosen, token: token) }
        await importItems(providers)
        await refresh.value
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
            isOffline = false
            if var current = context {
                current.options = options
                current.updatedAt = clock.now()
                context = current
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
        let key = UUID()
        guard
            let outbox = draft.outboxDraft(
                sendKey: key,
                attachmentPath: { name in
                    OutboxInbox.attachmentPath(instance: instance.id, item: key, fileName: name)
                })
        else { return false }
        phase = .saving
        do {
            try await sender.send(outbox, stagingFolder: stagingFolder, instance: instance.id, token: token)
            return true
        } catch {
            phase = .failed
            return false
        }
    }

    /// Back to the ready sheet after a failed send, with everything kept.
    func dismissFailure() { if phase == .failed { phase = .ready } }

    func cancel() {
        importTask?.cancel()
        importTask = nil
        progress = nil
        do { try files.remove(stagingFolder) } catch {
            // A leftover folder is removed by the app after an hour.
        }
    }
}
