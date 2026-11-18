import Foundation
import YakKit

extension SharedStorage {
    /// The push build values from the Info.plist, nil when a build has none.
    func pushBuild(bundle: Bundle = .main) -> PushBuild? { PushBuild.current(bundle: bundle) }

    /// The instance list in the app group. Extensions only read it.
    func instanceStore(bundle: Bundle = .main) -> InstanceStore {
        InstanceStore(defaults: defaults, tokens: tokens, now: { Date() }, build: pushBuild(bundle: bundle))
    }

    /// The real network, the app group's files, the system clock and random ids.
    func environment(appVersion: String, system: String) -> SessionEnvironment {
        SessionEnvironment(
            http: URLSessionTransport(), webSockets: URLSessionWebSocketConnector(), files: files,
            clock: SystemClock(), makeID: UUID.init, appVersion: appVersion, systemDescription: system,
            allowsInsecureRealtime: false)
    }
}
