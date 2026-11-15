import Foundation

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
}

/// Paths are relative to the store's root and use `/`.
public protocol FileStore: Sendable {
    /// Nil when the file is missing; `.unreadable` when it is protected while locked.
    func read(_ path: String) throws(FileStoreError) -> Data?
    /// Atomic: a reader sees the old file or the new one, never half of one.
    func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError)
    /// A file or a folder; no error when it is missing.
    func remove(_ path: String) throws(FileStoreError)
    /// Names, sorted; empty when the folder is missing.
    func list(_ folder: String) throws(FileStoreError) -> [String]
}

private func validatedComponents(_ path: String) throws(FileStoreError) -> [String] {
    if path.hasPrefix("/") { throw .invalidPath }
    let components = path.split(separator: "/", omittingEmptySubsequences: true).map(String.init)
    if components.contains("..") { throw .invalidPath }
    return components
}

/// Files under `root`: the app group container in the app.
public struct DiskFileStore: FileStore {
    private let root: URL

    public init(root: URL) {
        self.root = root
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

    /// While true, reading a `.complete` file throws `.unreadable`.
    public var isLocked: Bool {
        get { lock.withLock { locked } }
        set { lock.withLock { locked = newValue } }
    }

    public func protection(of path: String) -> FileProtection? {
        guard let key = try? validatedComponents(path).joined(separator: "/") else { return nil }
        return lock.withLock { files[key]?.protection }
    }

    public func read(_ path: String) throws(FileStoreError) -> Data? {
        let key = try validatedComponents(path).joined(separator: "/")
        let (file, isLockedNow) = lock.withLock { (files[key], locked) }
        guard let file else { return nil }
        if isLockedNow && file.protection == .complete { throw .unreadable }
        return file.data
    }

    public func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        let key = try validatedComponents(path).joined(separator: "/")
        lock.withLock { files[key] = (data, protection) }
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
