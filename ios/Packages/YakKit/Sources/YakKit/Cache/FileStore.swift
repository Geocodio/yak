import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "FileStore").error("\(message, privacy: .public)")
    #endif
}

/// How a file is protected while the device is locked. Only iOS acts on it.
public enum FileProtection: String, Codable, Sendable {
    case untilFirstUnlock
    case complete
}

public enum FileStoreError: Error, Equatable, Sendable {
    /// The file exists but cannot be read now, for example a `.complete` file while the device is locked.
    case unreadable
    /// The path leaves the store: a `..` component or a leading `/`.
    case invalidPath
    /// The file holds more than the bound a bounded read allows.
    case tooLarge
}

/// Paths are relative to the store's root and use `/`.
public protocol FileStore: Sendable {
    /// Nil when the file is missing; `.unreadable` when it is protected while locked.
    func read(_ path: String) throws(FileStoreError) -> Data?
    /// Atomic: a reader sees the old file or the new one, never half of one.
    func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError)
    /// A file or a folder; no error when it is missing.
    func remove(_ path: String) throws(FileStoreError)
    /// Whether a file is there, without reading it and whatever its protection. The default reads the whole file, so a
    /// conformer that holds large files must override it.
    func exists(_ path: String) throws(FileStoreError) -> Bool
    /// Names, sorted; empty when the folder is missing.
    func list(_ folder: String) throws(FileStoreError) -> [String]
    /// Nil when missing; `.tooLarge` when the file holds more than `maximumBytes`. `DiskFileStore` never loads more than the bound plus one byte. The default implementation reads the whole file first, so a conformer that holds large files must override it.
    func read(_ path: String, maximumBytes: Int) throws(FileStoreError) -> Data?
    /// Adds to the end of the file, creating it with `protection` when missing. Unlike `write`, an append to an existing file is not atomic: a crash can leave a partly written record at the end. Callers write records a reader can recover from, for example a length prefix or lines that end in a newline.
    func append(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError)
    /// Replaces the destination; `.unreadable` when the source is missing. The default rewrites the destination with `.untilFirstUnlock`, so a conformer that stores `.complete` files must override it to keep their protection.
    func move(_ source: String, to destination: String) throws(FileStoreError)
}

extension FileStore {
    public func exists(_ path: String) throws(FileStoreError) -> Bool { try read(path) != nil }

    public func read(_ path: String, maximumBytes: Int) throws(FileStoreError) -> Data? {
        guard let data = try read(path) else { return nil }
        if data.count > max(0, maximumBytes) { throw .tooLarge }
        return data
    }

    public func append(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        let existing = try read(path) ?? Data()
        try write(existing + data, to: path, protection: protection)
    }

    public func move(_ source: String, to destination: String) throws(FileStoreError) {
        if try validatedComponents(source) == validatedComponents(destination) {
            guard try read(source) != nil else { throw .unreadable }
            return
        }
        guard let data = try read(source) else { throw .unreadable }
        try write(data, to: destination, protection: .untilFirstUnlock)
        try remove(source)
    }
}

private func validatedComponents(_ path: String) throws(FileStoreError) -> [String] {
    if path.hasPrefix("/") { throw .invalidPath }
    let components = path.split(separator: "/", omittingEmptySubsequences: true).map(String.init).filter { $0 != "." }
    if components.contains("..") { throw .invalidPath }
    return components
}

/// Files under `root`: the app group container in the app.
public struct DiskFileStore: FileStore {
    private let root: URL
    /// Runs between the size check and the read of a bounded read; tests grow the file here.
    private let afterSizeCheck: (@Sendable () -> Void)?

    public init(root: URL) {
        self.root = root
        self.afterSizeCheck = nil
    }

    init(root: URL, afterSizeCheck: @escaping @Sendable () -> Void) {
        self.root = root
        self.afterSizeCheck = afterSizeCheck
    }

    /// The location of a path inside the root; `.invalidPath` when it leaves the root.
    public func fileURL(_ path: String) throws(FileStoreError) -> URL {
        try url(path)
    }

    private func url(_ path: String) throws(FileStoreError) -> URL {
        try validatedComponents(path).reduce(root) { $0.appendingPathComponent($1) }
    }

    public func read(_ path: String) throws(FileStoreError) -> Data? {
        let location = try url(path)
        guard FileManager.default.fileExists(atPath: location.path) else { return nil }
        do {
            return try Data(contentsOf: location)
        } catch {
            throw .unreadable
        }
    }

    public func read(_ path: String, maximumBytes: Int) throws(FileStoreError) -> Data? {
        let location = try url(path)
        guard FileManager.default.fileExists(atPath: location.path) else { return nil }
        let bound = max(0, maximumBytes)
        do {
            let size = try FileManager.default.attributesOfItem(atPath: location.path)[.size] as? NSNumber
            if maximumBytes < 0 || (size?.intValue ?? 0) > bound { throw FileStoreError.tooLarge }
            afterSizeCheck?()
            let handle = try FileHandle(forReadingFrom: location)
            defer { try? handle.close() }
            let data = try handle.read(upToCount: bound == Int.max ? bound : bound + 1) ?? Data()
            if data.count > bound { throw FileStoreError.tooLarge }
            return data
        } catch let error as FileStoreError {
            throw error
        } catch {
            throw .unreadable
        }
    }

