import Foundation
import Observation
import YakKit

#if canImport(os)
    import os
#endif

/// Decides which screen the app shows, from the instances this phone is paired with.
@MainActor @Observable
final class AppModel {
    enum Route: Equatable {
        case connect
        case signedIn(Instance)
        case pairAgain(Instance)
    }

    /// Why the last refresh of "who am I" did not work, each with its own message.
    enum RefreshState: Equatable {
        case idle, loading, unreachable, appTooOld, serverTooOld, tokenUnreadable, failed
    }

    private(set) var route: Route = .connect
    private(set) var refreshState: RefreshState = .idle
    private(set) var hasLoaded = false

    let instances: InstanceStore
    private let tokens: any TokenStore
    private let makeClient: @Sendable (URL) -> APIClient
    let pairing: Pairing
    let network = NetworkMonitor()

    init(instances: InstanceStore, tokens: any TokenStore, makeClient: @escaping @Sendable (URL) -> APIClient) {
        self.instances = instances
        self.tokens = tokens
        self.makeClient = makeClient
        self.pairing = Pairing(
            makeClient: makeClient, instances: instances, sleep: { try await Task.sleep(for: $0) })
    }

    /// The model for a pairing screen; it refreshes the route once pairing succeeds.
    func makePairingModel() -> PairingModel {
        PairingModel(
            pairing: pairing, device: { DeviceDescription.current() },
            onPaired: { [weak self] _ in await self?.reloadRoute() })
    }

    func reloadRoute() async {
        if let active = await instances.active() {
            route = await instances.state(of: active.id) == .ready ? .signedIn(active) : .pairAgain(active)
        } else {
            route = .connect
        }
        hasLoaded = true
    }

    /// Asks the instance who this phone is. A revoked or unknown token sends the person to
    /// "Pair again"; any other failure keeps the saved copy and offers a retry.
    func refreshMe(for instance: Instance) async {
        refreshState = .loading
        let token: String
        do {
            // Only a missing token means "pair again". A read error (Keychain locked before the
            // first unlock, for example) is temporary, so the token stays.
            guard let stored = try tokens.token(for: instance.id) else {
                await markRevoked(instance)
                return
            }
            token = stored
        } catch {
            logFailure("Reading the token failed: \(type(of: error))")
            refreshState = .tokenUnreadable
            return
        }
        do {
            let me = try await makeClient(instance.url).authenticated(token: token).send(.me())
            try await instances.update(instance.id, from: me)
            refreshState = .idle
            if let updated = await instances.all().first(where: { $0.id == instance.id }) {
                route = .signedIn(updated)
            }
        } catch let failure as APIFailure {
            switch failure {
            case .unauthorized(let code) where code == "device_revoked" || code == "invalid_token":
                await markRevoked(instance)
            case .unauthorized, .server, .notYak, .encodingFailed: refreshState = .failed
            case .network: refreshState = .unreachable
            case .appTooOld: refreshState = .appTooOld
            case .serverTooOld: refreshState = .serverTooOld
            }
        } catch {
            refreshState = .failed
        }
    }

    func remove(_ instance: Instance) async {
        do { try await instances.remove(instance.id) } catch { logFailure("Removing an instance failed") }
        refreshState = .idle
        await reloadRoute()
    }

    private func markRevoked(_ instance: Instance) async {
        do { try await instances.markRevoked(instance.id) } catch { logFailure("Marking revoked failed") }
        refreshState = .idle
        await reloadRoute()
    }

    private func logFailure(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "AppModel").error("\(message, privacy: .public)")
        #endif
    }
}
