import Foundation

public enum FileDocumentPhase: Equatable, Sendable {
    case loading(isSlow: Bool)
    case text(FileContent)
    case binary(FileContent)
    case tooLarge(FileContent)
    case notFound
    case unavailable(WorkspaceAvailability)
    case failed(ThreadProblem)
}

public enum EditPhase: Equatable, Sendable {
    case notEditing
    case editing
    case saving
    /// Shown for a moment by the view, then editing ends.
    case saved(working: Bool)
    /// Nil: the file was deleted meanwhile.
    case conflict(theirs: FileBody?)
    case saveFailed(SaveProblem)
}

public enum SaveProblem: Equatable, Sendable {
    case offline, threadClosed, forbidden
    /// Over `FileEditDraft.maximumBytes` before sending, or a 422 with `details.errors.content`.
    case tooLarge
    case unavailable(WorkspaceAvailability)
    /// A 422 with `details.errors.path`, or without either key.
    case invalid(message: String)
    /// An empty message means no server text: the view shows its own catalog copy.
    case server(message: String)
}

public struct FileDocumentSnapshot: Equatable, Sendable {
    public var path: String
    public var phase: FileDocumentPhase
    public var edit: EditPhase
    /// The person's text while editing.
    public var text: String?
    public var isDirty: Bool
    /// The text came from a draft after a relaunch.
    public var restoredDraft: Bool
    /// A quiet reload found a new version while the person edits.
    public var changedOnServer: Bool
    public var canEdit: Bool

    public init(
        path: String, phase: FileDocumentPhase = .loading(isSlow: false), edit: EditPhase = .notEditing,
        text: String? = nil, isDirty: Bool = false, restoredDraft: Bool = false, changedOnServer: Bool = false,
        canEdit: Bool = false
    ) {
        self.path = path
        self.phase = phase
        self.edit = edit
        self.text = text
        self.isDirty = isDirty
        self.restoredDraft = restoredDraft
        self.changedOnServer = changedOnServer
        self.canEdit = canEdit
    }
}

