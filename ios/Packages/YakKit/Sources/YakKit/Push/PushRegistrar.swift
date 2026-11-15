import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "PushRegistrar").error("\(message, privacy: .public)")
    #endif
}

/// The case and code of a failure, never a message, a detail or a token.
private func describe(_ failure: APIFailure) -> String {
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

extension Duration {
    fileprivate var secondsValue: TimeInterval {
        TimeInterval(components.seconds) + TimeInterval(components.attoseconds) / 1e18
    }
}

public enum PushRegistrationStatus: Equatable, Sendable {
    /// Never registered, and why.
    case unavailable(PushAvailability)
    /// Available, but this device has no token yet (permission not given).
    case waitingForToken
    case registering
    case waitingForNetwork
    case retrying(at: Date, problem: PushProblem)
    case registered
    /// Not retried until the token or the availability changes.
    case refused(PushProblem)
    case needsPairing
}

/// What the registrar keeps on disk for one instance: the desired state, marked done after the server's 204.
struct PushRegistrationRecord: Codable, Equatable, Sendable {
    struct Entry: Codable, Equatable, Sendable {
        var token: String
        var environment: PushEnvironment
        var isRegistered: Bool
        var attempts: Int
        var refusal: PushProblem?
    }

    /// Keyed by `PushTokenKind.rawValue`.
    var entries: [String: Entry] = [:]
    /// The last activity set asked for; nil until the app reports one.
    var activities: [String]?
    var activitiesReported = false
    var activityAttempts = 0
}

/// Everything push asks a server: tokens, the activity set, preferences, the test push, the channel and the
/// background refresh of `me`.
public actor PushRegistrar {
    /// What `reconcile` decided about an instance.
    private enum Basis {
        case unavailable(PushAvailability)
        case needsPairing
        case tokenUnreadable
        case eligible(Instance)
    }

    private enum Step {
        case token(PushTokenKind, sent: String)
        case activities(sent: [String])

        var name: String {
            switch self {
            case .token(let kind, _): "token \(kind.rawValue)"
            case .activities: "activity set"
            }
        }
    }

    private let instances: InstanceStore
    private let tokens: any TokenStore
    private let environment: SessionEnvironment

    private var records: [UUID: PushRegistrationRecord] = [:]
    private var basis: [UUID: Basis] = [:]
    private var deviceTokens: [PushTokenKind: String] = [:]
    private var workers: [UUID: Task<Void, Never>] = [:]
    private var generations: [UUID: Int] = [:]
    private var paused: [UUID: PushRegistrationStatus] = [:]
    private var waitingForNetwork: Set<UUID> = []
    private var unregistering: Set<UUID> = []
    private var isOnline = true
    private var isStopped = false
    private let statusBroadcast = Broadcast<[UUID: PushRegistrationStatus]>([:])

    private static let maximumActivities = 10

    public init(instances: InstanceStore, tokens: any TokenStore, environment: SessionEnvironment) {
        self.instances = instances
        self.tokens = tokens
        self.environment = environment
    }

    public static func hex(_ deviceToken: Data) -> String {
        deviceToken.map { ($0 < 16 ? "0" : "") + String($0, radix: 16) }.joined()
    }

    public func statuses() -> AsyncStream<[UUID: PushRegistrationStatus]> { statusBroadcast.stream() }

    // MARK: Lifecycle

    /// Loads every record and resumes unfinished work.
    public func start() async {
        isStopped = false
        let known = Set(await instances.all().map(\.id))
        do {
            for name in try environment.files.list("instances") {
                guard let id = UUID(uuidString: name), !known.contains(id) else { continue }
                removeRecordFile(id)
            }
        } catch {
            logFailure("instance folders unreadable: \(type(of: error))")
        }
        await reconcile()
    }

    /// Final: the status streams finish and the registrar is not started again; make a new one instead.
    public func stop() async {
        isStopped = true
        for task in workers.values { task.cancel() }
        workers = [:]
        statusBroadcast.finish()
    }

    /// After a pairing or a `me` refresh: clears a pause, applies the availability and resumes work.
    public func sync() async {
        paused = [:]
        await reconcile()
    }

    public func setToken(_ token: String, kind: PushTokenKind) async {
        deviceTokens[kind] = token
        await reconcile()
    }

    public func reportActivities(_ threads: [String], instance: UUID) async {
        guard !unregistering.contains(instance) else { return }
        let set = Array(Set(threads).sorted().prefix(Self.maximumActivities))
        var record = records[instance] ?? loadRecord(instance)
        guard record.activities != set || !record.activitiesReported else { return }
        record.activities = set
        record.activitiesReported = false
        record.activityAttempts = 0
        records[instance] = record
        save(instance)
        scheduleWork(instance)
    }

    public func networkChanged(isOnline: Bool) async {
        self.isOnline = isOnline
        guard isOnline else { return }
        let waiting = waitingForNetwork
        waitingForNetwork = []
        for id in waiting {
            setBaseline(id)
            scheduleWork(id)
        }
    }

    /// Best effort `DELETE push-tokens/<kind>` for each stored token, before the bearer token is erased. The
    /// record stays for `forget`, which the caller must call; until then the instance gets no work.
    public func unregister(_ instance: UUID, budget: Duration) async {
        unregistering.insert(instance)
        cancelWorker(instance)
        let kinds = PushTokenKind.allCases.filter { records[instance]?.entries[$0.rawValue] != nil }
        guard !kinds.isEmpty, let client = await client(for: instance) else { return }
        await Self.withBudget(budget, clock: environment.clock) {
            await withTaskGroup(of: Void.self) { group in
                for kind in kinds {
                    group.addTask {
                        do throws(APIFailure) {
                            _ = try await client.send(.deletePushToken(kind: kind))
                        } catch {
                            logFailure("token removal failed: \(describe(error))")
                        }
                    }
                }
            }
        }
    }

    public func forget(_ instance: UUID) async {
        cancelWorker(instance)
        records[instance] = nil
        basis[instance] = nil
        paused[instance] = nil
        waitingForNetwork.remove(instance)
        unregistering.remove(instance)
        removeRecordFile(instance)
        var current = statusBroadcast.value
        if current.removeValue(forKey: instance) != nil { statusBroadcast.send(current) }
    }

    // MARK: Calls the screens await

    public func preferences(instance: UUID) async throws(PushProblem) -> NotificationPreferences {
        try await call(.notificationPreferences(), instance: instance)
    }

    public func setPreference(_ event: PushEvent, isOn: Bool, instance: UUID) async throws(PushProblem)
        -> NotificationPreferences
    {
        try await call(.updateNotificationPreference(event, isOn: isOn), instance: instance)
    }

    public func sendTestNotification(instance: UUID) async throws(PushProblem) {
        _ = try await call(.sendTestPush(), instance: instance)
    }

    public func liveActivityChannel(thread: String, instance: UUID) async throws(PushProblem) -> String {
        try await call(.liveActivityChannel(thread: thread), instance: instance).channel
    }

    private func call<Response: Decodable & Sendable>(_ endpoint: Endpoint<Response>, instance: UUID)
        async throws(PushProblem) -> Response
    {
        guard let client = await client(for: instance) else { throw .needsPairing }
        do {
            return try await client.send(endpoint)
        } catch {
            throw PushProblem(error)
        }
    }

    /// `GET me` for every ready instance, one after another, then `sync()`. Returns the badge sum.
    public func refreshInstances(budget: Duration) async -> Int {
        let store = instances
        var clients: [(UUID, APIClient)] = []
        for instance in await instances.all() {
            if let client = client(for: instance) { clients.append((instance.id, client)) }
        }
        let ready = clients
        await Self.withBudget(budget, clock: environment.clock) {
            for (id, client) in ready {
                if Task.isCancelled { return }
                do {
                    let me = try await client.send(.me())
                    try await store.update(id, from: me)
                } catch let failure as APIFailure {
                    logFailure("me refresh failed: \(describe(failure))")
                } catch {
                    logFailure("me refresh could not be stored: \(type(of: error))")
                }
            }
        }
        await sync()
        return await instances.badgeCount()
    }

    /// Runs `work` and stops waiting for it when the budget ends; the work is cancelled then.
    private static func withBudget(
        _ budget: Duration, clock: any YakClock, _ work: @escaping @Sendable () async -> Void
    ) async {
        await withTaskGroup(of: Void.self) { group in
            group.addTask { await work() }
            group.addTask {
                do { try await clock.sleep(for: budget) } catch { return }
            }
            _ = await group.next()
            group.cancelAll()
        }
    }

    // MARK: Reconciling instances with records

    private func client(for instance: UUID) async -> APIClient? {
        guard let found = await instances.all().first(where: { $0.id == instance }) else { return nil }
        return client(for: found)
    }

    private func client(for instance: Instance) -> APIClient? {
        do {
            guard let token = try tokens.token(for: instance.id), !token.isEmpty else { return nil }
            let clock = environment.clock
            return APIClient(
                baseURL: instance.url, token: token, transport: environment.http,
                appVersion: environment.appVersion, systemDescription: environment.systemDescription,
                sleep: { try await clock.sleep(for: $0) })
        } catch {
            logFailure("token unreadable: \(type(of: error))")
            return nil
        }
    }

    /// Decides for every instance whether it gets a token, applies the device tokens and starts the work.
    private func reconcile() async {
        guard !isStopped else { return }
        let all = await instances.all()
        var states: [UUID: InstanceState] = [:]
        for instance in all { states[instance.id] = await instances.state(of: instance.id) }
        guard !isStopped else { return }

        let live = Set(all.map(\.id))
        for id in Array(records.keys) where !live.contains(id) && !unregistering.contains(id) {
            await forget(id)
        }
        for instance in all where !unregistering.contains(instance.id) {
            let id = instance.id
            if records[id] == nil { records[id] = loadRecord(id) }
            let decided = decide(instance, state: states[id] ?? .needsPairing)
            basis[id] = decided
            if case .eligible = decided {
                applyDeviceTokens(to: id)
            } else {
                cancelWorker(id)
                if case .unavailable = decided { clearRefusals(id) }
            }
            if workers[id] == nil || !isEligible(id) { setBaseline(id) }
        }
        for instance in all { scheduleWork(instance.id) }
    }

    private func decide(_ instance: Instance, state: InstanceState) -> Basis {
        switch state {
        case .needsPairing: return .needsPairing
        case .tokenUnreadable: return .tokenUnreadable
        case .ready: break
        }
        guard instances.build != nil else { return .unavailable(.noKey) }
        let availability = instance.push ?? (instance.pushAvailable ? .available : .noKey)
        return availability == .available ? .eligible(instance) : .unavailable(availability)
    }

    private func isEligible(_ id: UUID) -> Bool {
        if case .eligible? = basis[id] { return true }
        return false
    }

    private func applyDeviceTokens(to id: UUID) {
        guard let build = instances.build, var record = records[id] else { return }
        var changed = false
        for (kind, token) in deviceTokens {
            let current = record.entries[kind.rawValue]
            guard current?.token != token || current?.environment != build.environment else { continue }
            record.entries[kind.rawValue] = .init(
                token: token, environment: build.environment, isRegistered: false, attempts: 0, refusal: nil)
            changed = true
        }
        guard changed else { return }
        records[id] = record
        save(id)
    }

    /// A refusal ends when the instance's availability becomes unavailable (a failed Keychain read does not), so a later key or build is tried again.
    private func clearRefusals(_ id: UUID) {
        guard var record = records[id], record.entries.values.contains(where: { $0.refusal != nil }) else { return }
        for key in record.entries.keys { record.entries[key]?.refusal = nil }
        records[id] = record
        save(id)
    }

    // MARK: Status

    private func baselineStatus(_ id: UUID) -> PushRegistrationStatus? {
        switch basis[id] {
        case nil: return nil
        case .unavailable(let availability)?: return .unavailable(availability)
        case .needsPairing?: return .needsPairing
        case .tokenUnreadable?: return statusBroadcast.value[id] ?? .registering
        case .eligible?:
            if let pause = paused[id] { return pause }
            if waitingForNetwork.contains(id) { return .waitingForNetwork }
            let entries = PushTokenKind.allCases.compactMap { records[id]?.entries[$0.rawValue] }
            if entries.isEmpty { return .waitingForToken }
            if let refusal = entries.lazy.compactMap(\.refusal).first { return .refused(refusal) }
            return entries.contains { !$0.isRegistered } ? .registering : .registered
        }
    }

    private func setBaseline(_ id: UUID) {
        if let status = baselineStatus(id) { setStatus(id, status) }
    }

    private func setStatus(_ id: UUID, _ status: PushRegistrationStatus) {
        var current = statusBroadcast.value
        guard current[id] != status else { return }
        current[id] = status
        statusBroadcast.send(current)
    }

    // MARK: Work

    private func nextStep(_ id: UUID) -> Step? {
        guard isEligible(id), paused[id] == nil, !unregistering.contains(id), let record = records[id] else {
            return nil
        }
        for kind in PushTokenKind.allCases {
            if let entry = record.entries[kind.rawValue], !entry.isRegistered, entry.refusal == nil {
                return .token(kind, sent: entry.token)
            }
        }
        if let activities = record.activities, !record.activitiesReported { return .activities(sent: activities) }
        return nil
    }

    private func scheduleWork(_ id: UUID) {
        guard !isStopped, workers[id] == nil, !waitingForNetwork.contains(id), nextStep(id) != nil else { return }
        let generation = generations[id, default: 0]
        workers[id] = Task { await self.run(id, generation: generation) }
    }

    private func cancelWorker(_ id: UUID) {
        generations[id, default: 0] += 1
        workers.removeValue(forKey: id)?.cancel()
    }

    private func isCurrent(_ id: UUID, _ generation: Int) -> Bool {
        !isStopped && generations[id, default: 0] == generation && !Task.isCancelled
    }

    private func delay(forAttempt attempt: Int) -> Duration {
        let delays = OutboxRetryPolicy.delays
        return attempt >= 1 && attempt <= delays.count ? delays[attempt - 1] : OutboxRetryPolicy.laterDelay
    }

    /// Sends the newest desired state, one request at a time, until nothing is left to send. An answer for
    /// a state that was replaced while the request was out is not recorded.
    private func run(_ id: UUID, generation: Int) async {
        var cancellations = 0
        while isCurrent(id, generation) {
            guard case .eligible(let instance)? = basis[id], let step = nextStep(id) else { break }
            guard let client = client(for: instance) else { break }
            let failure: APIFailure?
            switch step {
            case .token(let kind, _):
                guard let entry = records[id]?.entries[kind.rawValue] else { continue }
                setStatus(id, .registering)
                do {
                    _ = try await client.send(
                        .putPushToken(kind: kind, token: entry.token, environment: entry.environment))
                    failure = nil
                } catch {
                    failure = error
                }
            case .activities(let threads):
                do {
                    _ = try await client.send(.reportLiveActivities(threads: threads))
                    failure = nil
                } catch {
                    failure = error
                }
            }
            guard isCurrent(id, generation) else { return }

            guard let failure else {
                cancellations = 0
                markDone(id, step: step)
                continue
            }
            logFailure("\(step.name) failed: \(describe(failure))")
            let attempt = bumpAttempts(id, step: step)
            switch OutboxRetryPolicy.decision(for: failure, attempt: attempt) {
            case .stayQueued:
                cancellations += 1
                if cancellations >= 3, !(await sleep(for: delay(forAttempt: attempt), id, generation)) {
                    return
                }
            case .retry(let wait):
                cancellations = 0
                if case .network = failure, !isOnline {
                    setStatus(id, .waitingForNetwork)
                    waitingForNetwork.insert(id)
                    workers[id] = nil
                    return
                }
                let retryAt = environment.clock.now().addingTimeInterval(wait.secondsValue)
                setStatus(id, .retrying(at: retryAt, problem: PushProblem(failure)))
                if !(await sleep(for: wait, id, generation)) { return }
            case .refuse:
                cancellations = 0
                refuse(id, step: step, problem: PushProblem(failure))
            case .pause(let pause):
                paused[id] = pause == .serverTooOld ? .refused(.serverTooOld) : .needsPairing
                workers[id] = nil
                setBaseline(id)
                return
            }
        }
        guard isCurrent(id, generation) else { return }
        workers[id] = nil
        setBaseline(id)
    }

    /// False when the wait was cut short because the work was cancelled.
    private func sleep(for duration: Duration, _ id: UUID, _ generation: Int) async -> Bool {
        do { try await environment.clock.sleep(for: duration) } catch { return false }
        return isCurrent(id, generation)
    }

    private func markDone(_ id: UUID, step: Step) {
        guard var record = records[id] else { return }
        switch step {
        case .token(let kind, let sent):
            guard record.entries[kind.rawValue]?.token == sent else { return }
            record.entries[kind.rawValue]?.isRegistered = true
            record.entries[kind.rawValue]?.attempts = 0
        case .activities(let sent):
            guard record.activities == sent else { return }
            record.activitiesReported = true
            record.activityAttempts = 0
        }
        records[id] = record
        save(id)
        setBaseline(id)
    }

    private func bumpAttempts(_ id: UUID, step: Step) -> Int {
        guard var record = records[id] else { return 1 }
        let attempt: Int
        switch step {
        case .token(let kind, _):
            record.entries[kind.rawValue]?.attempts += 1
            attempt = record.entries[kind.rawValue]?.attempts ?? 1
        case .activities:
            record.activityAttempts += 1
            attempt = record.activityAttempts
        }
        records[id] = record
        save(id)
        return attempt
    }

    /// A refused token waits for a new token or availability. A refused activity set is dropped: the app
    /// reports the next set when its activities change.
    private func refuse(_ id: UUID, step: Step, problem: PushProblem) {
        guard var record = records[id] else { return }
        switch step {
        case .token(let kind, let sent):
            if record.entries[kind.rawValue]?.token == sent { record.entries[kind.rawValue]?.refusal = problem }
        case .activities(let sent):
            if record.activities == sent { record.activitiesReported = true }
        }
        records[id] = record
        save(id)
        setBaseline(id)
    }

    // MARK: Record files

    private static func path(_ id: UUID) -> String { "instances/\(id.uuidString)/push-registration.json" }

    private func loadRecord(_ id: UUID) -> PushRegistrationRecord {
        do {
            guard let data = try environment.files.read(Self.path(id)) else { return PushRegistrationRecord() }
            return try JSONDecoder().decode(PushRegistrationRecord.self, from: data)
        } catch {
            logFailure("push record unreadable: \(type(of: error))")
            return PushRegistrationRecord()
        }
    }

    private func save(_ id: UUID) {
        guard let record = records[id] else { return }
        do {
            let encoder = JSONEncoder()
            encoder.outputFormatting = .sortedKeys
            try environment.files.write(
                try encoder.encode(record), to: Self.path(id), protection: .untilFirstUnlock)
        } catch {
            logFailure("push record not saved: \(type(of: error))")
        }
    }

    private func removeRecordFile(_ id: UUID) {
        do { try environment.files.remove(Self.path(id)) } catch {
            logFailure("push record not removed: \(type(of: error))")
        }
    }
}
