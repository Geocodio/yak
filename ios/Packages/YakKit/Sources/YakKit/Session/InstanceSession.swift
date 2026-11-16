import Foundation

#if canImport(os)
    import os
#endif

/// The case and code of a failure, never the server's message or details.
private func describe(_ failure: any Error) -> String {
    switch failure as? APIFailure {
    case .server(let error)?: "server \(error.code)"
    case .network(let code)?: "network \(code.rawValue)"
    case .notYak?: "notYak"
    case .some: "api failure"
    case nil: "\(type(of: failure))"
    }
}

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "InstanceSession").error("\(message, privacy: .public)")
    #endif
}

/// Everything a session takes from outside: the network, the files, time and ids.
public struct SessionEnvironment: Sendable {
    public let http: any HTTPTransport
    public let webSockets: any WebSocketConnector
    public let files: any FileStore
    public let clock: any YakClock
    public let makeID: @Sendable () -> UUID
    public let appVersion: String
    public let systemDescription: String
    /// True only in the app's Debug test mode: the realtime socket and the terminal socket may then use `ws`.
    public let allowsInsecureRealtime: Bool
    /// The background upload link of an instance; nil sends files with foreground requests. The closure runs on every
    /// use (the outbox and the upload summaries each call it) and must return the same link, and so the same ledger,
    /// for one instance every time. The background session's delegate must report to that same ledger. A new ledger
    /// per call would never hear completions or progress of the other.
    public let uploads: (@Sendable (UUID) -> UploadLink?)?

    public init(
        http: any HTTPTransport, webSockets: any WebSocketConnector, files: any FileStore, clock: any YakClock,
        makeID: @escaping @Sendable () -> UUID, appVersion: String, systemDescription: String,
        allowsInsecureRealtime: Bool, uploads: (@Sendable (UUID) -> UploadLink?)? = nil
    ) {
        self.http = http
        self.webSockets = webSockets
        self.files = files
        self.clock = clock
        self.makeID = makeID
        self.appVersion = appVersion
        self.systemDescription = systemDescription
        self.allowsInsecureRealtime = allowsInsecureRealtime
        self.uploads = uploads
    }
}

public enum SessionStatus: Equatable, Sendable {
    case starting, active, needsPairing, tokenUnreadable
    case appTooOld(minimum: Int)
    case serverTooOld
}

public enum SendProblem: Error, Equatable, Sendable {
    /// The session is not signed in, or has stopped.
    case notActive
    /// The outbox could not write the message to the disk.
    case notSaved
}

