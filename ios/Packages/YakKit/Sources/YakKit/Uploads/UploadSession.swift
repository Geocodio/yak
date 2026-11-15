import Foundation

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

public enum UploadStartProblem: Error, Equatable, Sendable { case noSession, fileMissing }

/// The `taskDescription` of every upload task: `"<item uuid>/<attempt>"`. Unique across sessions and processes.
public struct UploadTaskDescription: Equatable, Hashable, Sendable {
    public let item: UUID
    public let attempt: Int

    public var text: String { "\(item.uuidString)/\(attempt)" }

    /// Nil for anything but exactly a UUID, one slash and an attempt in 1...10_000.
    public init?(_ text: String) {
        let pieces = text.split(separator: "/", maxSplits: 2, omittingEmptySubsequences: false)
        guard pieces.count == 2, pieces[0].count == 36, let item = UUID(uuidString: String(pieces[0])),
            let attempt = Int(pieces[1]), (1...10_000).contains(attempt), String(attempt) == pieces[1]
        else { return nil }
        self.item = item
        self.attempt = attempt
    }

    public init(item: UUID, attempt: Int) {
        self.item = item
        self.attempt = attempt
    }
}

/// A background session. The app's and the extension's are `BackgroundUploadSession`; tests use a fake.
public protocol UploadSession: Sendable {
    var identifier: String { get }
    /// Starts an upload task whose `taskDescription` is `description.text`; returns the task identifier.
    func start(_ request: URLRequest, bodyPath: String, description: UploadTaskDescription) async throws(
        UploadStartProblem) -> Int
    /// The descriptions of the tasks the session still has.
    func liveTasks() async -> Set<UploadTaskDescription>
}

public enum UploadSessionID: Equatable, Hashable, Sendable {
    /// One per paired instance, created by the app.
    case app(instance: UUID)
    /// One per share sheet invocation, created by the extension; the app creates it only when woken for it.
    case share(invocation: UUID)

    public func identifier(appBundleID: String) -> String {
        switch self {
        case .app(let instance): "\(appBundleID).upload.\(instance.uuidString)"
        case .share(let invocation): "\(appBundleID).share.upload.\(invocation.uuidString)"
        }
    }

    public init?(_ identifier: String, appBundleID: String) {
        if let instance = Self.uuid(after: "\(appBundleID).upload.", in: identifier) {
            self = .app(instance: instance)
        } else if let invocation = Self.uuid(after: "\(appBundleID).share.upload.", in: identifier) {
            self = .share(invocation: invocation)
        } else {
            return nil
        }
    }

    private static func uuid(after prefix: String, in identifier: String) -> UUID? {
        guard identifier.hasPrefix(prefix) else { return nil }
        let rest = identifier.dropFirst(prefix.count)
        guard rest.count == 36 else { return nil }
        return UUID(uuidString: String(rest))
    }
}

/// What an outbox needs to send files in the background. The outbox builds requests with its own client.
public struct UploadLink: Sendable {
    public let ledger: UploadLedger
    public let session: any UploadSession

    public init(ledger: UploadLedger, session: any UploadSession) {
        self.ledger = ledger
        self.session = session
    }
}
