import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "WidgetPushRegistration").error("\(message, privacy: .public)")
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

public enum WidgetPushStatus: Equatable, Sendable {
    /// Never registered with this instance.
    case notEligible(PushAvailability)
    case pending
    case registered
    /// Not retried until the token or the environment changes.
    case refused(PushProblem)
    /// The instance answered 404: asked again after a day.
    case unsupported(retryAt: Date)
    case retrying(at: Date)
    case needsPairing
}

/// What is kept on disk for one instance: the desired state, marked done after the server's 204.
private struct WidgetPushRecord: Codable, Equatable {
    var token: String
    var environment: PushEnvironment
    var isRegistered = false
    var attempts = 0
    var nextAttemptAt: Date?
    var refusal: PushProblem?
    /// True when `nextAttemptAt` waits out a 404 rather than a failed attempt.
    var isUnsupported: Bool?
}

private struct WidgetPushTokenFile: Codable {
    var token: String
}

/// Registers the widget extension's push token with every instance that can push to this build, and keeps the
/// work on disk so a relaunch or a killed extension resumes it. The app and the extension may run it at once:
/// every write is atomic, and a lost write repeats a `PUT` that the server treats as a no-op.
public struct WidgetPushRegistration: Sendable {
    private let instances: InstanceStore
    private let tokens: any TokenStore
    private let environment: SessionEnvironment
    private let build: PushBuild?

    private static let maximumFileBytes = 4096
    private static let maximumTokenLength = 200
    private static let maximumParallelRequests = 4
    private static let unsupportedWait: TimeInterval = 24 * 60 * 60

    public init(instances: InstanceStore, tokens: any TokenStore, environment: SessionEnvironment, build: PushBuild?) {
        self.instances = instances
        self.tokens = tokens
        self.environment = environment
        self.build = build
    }

    /// Stores the device's widget token (hex) for every process, then registers it within `budget`.
    public func tokenChanged(_ token: String, budget: Duration) async -> [UUID: WidgetPushStatus] {
        guard Self.isValid(token) else {
            logFailure("widget token refused locally")
            return await statuses()
        }
        do {
            let data = try JSONEncoder().encode(WidgetPushTokenFile(token: token))
            try environment.files.write(data, to: InstanceFolders.widgetPushToken, protection: .untilFirstUnlock)
        } catch {
            logFailure("widget token not saved: \(type(of: error))")
            return await statuses()
        }
        return await registerPending(budget: budget)
    }

    /// Registers what is still pending for every paired instance within `budget`. Cheap when nothing is.
    public func registerPending(budget: Duration) async -> [UUID: WidgetPushStatus] {
        guard let token = deviceToken(), let build else { return await statuses() }
        let now = environment.clock.now()
        var work: [Instance] = []
        for instance in await instances.all() {
            guard await instances.state(of: instance.id) == .ready, availability(of: instance) == .available else {
                continue
            }
            var record = loadRecord(instance.id)
            if record?.token != token || record?.environment != build.environment {
                record = WidgetPushRecord(token: token, environment: build.environment)
                save(record, for: instance.id)
            }
            guard let current = record, !current.isRegistered, current.refusal == nil else { continue }
            if let next = current.nextAttemptAt, next > now { continue }
            work.append(instance)
        }
        guard !work.isEmpty else { return await statuses() }

        let pending = work
        let overrides = await withBudget(budget, clock: environment.clock) {
            () async -> [UUID: WidgetPushStatus] in
            await self.send(pending, token: token, environment: build.environment)
        }
        var result = await statuses()
        for (id, status) in overrides ?? [:] { result[id] = status }
        return result
    }

    public func statuses() async -> [UUID: WidgetPushStatus] {
        let token = deviceToken()
        let now = environment.clock.now()
        var result: [UUID: WidgetPushStatus] = [:]
        for instance in await instances.all() {
            let state = await instances.state(of: instance.id)
            if state == .needsPairing {
                result[instance.id] = .needsPairing
                continue
            }
            let availability = availability(of: instance)
            guard availability == .available else {
                result[instance.id] = .notEligible(availability)
                continue
            }
            guard let token, let build, let record = loadRecord(instance.id), record.token == token,
                record.environment == build.environment
            else {
                result[instance.id] = .pending
                continue
            }
            if record.isRegistered {
                result[instance.id] = .registered
            } else if let refusal = record.refusal {
                result[instance.id] = .refused(refusal)
            } else if let next = record.nextAttemptAt, next > now {
                result[instance.id] =
                    record.isUnsupported == true ? .unsupported(retryAt: next) : .retrying(at: next)
            } else {
                result[instance.id] = .pending
            }
        }
        return result
    }

    // MARK: Sending

    /// At most four requests at a time.
    private func send(_ work: [Instance], token: String, environment target: PushEnvironment) async
        -> [UUID: WidgetPushStatus]
    {
        await withTaskGroup(of: (UUID, WidgetPushStatus?).self) { group in
            var remaining = work[...]
            func addNext() {
                guard let instance = remaining.popFirst(), !Task.isCancelled else { return }
                group.addTask { (instance.id, await self.register(instance, token: token, environment: target)) }
            }
            for _ in 0..<Self.maximumParallelRequests { addNext() }
            var overrides: [UUID: WidgetPushStatus] = [:]
            while let (id, status) = await group.next() {
                if let status { overrides[id] = status }
                addNext()
            }
            return overrides
        }
    }

