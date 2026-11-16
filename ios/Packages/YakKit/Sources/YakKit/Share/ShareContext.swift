import Foundation

#if canImport(os)
    import os
#endif

/// The thread last opened in the app, with whether the person may send to it. A private thread keeps no title.
public struct ShareLastThread: Codable, Equatable, Sendable {
    public let id: String
    /// Nil for a private thread.
    public let title: String?
    public let isPrivate: Bool
    public let canSend: Bool
    public let nextAttachmentNumber: Int

    public init(id: String, title: String?, isPrivate: Bool, canSend: Bool, nextAttachmentNumber: Int) {
        self.id = id
        self.title = title
        self.isPrivate = isPrivate
        self.canSend = canSend
        self.nextAttachmentNumber = nextAttachmentNumber
    }
}

/// The share sheet's copy of `Me.AttachmentLimits`, which is `Decodable` only and stays unchanged.
public struct ShareLimits: Codable, Equatable, Sendable {
    public let maxFiles: Int
    public let maxFileBytes: Int

    public init(maxFiles: Int, maxFileBytes: Int) {
        self.maxFiles = maxFiles
        self.maxFileBytes = maxFileBytes
    }

    public init(_ limits: Me.AttachmentLimits) {
        self.init(maxFiles: limits.maxFiles, maxFileBytes: limits.maxFileBytes)
    }
}

/// What the app tells the share extension about one instance, so the sheet shows at once and works offline.
public struct ShareContext: Codable, Equatable, Sendable {
    public let instanceName: String
    public let limits: ShareLimits
    public var lastThread: ShareLastThread?
    public var options: NewThreadOptions?
    public var lastRepo: String?
    public var updatedAt: Date

    public init(
        instanceName: String, limits: ShareLimits, lastThread: ShareLastThread? = nil,
        options: NewThreadOptions? = nil, lastRepo: String? = nil, updatedAt: Date
    ) {
        self.instanceName = instanceName
        self.limits = limits
        self.lastThread = lastThread
        self.options = options
        self.lastRepo = lastRepo
        self.updatedAt = updatedAt
    }
}

public enum ShareContextStore {
    public static let byteLimit = 262_144
    static let maximumRepositories = 200
    static let maximumModelFamilies = 50
    static let maximumStringLength = 200

    /// Nil when missing, unreadable, too large or not a context. Bounds lists and strings on the way in.
    public static func read(instance: UUID, files: any FileStore) -> ShareContext? {
        let data: Data
        do {
            guard let stored = try files.read(InstanceFolders.shareContext(instance), maximumBytes: byteLimit) else {
                return nil
            }
            data = stored
        } catch {
            logFailure("share context not read: \(type(of: error))")
            return nil
        }
        guard data.count <= byteLimit, var object = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
        else { return nil }
        if var options = object["options"] as? [String: Any] {
            if let repos = options["repos"] as? [Any] { options["repos"] = Array(repos.prefix(maximumRepositories)) }
            if let models = options["models"] as? [Any] {
                options["models"] = Array(models.prefix(maximumModelFamilies))
            }
            object["options"] = options
        }
        guard let bounded = try? JSONSerialization.data(withJSONObject: cut(object)),
            let context = try? JSONDecoder().decode(ShareContext.self, from: bounded),
            (1...50).contains(context.limits.maxFiles), (1...104_857_600).contains(context.limits.maxFileBytes)
        else { return nil }
        return context
    }

    public static func write(_ context: ShareContext, instance: UUID, files: any FileStore) throws(FileStoreError) {
        let data: Data
        do { data = try JSONEncoder().encode(context) } catch { throw .unreadable }
        guard data.count <= byteLimit else { throw .tooLarge }
        try files.write(data, to: InstanceFolders.shareContext(instance), protection: .untilFirstUnlock)
    }

    /// A private thread keeps no title. `canSend` follows the composer bar's rule: not read-only, closed, on a computer or blocked.
    public static func lastThread(from show: ThreadShow) -> ShareLastThread {
        let isPrivate = show.thread.visibility == "private"
        let bar = ComposerBarState.make(
            composer: show.composer.state, canDrive: show.canDrive, turn: nil, notice: show.notice, typedText: "",
            attachmentCount: 0)
        return ShareLastThread(
            id: show.thread.id, title: isPrivate ? nil : String(show.thread.title.prefix(maximumStringLength)),
            isPrivate: isPrivate, canSend: bar.unavailable == nil,
            nextAttachmentNumber: show.composer.nextAttachmentNumber)
    }

    private static func cut(_ value: Any) -> Any {
        switch value {
        case let text as String: String(text.prefix(maximumStringLength))
        case let list as [Any]: list.map(cut)
        case let dictionary as [String: Any]: dictionary.mapValues(cut)
        default: value
        }
    }
}

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "ShareContext").error("\(message, privacy: .public)")
    #endif
}