    public func append(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        let location = try url(path)
        guard FileManager.default.fileExists(atPath: location.path) else {
            try write(data, to: path, protection: protection)
            return
        }
        do {
            let handle = try FileHandle(forWritingTo: location)
            defer { try? handle.close() }
            try handle.seekToEnd()
            try handle.write(contentsOf: data)
        } catch {
            throw .unreadable
        }
    }

    public func move(_ source: String, to destination: String) throws(FileStoreError) {
        let from = try url(source)
        let to = try url(destination)
        guard FileManager.default.fileExists(atPath: from.path) else { throw .unreadable }
        do {
            try FileManager.default.createDirectory(
                at: to.deletingLastPathComponent(), withIntermediateDirectories: true)
        } catch {
            throw .unreadable
        }
        // rename(2) replaces an existing destination in one atomic step on every platform.
        if rename(from.path, to.path) != 0 { throw .unreadable }
    }

    public func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        let location = try url(path)
        do {
            try FileManager.default.createDirectory(
                at: location.deletingLastPathComponent(), withIntermediateDirectories: true)
            var options: Data.WritingOptions = [.atomic]
            #if os(iOS)
                options.insert(
                    protection == .complete
                        ? .completeFileProtection : .completeFileProtectionUntilFirstUserAuthentication)
            #endif
            try data.write(to: location, options: options)
        } catch {
            throw .unreadable
        }
    }

    public func remove(_ path: String) throws(FileStoreError) {
        let location = try url(path)
        guard FileManager.default.fileExists(atPath: location.path) else { return }
        do {
            try FileManager.default.removeItem(at: location)
        } catch {
            throw .unreadable
        }
    }

    public func exists(_ path: String) throws(FileStoreError) -> Bool {
        var isFolder: ObjCBool = false
        return FileManager.default.fileExists(atPath: try url(path).path, isDirectory: &isFolder) && !isFolder.boolValue
    }

    public func list(_ folder: String) throws(FileStoreError) -> [String] {
        let location = try url(folder)
        guard FileManager.default.fileExists(atPath: location.path) else { return [] }
        do {
            return try FileManager.default.contentsOfDirectory(atPath: location.path).sorted()
        } catch {
            throw .unreadable
        }
    }
}

/// Files in memory, for tests and previews.
public final class InMemoryFileStore: FileStore, @unchecked Sendable {
    private let lock = NSLock()
    private var files: [String: (data: Data, protection: FileProtection)] = [:]
    private var locked = false

    public init() {}

    /// While true, reading, creating, writing or appending a `.complete` file throws `.unreadable`, as on a locked device.
    public var isLocked: Bool {
        get { lock.withLock { locked } }
        set { lock.withLock { locked = newValue } }
    }

    public func protection(of path: String) -> FileProtection? {
        let key: String
        do { key = try validatedComponents(path).joined(separator: "/") } catch {
            logFailure("protection lookup for an invalid path: \(type(of: error))")
            return nil
        }
        return lock.withLock { files[key]?.protection }
    }

    public func read(_ path: String) throws(FileStoreError) -> Data? {
        let key = try validatedComponents(path).joined(separator: "/")
        let (file, isLockedNow) = lock.withLock { (files[key], locked) }
        guard let file else { return nil }
        if isLockedNow && file.protection == .complete { throw .unreadable }
        return file.data
    }

    public func exists(_ path: String) throws(FileStoreError) -> Bool {
        let key = try validatedComponents(path).joined(separator: "/")
        return lock.withLock { files[key] != nil }
    }

    public func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        let key = try validatedComponents(path).joined(separator: "/")
        let isBlocked = lock.withLock { () -> Bool in
            if locked, protection == .complete || files[key]?.protection == .complete { return true }
            files[key] = (data, protection)
            return false
        }
        if isBlocked { throw .unreadable }
    }

    public func append(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        let key = try validatedComponents(path).joined(separator: "/")
        let isBlocked = lock.withLock { () -> Bool in
            if locked, protection == .complete || files[key]?.protection == .complete { return true }
            files[key] = ((files[key]?.data ?? Data()) + data, files[key]?.protection ?? protection)
            return false
        }
        if isBlocked { throw .unreadable }
    }

    public func move(_ source: String, to destination: String) throws(FileStoreError) {
        let from = try validatedComponents(source).joined(separator: "/")
        let to = try validatedComponents(destination).joined(separator: "/")
        let found = lock.withLock { () -> Bool in
            guard let file = files[from] else { return false }
            files[to] = file
            if from != to { files[from] = nil }
            return true
        }
        if !found { throw .unreadable }
    }

    public func remove(_ path: String) throws(FileStoreError) {
        let key = try validatedComponents(path).joined(separator: "/")
        lock.withLock {
            files[key] = nil
            for existing in files.keys where existing.hasPrefix(key + "/") { files[existing] = nil }
        }
    }

    public func list(_ folder: String) throws(FileStoreError) -> [String] {
        let prefix = try validatedComponents(folder).joined(separator: "/")
        let start = prefix.isEmpty ? "" : prefix + "/"
        return lock.withLock {
            Set(
                files.keys.filter { $0.hasPrefix(start) }
                    .compactMap { $0.dropFirst(start.count).split(separator: "/").first.map(String.init) }
            ).sorted()
        }
    }
}
