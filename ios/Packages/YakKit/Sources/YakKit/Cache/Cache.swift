import Foundation

#if canImport(os)
    import os
#endif

/// Logs the type of a failure, never stored content. Linux has no `os.Logger`, so it is a no-op there.
private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "Cache").error("\(message, privacy: .public)")
    #endif
}

public struct CachedThread: Codable, Equatable, Sendable {
    public var show: ThreadShow
    /// Stored events only, ascending, at most 2000.
    public var events: [ThreadEvent]
    public var lastSequence: Int
    /// The `after` of the oldest page held; 0 means the whole history is held.
    public var firstLoadedAfter: Int

    public init(show: ThreadShow, events: [ThreadEvent], lastSequence: Int, firstLoadedAfter: Int) {
        self.show = show
        self.events = events
        self.lastSequence = lastSequence
        self.firstLoadedAfter = firstLoadedAfter
    }
}

private struct StoredEvents: Codable {
    var events: [ThreadEvent]
    var lastSequence: Int
    var firstLoadedAfter: Int
}

private struct LastOpened: Codable {
    var id: String

    /// Letters, digits, dash and underscore, at most 64: nothing that could leave the thread folder.
    static func isPlainID(_ id: String) -> Bool {
        !id.isEmpty && id.utf8.count <= 64
            && id.utf8.allSatisfy {
                ($0 >= 48 && $0 <= 57) || ($0 >= 65 && $0 <= 90) || ($0 >= 97 && $0 <= 122) || $0 == 45 || $0 == 95
            }
    }
}

