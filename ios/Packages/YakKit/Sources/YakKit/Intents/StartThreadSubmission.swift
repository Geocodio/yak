import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "StartThreadSubmission").error("\(message, privacy: .public)")
    #endif
}

public struct StartThreadRequest: Equatable, Sendable {
    public let instance: UUID
    public let repo: String
    /// "fix" or "research".
    public let mode: String
    public let text: String

    public init(instance: UUID, repo: String, mode: String, text: String) {
        self.instance = instance
        self.repo = repo
        self.mode = mode
        self.text = text
    }
}

public enum StartThreadOutcome: Equatable, Sendable {
    /// The server answered 201 within the bound.
    case started(threadID: String)
    /// Written; the app sends it the next time it runs its outbox.
    case saved
    /// The server said no; the item is removed; the message is bounded and may be empty.
    case refused(message: String)
    /// Nothing written.
    case invalid(StartThreadProblem)
    /// Nothing written.
    case needsPairing
}

public enum StartThreadProblem: Equatable, Sendable { case emptyText, textTooLong, unknownRepository, noInstance }

public struct StartThreadRepository: Equatable, Sendable {
    /// "owner/name", at most 100 characters.
    public let slug: String
    /// Bounded to 100 characters and flattened.
    public let name: String
    public let isReady: Bool

    public init(slug: String, name: String, isReady: Bool) {
        self.slug = slug
        self.name = name
        self.isReady = isReady
    }
}

/// Why the repository list could not be loaded; each maps to one intent dialog.
public enum StartThreadOptionsFailure: Error, Equatable, Sendable {
    case noInstance, needsPairing, offline, timedOut, unavailable
}

/// Starts a thread for an intent: the item is on disk before the one bounded attempt, and the attempt's
/// `send_key` is the item's id, so the app's outbox repeating the item never creates a second thread.
public struct StartThreadSubmission: Sendable {
    public static let textLimit = 4000
    public static let slugLimit = 100
    public static let nameLimit = 100
    public static let repositoryLimit = 200
    public static let messageLimit = 200

    private let instances: InstanceStore
    private let tokens: any TokenStore
    private let environment: SessionEnvironment

    public init(instances: InstanceStore, tokens: any TokenStore, environment: SessionEnvironment) {
        self.instances = instances
        self.tokens = tokens
        self.environment = environment
    }

    public func submit(_ request: StartThreadRequest, budget: Duration) async -> StartThreadOutcome {
        let text = request.text.trimmingCharacters(in: .whitespacesAndNewlines)
        if text.isEmpty { return .invalid(.emptyText) }
        if text.count > Self.textLimit { return .invalid(.textTooLong) }
        guard Self.isRepositorySlug(request.repo) else { return .invalid(.unknownRepository) }
        guard let target = await instances.all().first(where: { $0.id == request.instance }) else {
            return .invalid(.noInstance)
        }
        let state = await instances.state(of: target.id)
        if state == .needsPairing { return .needsPairing }

        let id = environment.makeID()
        let draft = OutboxDraft(
            target: .newThread(NewThreadDraft(repo: request.repo, mode: request.mode)), text: text,
            delivery: .afterTurn)
        let inboxPath = "\(OutboxInbox.folder(target.id))/\(id.uuidString).json"
        do {
            try OutboxInbox.add(
                draft, instance: target.id, files: environment.files, id: id, now: environment.clock.now())
        } catch {
            logFailure("inbox write failed: \(error)")
            return .refused(message: "")
        }
        // The Keychain cannot be read yet (before the first unlock): the app sends the item later.
        guard state == .ready, let token = readToken(for: target) else { return .saved }

        let client = makeClient(target, token: token)
        let newThread = NewThreadDraft(repo: request.repo, mode: request.mode)
        let outcome = await withBudget(budget, clock: environment.clock) { () async -> Created in
            do throws(APIFailure) {
                return .thread(
                    try await client.send(
                        .startThread(newThread, description: text, sendKey: id, attachments: [], references: [])))
            } catch {
                return .failure(error)
            }
        }
        switch outcome {
        case .thread(let created):
            removeInboxFile(inboxPath)
            return .started(threadID: created.id)
        case .failure(.server(let error)) where [403, 404, 409, 422].contains(error.status):
            removeInboxFile(inboxPath)
            return .refused(message: WidgetText.bounded(error.message, limit: Self.messageLimit))
        case .failure(let failure):
            logFailure("start kept for the app: \(Self.describe(failure))")
            return .saved
        case nil:
            logFailure("start ended by its budget, kept for the app")
            return .saved
        }
    }