    /// The status to report when it is not the record's own (a refused pairing stores nothing).
    private func register(_ instance: Instance, token: String, environment target: PushEnvironment) async
        -> WidgetPushStatus?
    {
        let id = instance.id
        let bearer: String?
        do { bearer = try tokens.token(for: id) } catch {
            logFailure("token unreadable: \(type(of: error))")
            return nil
        }
        guard let bearer, !bearer.isEmpty else { return .needsPairing }
        let client = APIClient(
            baseURL: instance.url, token: bearer, transport: environment.http,
            appVersion: environment.appVersion, systemDescription: environment.systemDescription,
            sleep: { [clock = environment.clock] in try await clock.sleep(for: $0) })
        do throws(APIFailure) {
            _ = try await client.send(.putPushToken(kind: .widget, token: token, environment: target))
        } catch {
            logFailure("widget token failed: \(describe(error))")
            return recordFailure(error, id: id, token: token, environment: target)
        }
        update(id, token: token, environment: target) {
            $0.isRegistered = true
            $0.attempts = 0
            $0.nextAttemptAt = nil
        }
        return nil
    }

    private func recordFailure(_ failure: APIFailure, id: UUID, token: String, environment target: PushEnvironment)
        -> WidgetPushStatus?
    {
        let now = environment.clock.now()
        if case .unauthorized = failure { return .needsPairing }
        if case .server(let error) = failure, error.status == 404 {
            let retryAt = now.addingTimeInterval(Self.unsupportedWait)
            update(id, token: token, environment: target) {
                $0.nextAttemptAt = retryAt
                $0.isUnsupported = true
            }
            return .unsupported(retryAt: retryAt)
        }
        let attempt = (loadRecord(id)?.attempts ?? 0) + 1
        switch OutboxRetryPolicy.decision(for: failure, attempt: attempt) {
        case .stayQueued:
            return nil
        case .retry(let wait):
            let retryAt = now.addingTimeInterval(TimeInterval(wait.components.seconds))
            update(id, token: token, environment: target) {
                $0.attempts = attempt
                $0.nextAttemptAt = retryAt
                $0.isUnsupported = nil
            }
            return .retrying(at: retryAt)
        case .refuse:
            let problem = PushProblem(failure)
            update(id, token: token, environment: target) { $0.refusal = problem }
            return .refused(problem)
        case .pause(let pause):
            if case .serverTooOld = pause {
                let retryAt = now.addingTimeInterval(Self.unsupportedWait)
                update(id, token: token, environment: target) {
                    $0.nextAttemptAt = retryAt
                    $0.isUnsupported = true
                }
                return .unsupported(retryAt: retryAt)
            }
            return .needsPairing
        }
    }

    /// Applies `change` only while the record still belongs to the token and environment that were sent.
    private func update(
        _ id: UUID, token: String, environment target: PushEnvironment, _ change: (inout WidgetPushRecord) -> Void
    ) {
        guard var record = loadRecord(id), record.token == token, record.environment == target else { return }
        change(&record)
        save(record, for: id)
    }

    // MARK: Eligibility and files

    private func availability(of instance: Instance) -> PushAvailability {
        guard build != nil else { return .noKey }
        return instance.push ?? (instance.pushAvailable ? .available : .noKey)
    }

    private static func isValid(_ token: String) -> Bool {
        !token.isEmpty && token.utf8.count <= maximumTokenLength && token.utf8.allSatisfy(\.isHexDigit)
    }

    private func deviceToken() -> String? {
        do {
            guard
                let data = try environment.files.read(
                    InstanceFolders.widgetPushToken, maximumBytes: Self.maximumFileBytes)
            else { return nil }
            let token = try JSONDecoder().decode(WidgetPushTokenFile.self, from: data).token
            return Self.isValid(token) ? token : nil
        } catch {
            logFailure("widget token file unreadable: \(type(of: error))")
            return nil
        }
    }

    private func loadRecord(_ id: UUID) -> WidgetPushRecord? {
        do {
            guard
                let data = try environment.files.read(
                    InstanceFolders.widgetPushRecord(id), maximumBytes: Self.maximumFileBytes)
            else { return nil }
            return try JSONDecoder().decode(WidgetPushRecord.self, from: data)
        } catch {
            logFailure("widget push record unreadable: \(type(of: error))")
            return nil
        }
    }

    private func save(_ record: WidgetPushRecord?, for id: UUID) {
        guard let record else { return }
        do {
            let encoder = JSONEncoder()
            encoder.outputFormatting = .sortedKeys
            try environment.files.write(
                try encoder.encode(record), to: InstanceFolders.widgetPushRecord(id), protection: .untilFirstUnlock)
        } catch {
            logFailure("widget push record not saved: \(type(of: error))")
        }
    }
}

extension UInt8 {
    fileprivate var isHexDigit: Bool {
        (48...57).contains(self) || (65...70).contains(self) || (97...102).contains(self)
    }
}
