import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "OfflineCopyStore").error("\(message, privacy: .public)")
    #endif
}

public struct OfflineCopyLimits: Equatable, Sendable {
    /// An archive larger than this is not kept.
    public var maximumArchiveBytes: Int
    public var maximumThreads: Int
    public var maximumTotalBytes: Int

    public init(maximumArchiveBytes: Int, maximumThreads: Int, maximumTotalBytes: Int) {
        self.maximumArchiveBytes = maximumArchiveBytes
        self.maximumThreads = maximumThreads
        self.maximumTotalBytes = maximumTotalBytes
    }

    /// 8 MB per archive; 30 threads or 60 MB per instance.
    public static let standard = OfflineCopyLimits(
        maximumArchiveBytes: 8_000_000, maximumThreads: 30, maximumTotalBytes: 60_000_000)
}

public struct OfflineCopyEntry: Codable, Equatable, Sendable {
    public let threadID: String
    public let bytes: Int
    public let savedAt: Date
    /// Rises with every save and every read; the lowest is the least recently used.
    public var useOrder: Int
}

/// Which threads have an archive, how large each is, and which was used least recently.
public struct OfflineCopyIndex: Codable, Equatable, Sendable {
    public private(set) var entries: [OfflineCopyEntry] = []
    private var nextOrder = 0

    public init() {}

    public var totalBytes: Int { entries.reduce(0) { $0 + $1.bytes } }

    public func entry(for threadID: String) -> OfflineCopyEntry? { entries.first { $0.threadID == threadID } }

    /// Adds or replaces the thread's entry, then drops the least recently used others until the limits hold.
    /// Returns the threads dropped.
    public mutating func insert(
        threadID: String, bytes: Int, savedAt: Date, limits: OfflineCopyLimits
    ) -> [String] {
        entries.removeAll { $0.threadID == threadID }
        entries.append(OfflineCopyEntry(threadID: threadID, bytes: bytes, savedAt: savedAt, useOrder: advance()))
        var dropped: [String] = []
        while entries.count > limits.maximumThreads || totalBytes > limits.maximumTotalBytes,
            let oldest = entries.filter({ $0.threadID != threadID }).min(by: { $0.useOrder < $1.useOrder })
        {
            entries.removeAll { $0.threadID == oldest.threadID }
            dropped.append(oldest.threadID)
        }
        return dropped
    }

    public mutating func touch(_ threadID: String) {
        guard let position = entries.firstIndex(where: { $0.threadID == threadID }) else { return }
        entries[position].useOrder = advance()
    }

    @discardableResult public mutating func remove(_ threadID: String) -> Bool {
        let count = entries.count
        entries.removeAll { $0.threadID == threadID }
        return entries.count != count
    }

    public mutating func removeAll() { entries = [] }

    private mutating func advance() -> Int {
        defer { nextOrder += 1 }
        return nextOrder
    }
}

public enum OfflineCopySaveOutcome: Equatable, Sendable {
    case saved
    /// Over the size of one archive; any older copy of the thread is kept.
    case tooLarge
    /// Not a thread id, or the files could not be written.
    case notSaved
}

public struct OfflineCopy: Equatable, Sendable {
    public let archive: Data
    public let savedAt: Date
}

/// The web archives of one instance's threads, on a `FileStore`, kept within `OfflineCopyLimits`. Archives are
/// `.complete` files, so they are unreadable while the device is locked.
public actor OfflineCopyStore {
    private static let archiveSuffix = ".webarchive"

    private let folder: String
    private let files: any FileStore
    private let clock: any YakClock
    private let limits: OfflineCopyLimits
    private var index: OfflineCopyIndex?

    public init(
        instance: UUID, files: any FileStore, clock: any YakClock = SystemClock(),
        limits: OfflineCopyLimits = .standard
    ) {
        folder = InstanceFolders.offlineCopies(instance)
        self.files = files
        self.clock = clock
        self.limits = limits
    }

    /// Newest use first.
    public func entries() -> [OfflineCopyEntry] {
        (loadedIndex()?.entries ?? []).sorted { $0.useOrder > $1.useOrder }
    }

    public func save(_ archive: Data, thread threadID: String) -> OfflineCopySaveOutcome {
        guard ThreadIdentifier.isValid(threadID), var working = loadedIndex() else { return .notSaved }
        guard archive.count <= limits.maximumArchiveBytes else { return .tooLarge }
        let previous = working
        let dropped = working.insert(
            threadID: threadID, bytes: archive.count, savedAt: clock.now(), limits: limits)
        do {
            try files.write(archive, to: archivePath(threadID), protection: .complete)
            try writeIndex(working)
        } catch {
            logFailure("An offline copy could not be written: \(type(of: error))")
            // The old index still describes what is on disk, apart from the archive that may be half replaced.
            if previous.entry(for: threadID) == nil { try? files.remove(archivePath(threadID)) }
            return .notSaved
        }
        index = working
        for droppedID in dropped { removeArchive(droppedID) }
        return .saved
    }

    /// The thread's archive, which counts as a use; nil when there is none or it cannot be read now.
    public func copy(thread threadID: String) -> OfflineCopy? {
        guard ThreadIdentifier.isValid(threadID), var working = loadedIndex(),
            let entry = working.entry(for: threadID)
        else { return nil }
        let data: Data?
        do { data = try files.read(archivePath(threadID)) } catch {
            logFailure("An offline copy could not be read: \(type(of: error))")
            return nil
        }
        guard let data else {
            // The file is gone: the entry is stale.
            working.remove(threadID)
            if (try? writeIndex(working)) != nil { index = working }
            return nil
        }
        working.touch(threadID)
        if (try? writeIndex(working)) != nil { index = working }
        return OfflineCopy(archive: data, savedAt: entry.savedAt)
    }

    /// The thread became hidden or was removed.
    public func remove(thread threadID: String) {
        guard ThreadIdentifier.isValid(threadID) else { return }
        removeArchive(threadID)
        guard var working = loadedIndex(), working.remove(threadID) else { return }
        if (try? writeIndex(working)) != nil { index = working }
    }

    /// Sign-out, instance removal, a revoked device, or the person's choice in Settings.
    public func removeAll() {
        do { try files.remove(folder) } catch {
            logFailure("The offline copies could not be removed: \(type(of: error))")
        }
        index = OfflineCopyIndex()
    }

    // MARK: Files

    private func archivePath(_ threadID: String) -> String { "\(folder)/\(threadID)\(Self.archiveSuffix)" }

    private func removeArchive(_ threadID: String) {
        do { try files.remove(archivePath(threadID)) } catch {
            logFailure("An offline copy could not be removed: \(type(of: error))")
        }
    }

    private func writeIndex(_ value: OfflineCopyIndex) throws(FileStoreError) {
        guard let data = try? JSONEncoder().encode(value) else { throw .unreadable }
        try files.write(data, to: "\(folder)/index.json", protection: .complete)
    }

    /// The index, read once. An unreadable file (a locked device) gives nil now and is tried again later; a file that
    /// is not an index is replaced by an empty one, and its archives are removed with the folder.
    private func loadedIndex() -> OfflineCopyIndex? {
        if let index { return index }
        let data: Data?
        do { data = try files.read("\(folder)/index.json", maximumBytes: 1_000_000) } catch {
            logFailure("The offline copy index could not be read: \(type(of: error))")
            return nil
        }
        var loaded = OfflineCopyIndex()
        if let data {
            if let decoded = try? JSONDecoder().decode(OfflineCopyIndex.self, from: data) {
                loaded = decoded
            } else {
                try? files.remove(folder)
            }
        }
        index = loaded
        return loaded
    }
}