    /// The instance's repositories for the intent's picker, at most 200, within `budget`, with its default slug.
    public func repositories(instance: UUID, budget: Duration) async throws(StartThreadOptionsFailure)
        -> (repositories: [StartThreadRepository], defaultSlug: String?)
    {
        guard let target = await instances.all().first(where: { $0.id == instance }) else { throw .noInstance }
        switch await instances.state(of: target.id) {
        case .needsPairing: throw .needsPairing
        // The Keychain is locked, not empty: pairing again would not help.
        case .tokenUnreadable: throw .unavailable
        case .ready: break
        }
        guard let token = readToken(for: target) else { throw .unavailable }
        let client = makeClient(target, token: token)
        let outcome = await withBudget(budget, clock: environment.clock) { () async -> Options in
            do throws(APIFailure) {
                return .options(try await client.send(.newThreadOptions()))
            } catch {
                return .failure(error)
            }
        }
        switch outcome {
        case .options(let options):
            var kept = options.repos.compactMap { repository -> StartThreadRepository? in
                guard Self.isRepositorySlug(repository.slug) else { return nil }
                return StartThreadRepository(
                    slug: repository.slug, name: WidgetText.bounded(repository.name, limit: Self.nameLimit),
                    isReady: repository.setupStatus == "ready")
            }
            // A stable partition: ready repositories first, each group in the server's order.
            kept = kept.filter(\.isReady) + kept.filter { !$0.isReady }
            kept = Array(kept.prefix(Self.repositoryLimit))
            let defaultSlug = options.defaultRepo.flatMap { slug in kept.contains { $0.slug == slug } ? slug : nil }
            return (kept, defaultSlug)
        case .failure(.unauthorized): throw .needsPairing
        case .failure(.network): throw .offline
        case .failure: throw .unavailable
        case nil: throw .timedOut
        }
    }

    /// `owner/name`: letters, digits, `.`, `_` and `-` on each side of exactly one `/`, no side made only of dots.
    static func isRepositorySlug(_ slug: String) -> Bool {
        guard slug.count <= slugLimit else { return false }
        let sides = slug.split(separator: "/", omittingEmptySubsequences: false)
        guard sides.count == 2 else { return false }
        return sides.allSatisfy { side in
            !side.isEmpty && !side.allSatisfy { $0 == "." }
                && side.unicodeScalars.allSatisfy { scalar in
                    scalar.isASCII
                        && (scalar.properties.isAlphabetic || ("0"..."9").contains(Character(scalar))
                            || "._-".unicodeScalars.contains(scalar))
                }
        }
    }

    // MARK: Steps

    private enum Created: Sendable {
        case thread(ThreadCreated)
        case failure(APIFailure)
    }

    private enum Options: Sendable {
        case options(NewThreadOptions)
        case failure(APIFailure)
    }

    private func makeClient(_ target: Instance, token: String) -> APIClient {
        APIClient(
            baseURL: target.url, token: token, transport: environment.http,
            appVersion: environment.appVersion, systemDescription: environment.systemDescription,
            sleep: { [clock = environment.clock] in try await clock.sleep(for: $0) })
    }

    private func readToken(for instance: Instance) -> String? {
        do {
            guard let token = try tokens.token(for: instance.id), !token.isEmpty else { return nil }
            return token
        } catch {
            logFailure("token unreadable: \(type(of: error))")
            return nil
        }
    }

    private func removeInboxFile(_ path: String) {
        do { try environment.files.remove(path) } catch {
            logFailure("inbox file not removed: \(type(of: error))")
        }
    }

    /// The case and code of a failure, never a message.
    private static func describe(_ failure: APIFailure) -> String {
        switch failure {
        case .server(let error): "server \(error.status) \(error.code)"
        case .network(let code): "network \(code.rawValue)"
        case .unauthorized(let code): "unauthorized \(code)"
        case .appTooOld: "appTooOld"
        case .serverTooOld: "serverTooOld"
        case .notYak: "notYak"
        case .encodingFailed: "encodingFailed"
        }
    }
}
