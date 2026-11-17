import Foundation
import YakKit

#if canImport(os)
    import os
#endif

/// The app's background upload sessions: one per paired instance, plus one per share sheet invocation that the app
/// creates only when the system wakes it for that share's identifier.
final class UploadSessions: @unchecked Sendable {
    private struct State {
        var links: [UUID: UploadLink] = [:]
        var ledgers: [UUID: UploadLedger] = [:]
        var shareSessions: [String: BackgroundUploadSession] = [:]
    }

    private let appBundleID: String
    private let appGroup: String?
    private let files: DiskFileStore
    private let clock: any YakClock
    private let instances: InstanceStore
    private let tokens: any TokenStore
    private let appVersion: String
    private let systemDescription: String
    private let state = OSAllocatedUnfairLock(initialState: State())
    #if DEBUG
        /// Set by test mode: the first completion of each app session is reported as failed.
        var failsFirstUpload = false
    #endif

    init(
        appBundleID: String, appGroup: String?, files: DiskFileStore, clock: any YakClock, instances: InstanceStore,
        tokens: any TokenStore, appVersion: String, systemDescription: String
    ) {
        self.appBundleID = appBundleID
        self.appGroup = appGroup
        self.files = files
        self.clock = clock
        self.instances = instances
        self.tokens = tokens
        self.appVersion = appVersion
        self.systemDescription = systemDescription
    }

    // MARK: Links

    private func ledger(for instance: UUID) -> UploadLedger {
        state.withLock { state in
            if let existing = state.ledgers[instance] { return existing }
            let ledger = UploadLedger(instance: instance, files: files, clock: clock)
            state.ledgers[instance] = ledger
            return ledger
        }
    }

    /// The same link on every call for one instance, so the outbox and the summaries share one ledger.
    func link(for instance: UUID, finishedEvents: (@Sendable () -> Void)? = nil) -> UploadLink {
        let ledger = ledger(for: instance)
        return state.withLock { state in
            if let existing = state.links[instance] { return existing }
            let identifier = UploadSessionID.app(instance: instance).identifier(appBundleID: appBundleID)
            let session = BackgroundUploadSession(
                identifier: identifier, appGroup: appGroup, files: files, finishedEvents: finishedEvents
            ) {
                [weak self] description in
                guard let self else { return nil }
                let owner = self.ledger(for: instance)
                return await owner.owns(description) ? owner : nil
            }
            #if DEBUG
                if failsFirstUpload { session.failNextCompletions(1) }
            #endif
            let link = UploadLink(ledger: ledger, session: session)
            state.links[instance] = link
            return link
        }
    }

    // MARK: Woken for events

    /// The system relaunched the app for a background session. An app session's events reach its ledger; a share
    /// session is created here only, and goes away after its events are delivered.
    func handleEvents(identifier: String, completion: @escaping @Sendable () -> Void) {
        let finish: @Sendable () -> Void = { Task { @MainActor in completion() } }
        switch UploadSessionID(identifier, appBundleID: appBundleID) {
        case .app(let instance)?:
            let link = link(for: instance, finishedEvents: finish)
            (link.session as? BackgroundUploadSession)?.finishedEvents = finish
        case .share?:
            let holder = SessionHolder()
            let session = BackgroundUploadSession(
                identifier: identifier, appGroup: appGroup, files: files,
                finishedEvents: { [weak self, holder] in
                    finish()
                    holder.session?.finishAndInvalidate()
                    self?.forgetShareSession(identifier)
                }
            ) { [weak self] description in await self?.ledgerOwningShare(description) }
            holder.session = session
            state.withLock { $0.shareSessions[identifier] = session }
        case nil:
            #if canImport(os)
                Logger(subsystem: "Yak", category: "Uploads").error("events for an identifier that does not parse")
            #endif
            finish()
        }
    }

    /// The share session made for `identifier` while its events are delivered; nil before and after.
    func shareSession(_ identifier: String) -> BackgroundUploadSession? {
        state.withLock { $0.shareSessions[identifier] }
    }

    var shareSessionCount: Int { state.withLock { $0.shareSessions.count } }

    private func forgetShareSession(_ identifier: String) {
        state.withLock { _ = $0.shareSessions.removeValue(forKey: identifier) }
    }

    /// The one ready instance whose ledger owns the item; the item id is unique, so at most one does.
    private func ledgerOwningShare(_ description: UploadTaskDescription) async -> UploadLedger? {
        for instance in await instances.all() where await instances.state(of: instance.id) == .ready {
            let candidate = ledger(for: instance.id)
            if await candidate.owns(description) { return candidate }
        }
        return nil
    }

    // MARK: Retries

    /// Starts every retryable record of every ready instance until the budget is spent. A task started while the
    /// app is in the background is discretionary for the system whatever the configuration says.
    func retryFailed(budget: Duration) async {
        let deadline = clock.now().addingTimeInterval(budget.timeInterval)
        for instance in await instances.all() {
            guard await instances.state(of: instance.id) == .ready else { continue }
            let link = link(for: instance.id)
            guard let client = client(for: instance) else { continue }
            for record in await link.ledger.retryable() {
                guard clock.now() < deadline, !Task.isCancelled else { return }
                _ = await link.ledger.start(record.itemID, session: link.session, client: client)
            }
        }
    }

    private func client(for instance: Instance) -> APIClient? {
        do {
            guard let token = try tokens.token(for: instance.id), !token.isEmpty else { return nil }
            return APIClient(
                baseURL: instance.url, token: token, transport: URLSessionTransport(), appVersion: appVersion,
                systemDescription: systemDescription)
        } catch {
            #if canImport(os)
                Logger(subsystem: "Yak", category: "Uploads").error("token unreadable: \(type(of: error))")
            #endif
            return nil
        }
    }

    // MARK: Launch

    /// Removes share staging folders that nobody used within the hour.
    func removeExpiredStaging() {
        let now = clock.now()
        do {
            for name in ShareStaging.expired(try files.list(InstanceFolders.shareStaging), now: now) {
                try files.remove("\(InstanceFolders.shareStaging)/\(name)")
            }
        } catch {
            #if canImport(os)
                Logger(subsystem: "Yak", category: "Uploads").error("staging not cleaned: \(type(of: error))")
            #endif
        }
    }

}

private final class SessionHolder: @unchecked Sendable {
    weak var session: BackgroundUploadSession?
}

extension UploadSessions: InstanceLifecycleObserver {
    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        let link = state.withLock { state -> UploadLink? in
            state.ledgers[instance.id] = nil
            return state.links.removeValue(forKey: instance.id)
        }
        (link?.session as? BackgroundUploadSession)?.cancelAll()
    }
}

extension Duration {
    fileprivate var timeInterval: TimeInterval {
        Double(components.seconds) + Double(components.attoseconds) / 1e18
    }
}