/// One paired instance at run time: the client, realtime connection, thread list, open threads, outbox
/// and cache, with the rules for what ends or pauses them.
public actor InstanceSession {
    private struct OpenThread {
        let engine: ThreadEngine
        let token: Int
        var callers: Int
        var forwarders: [Task<Void, Never>] = []
        var realtimeForwarder: Task<Void, Never>?
    }

    public nonisolated let instance: Instance

    private let instances: InstanceStore
    private let tokens: any TokenStore
    let environment: SessionEnvironment
    private let statusBroadcast = Broadcast(SessionStatus.starting)
    private let connectionBroadcast = Broadcast(ConnectionState.offline)

    private var isStarted = false
    /// A running stop; a start that arrives meanwhile waits for it instead of being undone by it.
    private var stopping: Task<Void, Never>?
    /// Counts starts and stops, so an answer that arrives after a stop is dropped.
    private var runID = 0
    private(set) var hasEnded = false
    private(set) var isUnpairing = false
    /// Awaited before each outbox update reaches a thread engine; a test holds an update here.
    private var forwardingGate: (@Sendable (OutboxUpdate) async -> Void)?
    private var nextToken = 0

    private var client: APIClient?
    private var meRequester: APIClient?
    private var currentMe: Me?
    private var cache: Cache?
    private var overview: OverviewActions?
    private var currentOutbox: Outbox?
    private var feed: ThreadListFeed?
    private var realtime: RealtimeClient?
    private var connectionForwarder: Task<Void, Never>?
    private var meRetryTask: Task<Void, Never>?
    private var threads: [String: OpenThread] = [:]
    private var overviewFeeds: [String: (feed: OverviewFeed, callers: Int)] = [:]

    public init(instance: Instance, instances: InstanceStore, tokens: any TokenStore, environment: SessionEnvironment) {
        self.instance = instance
        self.instances = instances
        self.tokens = tokens
        self.environment = environment
    }

    // MARK: Reading

    public func statuses() -> AsyncStream<SessionStatus> { statusBroadcast.stream() }
    public func me() -> Me? { currentMe }
    public func threadList() -> ThreadListFeed? { feed }
    public func outbox() -> Outbox? { currentOutbox }
    public func connectionStates() -> AsyncStream<ConnectionState> { connectionBroadcast.stream() }
    /// The signed-in client for a feature's requests. Nil unless the session is active. Fetch it again for each
    /// request; a client kept past `stop()` or a revoke still carries the old token.
    public func apiClient() -> APIClient? { isActive ? client : nil }
    /// The instance's cache, erased on unpair and revoke. Nil unless the session is active.
    public func instanceCache() -> Cache? { isActive ? cache : nil }
    /// The Overview's actions, made with the client and cache at start. Nil unless the session is active.
    public func overviewActions() -> OverviewActions? { isActive ? overview : nil }
    public nonisolated var clock: any YakClock { environment.clock }

    private var isActive: Bool { isStarted && !hasEnded && statusBroadcast.value == .active }

    // MARK: Start and stop

    /// Reads the token and asks `me`. Repeating it while started changes nothing.
    public func start() async {
        await stopping?.value
        guard !isStarted else { return }
        isStarted = true
        hasEnded = false
        isUnpairing = false
        runID += 1
        let current = runID
        let token: String?
        do { token = try tokens.token(for: instance.id) } catch {
            logFailure("token read failed: \(type(of: error))")
            isStarted = false
            statusBroadcast.send(.tokenUnreadable)
            return
        }
        guard let token, !token.isEmpty else {
            isStarted = false
            statusBroadcast.send(.needsPairing)
            return
        }
        currentOutbox = nil
        statusBroadcast.send(.starting)
        let transport = SessionTransport(wrapping: environment.http) { [weak self] signal in
            Task { await self?.handle(signal) }
        }
        let clock = environment.clock
        client = makeClient(token: token, transport: transport) { try await clock.sleep(for: $0) }
        // `me` is retried by the session's own schedule, so its client does not retry inside.
        let meClient = makeClient(token: token, transport: transport) { _ in throw CancellationError() }
        meRequester = meClient
        do {
            let me = try await meClient.send(.me())
            guard runID == current, isStarted, !hasEnded else { return }
            buildLocalParts()
            await connect(me, run: current)
            guard runID == current, !hasEnded else { return }
            statusBroadcast.send(.active)
        } catch {
            guard runID == current, !hasEnded else { return }
            if let signal = Self.signal(for: error) {
                await handle(signal)
                return
            }
            logFailure("me failed at start: \(describe(error))")
            buildLocalParts()
            statusBroadcast.send(.active)
            retryMe(run: current)
        }
    }

    private func makeClient(
        token: String, transport: any HTTPTransport, sleep: @escaping @Sendable (Duration) async throws -> Void
    ) -> APIClient {
        APIClient(
            baseURL: instance.url, token: token, transport: transport, appVersion: environment.appVersion,
            systemDescription: environment.systemDescription, sleep: sleep)
    }

    private static func signal(for failure: any Error) -> SessionTransport.Signal? {
        switch failure as? APIFailure {
        case .unauthorized(let code): .unauthorized(code: code)
        case .appTooOld(let minimum): .appTooOld(minimum: minimum)
        case .serverTooOld: .serverTooOld
        default: nil
        }
    }

    /// The cache, outbox and thread list work without the network, so they exist as soon as the session is active.
    private func buildLocalParts() {
        guard let client, cache == nil else { return }
        let newCache = Cache(instance: instance.id, files: environment.files, clock: environment.clock)
        cache = newCache
        overview = OverviewActions(
            client: client, cache: newCache, clock: environment.clock, makeID: environment.makeID)
        let newOutbox = Outbox(
            instance: instance.id, client: client, files: environment.files, clock: environment.clock,
            makeID: environment.makeID, uploads: environment.uploads?(instance.id))
        currentOutbox = newOutbox
        feed = ThreadListFeed(
            instance: instance, client: client, cache: newCache, instances: instances, clock: environment.clock)
        Task {
            await newOutbox.load()
            await newOutbox.sweepAttachmentFolders(keeping: await newCache.composerAttachmentFolders())
        }
    }

    /// Stores what `me` says, then starts realtime and puts every open thread on it.
    private func connect(_ me: Me, run: Int) async {
        currentMe = me
        do { try await instances.update(instance.id, from: me) } catch {
            logFailure("instance not updated: \(type(of: error))")
        }
        guard isCurrent(run), let client else { return }
        let configuration = RealtimeConfiguration(
            me.realtime, appVersion: environment.appVersion, allowsInsecure: environment.allowsInsecureRealtime)
        let newRealtime = RealtimeClient(
            configuration: configuration, connector: environment.webSockets,
            authorizer: { socketID, channelName throws(APIFailure) in
                try await client.send(.authorizeChannel(socketID: socketID, channelName: channelName))
            }, clock: environment.clock)
        realtime = newRealtime
        let states = await newRealtime.states()
        guard isCurrent(run) else { return await abandon(newRealtime) }
        let broadcast = connectionBroadcast
        connectionForwarder = Task {
            for await state in states { broadcast.send(state) }
        }
        for id in threads.keys {
            let forwarder = await realtimeForwarder(thread: id, realtime: newRealtime)
            guard isCurrent(run), var entry = threads[id] else {
                forwarder.cancel()
                continue
            }
            entry.realtimeForwarder?.cancel()
            entry.realtimeForwarder = forwarder
            threads[id] = entry
        }
        // A stop or a revoke during the awaits above has already cleared `realtime` and the forwarders, so
        // nothing owns this client any more and it must not run.
        guard isCurrent(run) else { return await abandon(newRealtime) }
        await newRealtime.start()
        if !isCurrent(run) { await abandon(newRealtime) }
    }

    /// False once a stop, a start or an ending (a revoke included) has replaced the run.
    private func isCurrent(_ run: Int) -> Bool { runID == run && !hasEnded }

    private func abandon(_ client: RealtimeClient) async {
        if realtime === client { realtime = nil }
        await client.stop()
    }

    /// Asks `me` again by the outbox's schedule until it answers.
    private func retryMe(run: Int) {
        let clock = environment.clock
        meRetryTask = Task {
            var attempt = 0
            while !Task.isCancelled {
                let delays = OutboxRetryPolicy.delays
                let delay = attempt < delays.count ? delays[attempt] : OutboxRetryPolicy.laterDelay
                attempt += 1
                do { try await clock.sleep(for: delay) } catch { return }
                guard let requester = self.meRequester else { return }
                do {
                    let me = try await requester.send(.me())
                    guard self.runID == run, !self.hasEnded else { return }
                    await self.connect(me, run: run)
                    return
                } catch {
                    if let signal = Self.signal(for: error) {
                        await self.handle(signal)
                        return
                    }
                    logFailure("me retry failed: \(describe(error))")
                }
            }
        }
    }

    public func stop() async {
        if let stopping { return await stopping.value }
        runID += 1
        isStarted = false
        let work = Task {
            await self.tearDown(closingThreads: true)
            await self.releaseParts()
        }
        stopping = work
        await work.value
        stopping = nil
    }

    /// Stops the outbox's work and drops everything the session built; the outbox items stay on disk.
    /// After a revoke the shut-down outbox stays readable, so the pairing screen can say why messages are
    /// held, until the next `start()` replaces it.
    private func releaseParts(keepingOutbox: Bool = false) async {
        await currentOutbox?.shutDown()
        cache = nil
        overview = nil
        if !keepingOutbox { currentOutbox = nil }
        feed = nil
        realtime = nil
        client = nil
        meRequester = nil
        currentMe = nil
    }

    private func tearDown(closingThreads: Bool) async {
        meRetryTask?.cancel()
        meRetryTask = nil
        connectionForwarder?.cancel()
        connectionForwarder = nil
        await realtime?.stop()
        connectionBroadcast.send(.offline)
        await feed?.setVisible(false)
        guard closingThreads else { return }
        let openFeeds = overviewFeeds
        overviewFeeds = [:]
        for entry in openFeeds.values { await entry.feed.close() }
        let open = threads
        threads = [:]
        for entry in open.values {
            for task in entry.forwarders { task.cancel() }
            entry.realtimeForwarder?.cancel()
            await entry.engine.close()
        }
    }

    // MARK: Signals

    /// Each ending is handled once, whichever request reported it.
    func handle(_ signal: SessionTransport.Signal) async {
        guard !hasEnded, !isUnpairing else { return }
        hasEnded = true
        switch signal {
        case .unauthorized(let code):
            do { try await instances.markRevoked(instance.id) } catch {
                logFailure("instance not marked revoked: \(type(of: error))")
            }
            await pauseOutboxForRevoke(code: code)
            await tearDown(closingThreads: true)
            await eraseCache()
            await releaseParts(keepingOutbox: true)
            isStarted = false
            statusBroadcast.send(.needsPairing)
        case .appTooOld(let minimum):
            await stopCalling(.appTooOld(minimum: minimum))
            statusBroadcast.send(.appTooOld(minimum: minimum))
        case .serverTooOld:
            await stopCalling(.serverTooOld)
            statusBroadcast.send(.serverTooOld)
        }
    }

    /// A revoke at start finds no outbox yet; one is built and paused so its held items stay readable.
    private func pauseOutboxForRevoke(code: String) async {
        let reason = OutboxPause.unauthorized(code: code)
        if let currentOutbox {
            await currentOutbox.pause(reason)
        } else if let client {
            let held = Outbox(
                instance: instance.id, client: client, files: environment.files, clock: environment.clock,
                makeID: environment.makeID, uploads: environment.uploads?(instance.id))
            await held.pause(reason)
            await held.load()
            currentOutbox = held
        }
    }

    /// Realtime, the list's polling and every thread's polling stop; the outbox keeps its items.
    private func stopCalling(_ reason: OutboxPause) async {
        await currentOutbox?.pause(reason)
        await tearDown(closingThreads: false)
        for entry in threads.values { await entry.engine.setVisible(false) }
    }

    private func eraseCache() async {
        let target = cache ?? Cache(instance: instance.id, files: environment.files, clock: environment.clock)
        await target.eraseAll()
    }

    // MARK: Threads

    /// One engine per thread, however many scenes open it. Nil unless the session is active.
    public func openThread(_ id: String) async -> ThreadEngine? {
        guard isActive, let client, let cache else { return nil }
        if var entry = threads[id] {
            entry.callers += 1
            threads[id] = entry
            return entry.engine
        }
        let engine = ThreadEngine(threadID: id, client: client, cache: cache, clock: environment.clock)
        nextToken += 1
        let token = nextToken
        // Registered before the first await, so a second scene finds this engine instead of making another.
        threads[id] = OpenThread(engine: engine, token: token, callers: 1)
        let forwarders = await outboxForwarders(thread: id, engine: engine)
        var realtimeForwarder: Task<Void, Never>?
        if let realtime { realtimeForwarder = await self.realtimeForwarder(thread: id, realtime: realtime) }
        guard var entry = threads[id], entry.token == token else {
            for task in forwarders { task.cancel() }
            realtimeForwarder?.cancel()
            return nil
        }
        entry.forwarders = forwarders
        entry.realtimeForwarder = realtimeForwarder
        threads[id] = entry
        await engine.open()
        return engine
    }

    /// Closes the engine when the last scene leaves.
    public func closeThread(_ id: String) async {
        guard var entry = threads[id] else { return }
        entry.callers -= 1
        guard entry.callers <= 0 else {
            threads[id] = entry
            return
        }
        threads[id] = nil
        if let feed = overviewFeeds.removeValue(forKey: id) { await feed.feed.close() }
        for task in entry.forwarders { task.cancel() }
        entry.realtimeForwarder?.cancel()
        await realtime?.unsubscribe(thread: id)
        await entry.engine.close()
    }

    /// One Overview feed per open thread, however many scenes show it. Nil unless the session is active and the thread is open.
    public func openOverview(_ threadID: String) async -> OverviewFeed? {
        if var existing = overviewFeeds[threadID] {
            existing.callers += 1
            overviewFeeds[threadID] = existing
            return existing.feed
        }
        guard isActive, let client, let overview, let engine = threads[threadID]?.engine else { return nil }
        let feed = OverviewFeed(
            threadID: threadID, client: client, clock: environment.clock, snapshots: await engine.snapshots(),
            changes: await overview.changes())
        // Another scene may have opened the feed, or the thread may have closed, during the awaits above.
        if var existing = overviewFeeds[threadID] {
            await feed.close()
            existing.callers += 1
            overviewFeeds[threadID] = existing
            return existing.feed
        }
        guard isActive, threads[threadID] != nil else {
            await feed.close()
            return nil
        }
        overviewFeeds[threadID] = (feed, 1)
        return feed
    }

    /// Closes the feed when the last caller leaves.
    public func closeOverview(_ threadID: String) async {
        guard var entry = overviewFeeds[threadID] else { return }
        entry.callers -= 1
        guard entry.callers <= 0 else {
            overviewFeeds[threadID] = entry
            return
        }
        overviewFeeds[threadID] = nil
        await entry.feed.close()
    }

    private func realtimeForwarder(thread id: String, realtime: RealtimeClient) async -> Task<Void, Never> {
        let stream = await realtime.subscribe(thread: id)
        let engine = threads[id]?.engine
        return Task {
            for await message in stream { await engine?.receive(message) }
        }
    }

    func setForwardingGate(_ gate: (@Sendable (OutboxUpdate) async -> Void)?) { forwardingGate = gate }

    /// One task reads the outbox's changes and deliveries from a single stream, so the engine hears of a
    /// delivery before the list that no longer holds the delivered item.
    private func outboxForwarders(thread id: String, engine: ThreadEngine) async -> [Task<Void, Never>] {
        guard let currentOutbox else { return [] }
        let updates = await currentOutbox.updates()
        let target = OutboxTarget.thread(id)
        let gate = forwardingGate
        return [
            Task {
                for await update in updates {
                    await gate?(update)
                    switch update {
                    case .items(let items): await engine.outboxChanged(items.filter { $0.target == target })
                    case .delivery(let delivery) where delivery.target == target: await engine.delivered(delivery)
                    case .delivery: break
                    }
                }
            }
        ]
    }

    // MARK: Sending

    /// Saves the draft in the outbox, which sends it as soon as it can.
    public func send(_ draft: OutboxDraft) async throws(SendProblem) -> OutboxItem {
        guard isActive, let currentOutbox else { throw .notActive }
        do { return try await currentOutbox.enqueue(draft) } catch { throw .notSaved }
    }

    /// Edit on a refused or waiting item: the item leaves the outbox and its files stay for the caller. Unavailable
    /// when the item is not there, is sending or may already be on the server; not saved when the disk refused the
    /// change, so the item is still in the outbox.
    public func takeOutboxItem(_ id: UUID) async -> OutboxTakeOutcome {
        guard let currentOutbox else { return .unavailable }
        do { return .taken(try await currentOutbox.take(id)) } catch {
            if error == .notSaved {
                logFailure("outbox item not taken, list not saved")
                return .notSaved
            }
            return .unavailable
        }
    }

    // MARK: Composer drafts and new threads

    /// Drafts and stored attachments are local files, so they work before the session is active and offline.
    private func draftCache() -> Cache {
        cache ?? Cache(instance: instance.id, files: environment.files, clock: environment.clock)
    }

    public func composerDraft(_ key: ComposerDraftKey) async -> ComposerDraft? {
        await draftCache().composerDraft(key)
    }

    public func saveComposerDraft(_ draft: ComposerDraft?, for key: ComposerDraftKey) async {
        await draftCache().saveComposerDraft(draft, for: key)
    }

    /// Writes the bytes where the outbox item with id `sendKey` keeps its attachments.
    public func storeAttachment(
        _ data: Data, fileName: String, contentType: String, reference: String, sendKey: UUID
    ) async throws(FileStoreError) -> OutboxAttachment {
        let safeName = ComposerAttachments.safeFileName(fileName)
        var path = OutboxInbox.attachmentPath(instance: instance.id, item: sendKey, fileName: safeName)
        // A name already taken in this draft's folder gets "-2", "-3" and so on before the extension.
        var counter = 2
        while try environment.files.read(path) != nil {
            path = OutboxInbox.attachmentPath(
                instance: instance.id, item: sendKey,
                fileName: ComposerAttachments.numberedFileName(safeName, counter: counter))
            counter += 1
        }
        try environment.files.write(data, to: path, protection: .untilFirstUnlock)
        return OutboxAttachment(reference: reference, fileName: fileName, contentType: contentType, path: path)
    }

    /// The stored bytes of a draft's attachment, or nil when the file is gone or cannot be read now.
    public func attachmentData(_ attachment: OutboxAttachment) async -> Data? {
        do { return try environment.files.read(attachment.path) } catch {
            logFailure("attachment not read: \(type(of: error))")
            return nil
        }
    }

    /// Whether the stored file of a draft's attachment is there, without reading it. A file that cannot be checked
    /// counts as there, so a draft is not trimmed on a failure that may pass.
    public func attachmentExists(_ attachment: OutboxAttachment) async -> Bool {
        do { return try environment.files.exists(attachment.path) } catch {
            logFailure("attachment not checked: \(type(of: error))")
            return true
        }
    }

    public func discardAttachment(_ attachment: OutboxAttachment) async {
        do { try environment.files.remove(attachment.path) } catch {
            logFailure("attachment not removed: \(type(of: error))")
        }
    }

    public func newThreadOptions() async throws(APIFailure) -> NewThreadOptions {
        guard let client else { throw .network(.notConnectedToInternet) }
        return try await client.send(.newThreadOptions())
    }

    public func repositoryAccess(_ repo: String) async throws(APIFailure) -> RepositoryAccess {
        guard let client else { throw .network(.notConnectedToInternet) }
        return try await client.send(.repositoryAccess(repo: repo))
    }

    // MARK: App state

    public func enterBackground() async {
        guard isActive else { return }
        await realtime?.suspend()
        for entry in threads.values { await entry.engine.flush() }
        await feed?.setVisible(false)
    }

    public func enterForeground() async {
        guard isActive else { return }
        await realtime?.resume()
        await currentOutbox?.importIncoming()
        for entry in threads.values { await entry.engine.requestCatchUp() }
    }

    public func networkPathChanged(isSatisfied: Bool) async {
        guard isActive else { return }
        await realtime?.networkPathChanged(isSatisfied: isSatisfied)
        if isSatisfied { await currentOutbox?.networkReturned() }
    }

    // MARK: Unpairing

    /// A client for a session that is not started, so `DELETE device` still goes out first.
    private func oneOffClient() -> APIClient? {
        do {
            guard let token = try tokens.token(for: instance.id), !token.isEmpty else { return nil }
            return makeClient(token: token, transport: environment.http) { _ in throw CancellationError() }
        } catch {
            logFailure("token read failed: \(type(of: error))")
            return nil
        }
    }

    /// Tells the server, then erases every copy this phone holds of the instance and forgets it.
    public func unpair() async {
        guard !isUnpairing else { return }
        isUnpairing = true
        if let deviceClient = client ?? oneOffClient() {
            do { _ = try await deviceClient.send(.unpair()) } catch {
                logFailure("device not removed on the server: \(describe(error))")
            }
        }
        hasEnded = true
        runID += 1
        await tearDown(closingThreads: true)
        await eraseCache()
        // A shut-down outbox ignores eraseAll, so the folder is removed here whatever the outbox says.
        await currentOutbox?.eraseAll()
        do { try environment.files.remove(InstanceFolders.outbox(instance.id)) } catch {
            logFailure("outbox not erased: \(type(of: error))")
        }
        do { try await instances.remove(instance.id) } catch {
            logFailure("instance not removed: \(type(of: error))")
        }
        await releaseParts()
        isStarted = false
        statusBroadcast.send(.needsPairing)
    }
}
