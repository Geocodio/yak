import Foundation
import Observation
import YakKit

/// One `InstanceSession` per paired instance, started when it is first asked for and stopped when the person
/// leaves the instance.
@MainActor @Observable
final class SessionRegistry {
    @ObservationIgnored private let instances: InstanceStore
    @ObservationIgnored private let tokens: any TokenStore
    @ObservationIgnored private let makeEnvironment: @Sendable () -> SessionEnvironment
    @ObservationIgnored private var sessions: [UUID: InstanceSession] = [:]

    init(
        instances: InstanceStore, tokens: any TokenStore, makeEnvironment: @escaping @Sendable () -> SessionEnvironment
    ) {
        self.instances = instances
        self.tokens = tokens
        self.makeEnvironment = makeEnvironment
    }

    /// The session of `instance`; the first call creates it and starts it.
    func session(for instance: Instance) -> InstanceSession {
        if let existing = sessions[instance.id] { return existing }
        let session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens, environment: makeEnvironment())
        sessions[instance.id] = session
        Task { await session.start() }
        return session
    }

    func stop(_ instance: UUID) async {
        guard let session = sessions.removeValue(forKey: instance) else { return }
        await session.stop()
    }

    func enterBackground() async {
        for session in Array(sessions.values) { await session.enterBackground() }
    }

    func enterForeground() async {
        for session in Array(sessions.values) {
            await session.enterForeground()
            await session.retryFailedUploads()
        }
    }

    func networkChanged(isOnline: Bool) async {
        for session in Array(sessions.values) { await session.networkPathChanged(isSatisfied: isOnline) }
    }
}