/// One open file: its content, the person's edit, the save rules and the draft that survives a relaunch.
public actor FileDocument {
    private enum Fetched {
        case content(FileContent)
        case notFound
        case unavailable(WorkspaceAvailability)
        case failed(ThreadProblem)
        /// Cancelled, closed, or a quiet request that met an unavailable sandbox.
        case ignored
    }

    private static let draftDelay: Duration = .seconds(1)

    private let threadID: String
    private let scope: FileScope
    private let path: String
    private let client: APIClient
    private let cache: Cache
    private let clock: any YakClock
    private let timings: FileTimings
    private let broadcast: Broadcast<FileDocumentSnapshot>

    private var phase: FileDocumentPhase = .loading(isSlow: false)
    private var loaded: FileContent?
    private var edit: EditPhase = .notEditing
    private var text: String?
    /// The hash the person's text is based on; empty for a file that does not exist.
    private var baseSHA256 = ""
    private var restoredDraft = false
    private var changedOnServer = false
    private var hasCheckedDraft = false
    private var isLoading = false
    private var isClosed = false
    private var draftTimer: Task<Void, Never>?
    private var slowTimer: Task<Void, Never>?
    private var requests: [Int: @Sendable () -> Void] = [:]
    private var nextRequestID = 0

    public init(
        threadID: String, scope: FileScope, path: String, client: APIClient, cache: Cache, clock: any YakClock,
        timings: FileTimings = .standard
    ) {
        self.threadID = threadID
        self.scope = scope
        self.path = path
        self.client = client
        self.cache = cache
        self.clock = clock
        self.timings = timings
        broadcast = Broadcast(FileDocumentSnapshot(path: path))
    }

    public func snapshots() -> AsyncStream<FileDocumentSnapshot> { broadcast.stream() }

    // MARK: Loading

    public func load() async {
        guard !isClosed, !isLoading else { return }
        isLoading = true
        defer { isLoading = false }
        if loaded == nil {
            phase = .loading(isSlow: false)
            publish()
            startSlowTimer()
        }
        let fetched = await track { await $0.fetch(quiet: false) }
        slowTimer?.cancel()
        slowTimer = nil
        guard !isClosed else { return }
        switch fetched {
        case .content(let content):
            apply(content)
            await resumeDraft(for: content)
        case .notFound:
            loaded = nil
            phase = .notFound
            await resumeDraft(for: nil)
        case .unavailable(let availability):
            if loaded == nil { phase = .unavailable(availability) }
        case .failed(let problem):
            if loaded == nil { phase = .failed(problem) }
        case .ignored:
            break
        }
        publish()
    }

    public func reloadIfUnchanged() async {
        guard !isClosed, !isLoading, edit != .saving else { return }
        let fetched = await track { await $0.fetch(quiet: true) }
        guard !isClosed, edit != .saving else { return }
        let isEditing = edit != .notEditing
        switch fetched {
        case .content(let content):
            if isEditing {
                if content.sha256 != baseSHA256 { changedOnServer = true }
            } else if loaded?.sha256 != content.sha256 || !isShowingContent {
                apply(content)
            }
        case .notFound:
            if isEditing {
                if !baseSHA256.isEmpty { changedOnServer = true }
            } else {
                loaded = nil
                phase = .notFound
            }
        case .unavailable, .failed, .ignored:
            break
        }
        publish()
    }

    private var isShowingContent: Bool {
        switch phase {
        case .text, .binary, .tooLarge: true
        default: false
        }
    }

    private func apply(_ content: FileContent) {
        loaded = content
        if content.isBinary {
            phase = .binary(content)
        } else if content.isTooLarge {
            phase = .tooLarge(content)
        } else {
            phase = .text(content)
        }
    }

    /// A stored draft resumes editing once per document. A draft based on another version opens the compare.
    private func resumeDraft(for content: FileContent?) async {
        guard !hasCheckedDraft, edit == .notEditing else { return }
        hasCheckedDraft = true
        guard let draft = await cache.fileEditDraft(thread: threadID, scope: scope, path: path) else { return }
        if let content {
            guard case .text = phase, content.canEdit else { return }
            text = draft.text
            baseSHA256 = draft.baseSHA256
            restoredDraft = true
            edit = draft.baseSHA256 == content.sha256 ? .editing : .conflict(theirs: content.body)
        } else {
            text = draft.text
            baseSHA256 = draft.baseSHA256
            restoredDraft = true
            edit = draft.baseSHA256.isEmpty ? .editing : .conflict(theirs: nil)
        }
    }

    private func fetch(quiet: Bool) async -> Fetched {
        var wakeStart: Date?
        var restoreStart: Date?
        while true {
            let failure: APIFailure
            do {
                return .content(try await client.send(.fileContent(threadID, scope: scope, path: path)))
            } catch {
                failure = error
            }
            guard !isClosed, !Task.isCancelled else { return .ignored }
            switch failure {
            case .network(.cancelled):
                return .ignored
            case .server(let error):
                if let unavailable = SandboxUnavailable(error) {
                    if quiet { return .ignored }
                    let now = clock.now()
                    guard unavailable.state == .waking else {
                        return .unavailable(FileTree.availability(for: unavailable))
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
                        return .unavailable(unavailable.restoring ? .stillRestoring : .stillWaking)
                    }
                    if loaded == nil {
                        phase = .unavailable(
                            .waking(
                                waitingForSlot: unavailable.waitingForSlot, restoring: unavailable.restoring,
                                since: since))
                        publish()
                    }
                    do { try await clock.sleep(for: timings.wakePoll) } catch { return .ignored }
                    guard !isClosed, !Task.isCancelled else { return .ignored }
                    continue
                }
                if error.status == 404 { return .notFound }
                return .failed(ThreadProblem(failure))
            default:
                return .failed(ThreadProblem(failure))
            }
        }
    }

    private func startSlowTimer() {
        let threshold = timings.slowAfter
        slowTimer = Task {
            do { try await clock.sleep(for: threshold) } catch { return }
            self.markSlow()
        }
    }

    private func markSlow() {
        guard !isClosed, case .loading = phase else { return }
        phase = .loading(isSlow: true)
        publish()
    }

    // MARK: Editing

    /// False unless the file is a text the person may change.
    public func beginEditing() async -> Bool {
        guard !isClosed, case .text(let content) = phase, content.canEdit else { return false }
        switch edit {
        case .notEditing, .saved:
            text = content.content ?? ""
            baseSHA256 = content.sha256
            restoredDraft = false
            changedOnServer = false
            edit = .editing
            publish()
            return true
        case .saving:
            return false
        default:
            return true
        }
    }

    /// A draft is written one second after the typing stops.
    public func updateText(_ newText: String) async {
        guard !isClosed else { return }
        switch edit {
        case .notEditing: return
        case .saving:
            // Text typed while a save is in flight is kept; the saved answer does not replace it.
            text = newText
            publish()
            return
        case .saveFailed, .saved: edit = .editing
        case .editing, .conflict: break
        }
        text = newText
        publish()
        draftTimer?.cancel()
        draftTimer = Task {
            do { try await clock.sleep(for: Self.draftDelay) } catch { return }
            await self.writeDraft()
        }
    }

    public func save() async {
        guard !isClosed else { return }
        switch edit {
        case .editing, .saveFailed: break
        default: return
        }
        await send(overwrite: false, base: baseSHA256)
    }

    /// From the conflict: saves over the other version, or creates the file again when it was deleted.
    public func keepMine() async {
        guard !isClosed, case .conflict(let theirs) = edit else { return }
        await send(overwrite: true, base: theirs?.sha256 ?? "")
    }

    /// From the conflict: drops the person's text and opens the other version.
    public func useTheirs() async {
        guard !isClosed, case .conflict(let theirs) = edit else { return }
        if let theirs {
            apply(
                FileContent(
                    path: theirs.path, sha256: theirs.sha256, size: theirs.size, isBinary: theirs.isBinary,
                    isTooLarge: theirs.isTooLarge, content: theirs.content, headContent: theirs.headContent,
                    language: theirs.language, canEdit: loaded?.canEdit ?? true))
        } else {
            loaded = nil
            phase = .notFound
        }
        await endEditing()
    }

    public func discardChanges() async {
        guard !isClosed, edit != .saving else { return }
        await endEditing()
    }

    public func flushDraft() async {
        draftTimer?.cancel()
        draftTimer = nil
        await writeDraft()
    }

    public func close() async {
        guard !isClosed else { return }
        draftTimer?.cancel()
        await writeDraft()
        isClosed = true
        draftTimer = nil
        slowTimer?.cancel()
        slowTimer = nil
        for (_, cancel) in requests { cancel() }
        requests = [:]
        broadcast.finish()
    }

    private func endEditing() async {
        draftTimer?.cancel()
        draftTimer = nil
        edit = .notEditing
        text = nil
        restoredDraft = false
        changedOnServer = false
        await cache.removeFileEditDraft(thread: threadID, scope: scope, path: path)
        publish()
    }

    // MARK: Saving

    private func send(overwrite: Bool, base: String) async {
        guard let sent = text else { return }
        if sent.utf8.count > FileEditDraft.maximumBytes {
            edit = .saveFailed(.tooLarge)
            publish()
            return
        }
        draftTimer?.cancel()
        draftTimer = nil
        await writeDraft()
        edit = .saving
        publish()
        let result = await track { document in
            await document.sendRequest(text: sent, base: base, overwrite: overwrite)
        }
        guard !isClosed else { return }
        guard let result else {
            edit = .editing
            publish()
            return
        }
        switch result {
        case .success(let saved):
            await applySaved(saved.file, working: saved.working, sent: sent)
        case .failure(let failure):
            await handleSaveFailure(failure, sent: sent)
        }
        publish()
    }

    private func sendRequest(text: String, base: String, overwrite: Bool) async -> Result<FileSaveResult, APIFailure>? {
        do {
            return .success(
                try await client.send(
                    .saveFile(
                        threadID, scope: scope, path: path, content: text, baseSHA256: base, overwrite: overwrite)))
        } catch {
            if case .network(.cancelled) = error { return nil }
            return .failure(error)
        }
    }

    private func applySaved(_ file: FileBody, working: Bool, sent: String) async {
        let content = FileContent(
            path: file.path, sha256: file.sha256, size: file.size, isBinary: file.isBinary,
            isTooLarge: file.isTooLarge, content: file.content, headContent: file.headContent,
            language: file.language, canEdit: true)
        apply(content)
        baseSHA256 = file.sha256
        restoredDraft = false
        changedOnServer = false
        await cache.removeFileEditDraft(thread: threadID, scope: scope, path: path)
        if let typedSince = text, typedSince != sent {
            edit = .editing
            await writeDraft()
        } else {
            text = file.content ?? text
            edit = .saved(working: working)
        }
    }

    private func handleSaveFailure(_ failure: APIFailure, sent: String) async {
        guard case .server(let error) = failure else {
            if case .network = failure {
                edit = .saveFailed(.offline)
            } else {
                edit = .saveFailed(.server(message: ""))
            }
            return
        }
        if let conflict = FileConflict(error) {
            // A save whose answer was lost and is sent again meets its own text: it is saved.
            if let file = conflict.file, file.content == sent {
                await applySaved(file, working: false, sent: sent)
            } else {
                edit = .conflict(theirs: conflict.file)
            }
            return
        }
        if let unavailable = SandboxUnavailable(error) {
            let availability: WorkspaceAvailability =
                unavailable.state == .waking
                ? .waking(
                    waitingForSlot: unavailable.waitingForSlot, restoring: unavailable.restoring, since: clock.now())
                : FileTree.availability(for: unavailable)
            edit = .saveFailed(.unavailable(availability))
            return
        }
        switch (error.status, error.code) {
        case (_, "thread_closed"): edit = .saveFailed(.threadClosed)
        case (403, _), (_, "forbidden"): edit = .saveFailed(.forbidden)
        case (422, _):
            let errors = error.details["errors"]
            if errors?["content"] != nil {
                edit = .saveFailed(.tooLarge)
            } else {
                edit = .saveFailed(.invalid(message: Self.firstMessage(errors?["path"]) ?? error.message))
            }
        default:
            edit = .saveFailed(.server(message: error.message))
        }
    }

    private static func firstMessage(_ value: JSONValue?) -> String? {
        switch value {
        case .array(let messages)?: messages.first?.stringValue
        case .string(let text)?: text
        default: nil
        }
    }

    // MARK: Drafts

    /// Keeps the person's text on disk while it differs from the file; removes the draft when it does not.
    private func writeDraft() async {
        guard let text, edit != .notEditing else { return }
        if case .saved = edit { return }
        if text == (loaded?.content ?? "") {
            await cache.removeFileEditDraft(thread: threadID, scope: scope, path: path)
        } else {
            await cache.saveFileEditDraft(
                FileEditDraft(
                    scope: scope, path: path, baseSHA256: baseSHA256, text: text, savedAt: clock.now()),
                thread: threadID)
        }
    }

    // MARK: Requests and snapshots

    /// Runs a request as a task the document cancels when it closes.
    private func track<Value: Sendable>(_ operation: @escaping @Sendable (FileDocument) async -> Value) async -> Value {
        nextRequestID += 1
        let id = nextRequestID
        let task = Task { await operation(self) }
        requests[id] = { task.cancel() }
        let value = await withTaskCancellationHandler {
            await task.value
        } onCancel: {
            task.cancel()
        }
        requests[id] = nil
        return value
    }

    private func publish() {
        let isDirty = text != nil && edit != .notEditing && text != (loaded?.content ?? "")
        broadcast.send(
            FileDocumentSnapshot(
                path: path, phase: phase, edit: edit, text: text, isDirty: isDirty, restoredDraft: restoredDraft,
                changedOnServer: changedOnServer, canEdit: loaded?.canEdit ?? false))
    }
}

extension Duration {
    fileprivate var seconds: TimeInterval {
        let parts = components
        return TimeInterval(parts.seconds) + TimeInterval(parts.attoseconds) / 1e18
    }
}