/// One instance's copies on disk. Best effort: a failed write or read is logged and the app goes on without it.
public actor Cache {
    public static let maximumEventsPerThread = 2000
    public static let maximumThreads = 50
    /// The largest widget snapshot file that is read; a larger one is removed.
    public static let maximumWidgetSnapshotBytes = 256 * 1024

    /// The instance's cache folder. A feature's `Cache+<Feature>.swift` stores under it, so `eraseAll()` removes it.
    let root: String
    let files: any FileStore
    private let clock: any YakClock
    private let encoder = JSONEncoder()
    private let decoder = JSONDecoder()

    public init(instance: UUID, files: any FileStore, clock: any YakClock) {
        root = InstanceFolders.cache(instance)
        self.files = files
        self.clock = clock
    }

    // MARK: Paths

    private var indexPath: String { "\(root)/index.json" }
    private var widgetPath: String { "\(root)/widget.json" }
    private var lastOpenedPath: String { "\(root)/last-opened.json" }
    private func listPath(_ filter: ThreadListFilter) -> String { "\(root)/lists/\(filter.rawValue).json" }
    func folder(_ id: String) -> String { "\(root)/threads/\(Endpoint<ThreadShow>.segment(id))" }

    // MARK: Reading and writing one file

    /// Nil when missing, unreadable or not decodable. A file that does not decode, or that holds more than
    /// `maximumBytes` (never loaded past the bound), is removed.
    func load<Value: Decodable>(_ type: Value.Type, at path: String, maximumBytes: Int? = nil) -> Value? {
        let data: Data
        do {
            let read = try maximumBytes.map { try files.read(path, maximumBytes: $0) } ?? files.read(path)
            guard let found = read else { return nil }
            data = found
        } catch FileStoreError.tooLarge {
            logFailure("cache file too large: \(type)")
            do { try files.remove(path) } catch { logFailure("cache remove failed: \(Swift.type(of: error))") }
            return nil
        } catch {
            logFailure("cache read failed: \(Swift.type(of: error))")
            return nil
        }
        do {
            return try decoder.decode(type, from: data)
        } catch {
            logFailure("cache file not decodable: \(type)")
            do { try files.remove(path) } catch { logFailure("cache remove failed: \(Swift.type(of: error))") }
            return nil
        }
    }

    func store<Value: Encodable>(_ value: Value, at path: String, protection: FileProtection) {
        do {
            try files.write(try encoder.encode(value), to: path, protection: protection)
        } catch {
            logFailure("cache write failed: \(Swift.type(of: error))")
        }
    }

    func delete(_ path: String) {
        do { try files.remove(path) } catch { logFailure("cache remove failed: \(Swift.type(of: error))") }
    }

    // MARK: Thread list

    public func threadList(_ filter: ThreadListFilter) -> ThreadList? {
        load(ThreadList.self, at: listPath(filter))
    }

    /// `savedAt` is when the server answered; the offline line shows it.
    public func saveThreadList(_ list: ThreadList, filter: ThreadListFilter, savedAt: Date? = nil) {
        store(list, at: listPath(filter), protection: .untilFirstUnlock)
        if let savedAt { store(SavedAt(date: savedAt), at: savedAtPath(filter), protection: .untilFirstUnlock) }
    }

    /// When the stored list of the tab was saved; nil for a list saved before the time was kept.
    public func threadListSavedAt(_ filter: ThreadListFilter) -> Date? {
        load(SavedAt.self, at: savedAtPath(filter))?.date
    }

    private struct SavedAt: Codable { let date: Date }

    private func savedAtPath(_ filter: ThreadListFilter) -> String { "\(root)/lists/\(filter.rawValue)-saved-at.json" }

    // MARK: Thread filters

    private var threadFiltersPath: String { "\(root)/thread-filters.json" }

    /// The filter words the server listed the last time it answered `me`; nil when it listed none.
    public func threadFilters() -> [String]? {
        load([String].self, at: threadFiltersPath)
    }

    public func saveThreadFilters(_ names: [String]?) {
        guard let names else { return delete(threadFiltersPath) }
        store(names, at: threadFiltersPath, protection: .untilFirstUnlock)
    }

    // MARK: Threads

    public func thread(_ id: String) -> CachedThread? {
        let base = folder(id)
        guard let show = load(ThreadShow.self, at: "\(base)/show.json"),
            let stored = load(StoredEvents.self, at: "\(base)/events.json")
        else { return nil }
        return CachedThread(
            show: show, events: stored.events, lastSequence: stored.lastSequence,
            firstLoadedAfter: stored.firstLoadedAfter)
    }

    public func saveThread(_ id: String, _ thread: CachedThread) {
        var events = thread.events.filter(\.isStored).sorted { $0.sequence < $1.sequence }
        var firstLoadedAfter = thread.firstLoadedAfter
        if events.count > Self.maximumEventsPerThread {
            events.removeFirst(events.count - Self.maximumEventsPerThread)
            firstLoadedAfter = max(firstLoadedAfter, events[0].sequence - 1)
        }
        let base = folder(id)
        store(thread.show, at: "\(base)/show.json", protection: .complete)
        store(
            StoredEvents(events: events, lastSequence: thread.lastSequence, firstLoadedAfter: firstLoadedAfter),
            at: "\(base)/events.json", protection: .complete)
        touch(id, addingIfMissing: true)
    }

    /// Records that a thread with a saved copy was opened now.
    /// The id also goes to a small file of its own, so a widget or an intent can read it without the index.
    public func markOpened(_ id: String) {
        touch(id, addingIfMissing: false)
        store(LastOpened(id: id), at: lastOpenedPath, protection: .untilFirstUnlock)
    }

    /// The thread opened most recently, which is not always the newest index entry: saving a thread touches it too.
    /// Nil when none, or when the file is larger than 1 KB or holds an id that is not a plain id.
    public func lastOpenedThreadID() -> String? {
        guard let id = load(LastOpened.self, at: lastOpenedPath, maximumBytes: 1024)?.id, LastOpened.isPlainID(id)
        else { return nil }
        return id
    }

    public func removeThread(_ id: String) {
        delete(folder(id))
        var index = loadIndex()
        if index.removeValue(forKey: id) != nil { saveIndex(index) }
        if lastOpenedThreadID() == id { delete(lastOpenedPath) }
    }

    // MARK: Data of removed features

    private var legacyMarkerPath: String { "\(root)/legacy-chat-data-removed" }

    /// Deletes what the native chat left in each thread's folder: the question answer drafts (`question.json`) and the
    /// attachment thumbnails (`attachments/`). It runs once per instance; a marker file records that it did.
    public func removeRemovedFeatureData() {
        do {
            if try files.exists(legacyMarkerPath) { return }
            for name in try files.list("\(root)/threads") {
                let folder = "\(root)/threads/\(name)"
                delete("\(folder)/question.json")
                delete("\(folder)/attachments")
            }
            try files.write(Data(), to: legacyMarkerPath, protection: .untilFirstUnlock)
        } catch {
            logFailure("legacy data removal failed: \(Swift.type(of: error))")
        }
    }

    // MARK: Composer drafts

    private func composerPath(_ key: ComposerDraftKey) -> String {
        switch key {
        case .thread(let id): "\(folder(id))/composer.json"
        case .newThread: "\(root)/new-thread.json"
        }
    }

    public func composerDraft(_ key: ComposerDraftKey) -> ComposerDraft? {
        load(ComposerDraft.self, at: composerPath(key))
    }

    /// Nil or an empty draft removes the file.
    public func saveComposerDraft(_ draft: ComposerDraft?, for key: ComposerDraftKey) {
        if let draft, !draft.isEmpty {
            store(draft, at: composerPath(key), protection: .complete)
        } else {
            delete(composerPath(key))
        }
    }

    /// The folder names (outbox item ids) that every saved composer draft keeps attachment files in. Nil when any
    /// listing or draft cannot be read for a reason other than being missing, for example a `.complete` draft on a
    /// locked device: the caller cannot account for every draft, so it must not treat any folder as unheld.
    public func composerAttachmentFolders() -> Set<String>? {
        let threads: [String]
        do { threads = try files.list("\(root)/threads") } catch {
            logFailure("draft folders not listed: \(Swift.type(of: error))")
            return nil
        }
        var names: Set<String> = []
        for path in threads.map({ "\(root)/threads/\($0)/composer.json" }) + [composerPath(.newThread)] {
            let data: Data?
            do { data = try files.read(path) } catch {
                logFailure("draft not read: \(Swift.type(of: error))")
                return nil
            }
            guard let data else { continue }
            guard let draft = try? decoder.decode(ComposerDraft.self, from: data) else {
                logFailure("draft not decodable")
                return nil
            }
            if let key = draft.sendKey { names.insert(key.uuidString) }
            for attachment in draft.attachments {
                if let name = OutboxInbox.attachmentFolderName(of: attachment.path) { names.insert(name) }
            }
        }
        return names
    }

    // MARK: Widget snapshot

    public func widgetSnapshot() -> WidgetSnapshot? {
        load(WidgetSnapshot.self, at: widgetPath, maximumBytes: Self.maximumWidgetSnapshotBytes)
    }

    public func saveWidgetSnapshot(_ snapshot: WidgetSnapshot) {
        store(snapshot, at: widgetPath, protection: .untilFirstUnlock)
    }

    /// Removes every copy of this instance.
    public func eraseAll() {
        delete(root)
    }

    // MARK: Index

    private func loadIndex() -> [String: Date] {
        load([String: Date].self, at: indexPath) ?? [:]
    }

    private func saveIndex(_ index: [String: Date]) {
        store(index, at: indexPath, protection: .untilFirstUnlock)
    }

    func touch(_ id: String, addingIfMissing: Bool) {
        var index = loadIndex()
        guard addingIfMissing || index[id] != nil else { return }
        index[id] = clock.now()
        if index.count > Self.maximumThreads {
            let stale = index.filter { $0.key != id }
                .sorted { ($0.value, $0.key) < ($1.value, $1.key) }
                .prefix(index.count - Self.maximumThreads)
            for entry in stale {
                delete(folder(entry.key))
                index[entry.key] = nil
            }
        }
        saveIndex(index)
    }
}
