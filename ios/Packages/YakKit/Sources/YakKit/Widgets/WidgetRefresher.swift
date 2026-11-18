import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "WidgetRefresher").error("\(message, privacy: .public)")
    #endif
}

/// The case and code of a failure, never a message or a detail.
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

/// What a widget or an intent shows after a refresh.
public struct WidgetRefresh: Equatable, Sendable {
    public let instance: Instance?
    /// Fresh, or the saved one when the load failed.
    public let snapshot: WidgetSnapshot?
    public let state: WidgetState
    public let isFresh: Bool

    public init(instance: Instance?, snapshot: WidgetSnapshot?, state: WidgetState, isFresh: Bool) {
        self.instance = instance
        self.snapshot = snapshot
        self.state = state
        self.isFresh = isFresh
    }
}

/// Loads the person's own thread list for one instance and keeps the widget snapshot current. It reads the
/// instance list and the token and writes only the snapshot file.
public struct WidgetRefresher: Sendable {
    private let instances: InstanceStore
    private let tokens: any TokenStore
    private let environment: SessionEnvironment
    private let isStillPaired: @Sendable (UUID) async -> Bool

    /// `isStillPaired` is asked before a snapshot is saved; a caller whose instance list may change in another
    /// process passes one that reads the stored list afresh. It defaults to `instances`.
    public init(
        instances: InstanceStore, tokens: any TokenStore, environment: SessionEnvironment,
        isStillPaired: (@Sendable (UUID) async -> Bool)? = nil
    ) {
        self.instances = instances
        self.tokens = tokens
        self.environment = environment
        self.isStillPaired = isStillPaired ?? { id in await instances.all().contains { $0.id == id } }
    }

    /// The configured instance, or the most recently used one when `instance` is nil.
    /// Loads `GET threads?filter=mine` within `budget`, writes the snapshot, and never writes anything else.
    public func refresh(instance: UUID?, budget: Duration) async -> WidgetRefresh {
        switch await resolve(instance) {
        case .done(let result): return result
        case .ready(let target, let saved):
            guard let token = readToken(for: target) else {
                return WidgetRefresh(
                    instance: target, snapshot: saved, state: unreadableState(target, saved), isFresh: false)
            }
            let client = APIClient(
                baseURL: target.url, token: token, transport: environment.http,
                appVersion: environment.appVersion, systemDescription: environment.systemDescription,
                sleep: { [clock = environment.clock] in try await clock.sleep(for: $0) })
            let outcome = await withBudget(budget, clock: environment.clock) { () async -> Loaded in
                do throws(APIFailure) {
                    return .list(try await client.send(.threads(filter: .mine, search: nil)))
                } catch {
                    return .failure(error)
                }
            }
            return await finish(outcome, target: target, saved: saved)
        }
    }

    /// The saved snapshot only, for the system's placeholder and for intents that must not wait.
    public func saved(instance: UUID?) async -> WidgetRefresh {
        switch await resolve(instance) {
        case .done(let result): return result
        case .ready(let target, let saved):
            return WidgetRefresh(
                instance: target, snapshot: saved, state: saved == nil ? .notLoaded(host: host(target)) : .content,
                isFresh: false)
        }
    }

    // MARK: Steps

    private enum Resolution {
        case done(WidgetRefresh)
        case ready(Instance, saved: WidgetSnapshot?)
    }

    private enum Loaded: Sendable {
        case list(ThreadList)
        case failure(APIFailure)
    }

    private func resolve(_ instance: UUID?) async -> Resolution {
        let all = await instances.all()
        guard let first = all.first else {
            return .done(WidgetRefresh(instance: nil, snapshot: nil, state: .noInstance, isFresh: false))
        }
        let target: Instance
        if let instance {
            guard let found = all.first(where: { $0.id == instance }) else {
                return .done(WidgetRefresh(instance: nil, snapshot: nil, state: .instanceMissing, isFresh: false))
            }
            target = found
        } else {
            target = first
        }
        let state = await instances.state(of: target.id)
        if case .needsPairing = state {
            return .done(
                WidgetRefresh(instance: target, snapshot: nil, state: .needsPairing(host: host(target)), isFresh: false)
            )
        }
        let saved = await Cache(instance: target.id, files: environment.files, clock: environment.clock)
            .widgetSnapshot()
        if state == .tokenUnreadable {
            return .done(
                WidgetRefresh(instance: target, snapshot: saved, state: unreadableState(target, saved), isFresh: false))
        }
        return .ready(target, saved: saved)
    }

    private func finish(_ outcome: Loaded?, target: Instance, saved: WidgetSnapshot?) async -> WidgetRefresh {
        switch outcome {
        case .list(let list):
            guard await isStillPaired(target.id) else {
                return WidgetRefresh(instance: nil, snapshot: nil, state: .instanceMissing, isFresh: false)
            }
            let snapshot = WidgetSnapshot.make(
                from: list, instanceName: target.name, now: environment.clock.now())
            await Cache(instance: target.id, files: environment.files, clock: environment.clock)
                .saveWidgetSnapshot(snapshot)
            return WidgetRefresh(instance: target, snapshot: snapshot, state: .content, isFresh: true)
        case .failure(.unauthorized):
            return WidgetRefresh(
                instance: target, snapshot: nil, state: .needsPairing(host: host(target)), isFresh: false)
        case .failure(let failure):
            logFailure("refresh failed: \(describe(failure))")
        case nil:
            logFailure("refresh ended by its budget")
        }
        return WidgetRefresh(
            instance: target, snapshot: saved, state: saved == nil ? .notLoaded(host: host(target)) : .content,
            isFresh: false)
    }

    private func unreadableState(_ target: Instance, _ saved: WidgetSnapshot?) -> WidgetState {
        saved == nil ? .notLoaded(host: host(target)) : .content
    }

    private func host(_ instance: Instance) -> String { instance.url.host ?? instance.url.absoluteString }

    private func readToken(for instance: Instance) -> String? {
        do {
            guard let token = try tokens.token(for: instance.id), !token.isEmpty else { return nil }
            return token
        } catch {
            logFailure("token unreadable: \(type(of: error))")
            return nil
        }
    }
}
