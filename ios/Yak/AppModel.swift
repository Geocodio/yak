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

    enum RefreshState: Equatable { case idle, loading, failed }

    private(set) var route: Route = .connect
    private(set) var refreshState: RefreshState = .idle
    private(set) var hasLoaded = false

    let instances: InstanceStore
    private let tokens: any TokenStore
    private let makeClient: @Sendable (URL) -> APIClient
    let pairing: Pairing

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
        let token: String?
        do { token = try tokens.token(for: instance.id) } catch { token = nil }
        guard let token else {
            await markRevoked(instance)
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
            if case .unauthorized(let code) = failure, code == "device_revoked" || code == "invalid_token" {
                await markRevoked(instance)
            } else {
                refreshState = .failed
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

    private func logFailure(_ message: StaticString) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "AppModel").error("\(message)")
        #endif
    }
}
