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

/// One instance's copies on disk. Best effort: a failed write or read is logged and the app goes on without it.
public actor Cache {
    public static let maximumEventsPerThread = 2000
    public static let maximumThreads = 50

    /// The instance's cache folder. A feature's `Cache+<Feature>.swift` stores under it, so `eraseAll()` removes it.
    let root: String
    private let files: any FileStore
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
    private func listPath(_ filter: ThreadListFilter) -> String { "\(root)/lists/\(filter.rawValue).json" }
    func folder(_ id: String) -> String { "\(root)/threads/\(Endpoint<ThreadShow>.segment(id))" }

    // MARK: Reading and writing one file

    /// Nil when missing, unreadable or not decodable. A file that does not decode is removed.
    func load<Value: Decodable>(_ type: Value.Type, at path: String) -> Value? {
        let data: Data
        do {
            guard let found = try files.read(path) else { return nil }
            data = found
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

    public func saveThreadList(_ list: ThreadList, filter: ThreadListFilter) {
        store(list, at: listPath(filter), protection: .untilFirstUnlock)
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
    public func markOpened(_ id: String) {
        touch(id, addingIfMissing: false)
    }

    public func removeThread(_ id: String) {
        delete(folder(id))
        var index = loadIndex()
        if index.removeValue(forKey: id) != nil { saveIndex(index) }
    }

    // MARK: Question drafts

    public func questionDraft(thread id: String) -> QuestionDraft? {
        load(QuestionDraft.self, at: "\(folder(id))/question.json")
    }

    public func saveQuestionDraft(_ draft: QuestionDraft?, thread id: String) {
        let path = "\(folder(id))/question.json"
        if let draft {
            store(draft, at: path, protection: .complete)
        } else {
            delete(path)
        }
    }

    /// Deletes the saved draft only when its round key passes `shouldRemove`, in one step.
    public func removeQuestionDraft(thread id: String, where shouldRemove: @Sendable (String) -> Bool) {
        guard let draft = questionDraft(thread: id), shouldRemove(draft.roundKey) else { return }
        delete("\(folder(id))/question.json")
    }

    // MARK: Widget snapshot

    public func widgetSnapshot() -> WidgetSnapshot? {
        load(WidgetSnapshot.self, at: widgetPath)
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
