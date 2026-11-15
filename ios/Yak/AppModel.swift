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
    /// The pairing in progress for a scanned or opened link, kept while it shows a problem.
    private(set) var linkPairing: PairingModel?
    private var linkToPair: PairingLink?
    /// Counts completed link pairings, so a view that outlives the pairing screen can play the success haptic.
    private(set) var linkPairingSuccessCount = 0
    /// The host of the link being paired, for the problem screen.
    var linkHost: String? { linkToPair?.instance.host() }

    let instances: InstanceStore
    let sessions: SessionRegistry
    private let tokens: any TokenStore
    private let makeClient: @Sendable (URL) -> APIClient
    let pairing: Pairing
    private let normalizeAddress: @Sendable (String) -> URL?
    private let parseLink: @Sendable (URL) -> PairingLink?
    private let normalizeLinkAddress: @Sendable (String) -> URL?
    /// The window the app shows; links that are not pairing links go to it.
    private weak var scene: SceneModel?
    /// A link that arrived before the window chose its first screen; the cold start reads it once.
    private(set) var launchLink: DeepLink?
    /// The task page to show in the in-app browser.
    var browserURL: URL?
    /// An instance a link named that this phone is not paired with; the shell shows the connect flow for it.
    var addressToConnect: URL?
    /// Set by the shell from the window's size class, for links that open a thread.
    var isCompactWidth = true
    /// The instance whose session is running, so leaving it stops the session.
    private var runningInstance: UUID?
    /// Runs once before the first route is chosen; UI tests seed their instance here.
    private var prepare: (@Sendable (InstanceStore) async -> Void)?
    let network: NetworkMonitor
    /// How many sheets are open. The pairing link screen is presented by the topmost one, because a screen
    /// cannot present over a sheet that its own parent presented.
    var openSheetCount = 0
    /// Debug test mode only: show the waiting screen without opening the system web sign-in sheet.
    var skipsWebSession = false

    init(
        instances: InstanceStore, tokens: any TokenStore, makeClient: @escaping @Sendable (URL) -> APIClient,
        normalizeAddress: @escaping @Sendable (String) -> URL? = InstanceAddress.normalize,
        parseLink: @escaping @Sendable (URL) -> PairingLink? = { PairingLink(url: $0) },
        normalizeLinkAddress: @escaping @Sendable (String) -> URL? = DeepLink.normalizeHTTPS,
        makeEnvironment: @escaping @Sendable () -> SessionEnvironment = {
            .app(version: "0.0.0", system: "Unit test", files: InMemoryFileStore(), allowsInsecureRealtime: false)
        },
        prepare: (@Sendable (InstanceStore) async -> Void)? = nil,
        network: NetworkMonitor = NetworkMonitor()
    ) {
        self.network = network
        self.normalizeAddress = normalizeAddress
        self.parseLink = parseLink
        self.normalizeLinkAddress = normalizeLinkAddress
        self.sessions = SessionRegistry(instances: instances, tokens: tokens, makeEnvironment: makeEnvironment)
        self.prepare = prepare
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
            onPaired: { [weak self] instance in
                self?.scene?.justPairedUserName = instance.userName
                await self?.reloadRoute()
            },
            pairedInstances: { [weak self] in await self?.instances.all() ?? [] },
            normalizeAddress: normalizeAddress)
    }

    /// The model of a window; `restored` is what the window saved last time.
    func makeSceneModel(restored: Data?) -> SceneModel {
        let model = SceneModel(state: SceneModel.restoredState(from: restored))
        scene = model
        return model
    }

    /// A `yak://pair` link starts pairing up to the confirmation; the other links go to the window.
    func handle(url: URL) async {
        if let link = parseLink(url) {
            await pair(link: link)
        } else if let link = DeepLink(url: url, normalize: normalizeLinkAddress, parsePair: parseLink) {
            await handle(link: link)
        }
    }

    /// Hands the cold start the link that launched the app, once.
    func takeLaunchLink() -> DeepLink? {
        defer { launchLink = nil }
        return launchLink
    }

    func handle(link: DeepLink) async {
        guard let scene, scene.hasAppliedColdStart, case .signedIn(let active) = route else {
            if case .pair = link { return }
            launchLink = link
            return
        }
        let paired = await instances.all()
        switch scene.handle(link, active: active, instances: paired, isCompactWidth: isCompactWidth) {
        case .handled: break
        case .switchInstance(let target):
            do { try await instances.setActive(target.id) } catch { logFailure("Switching instance failed") }
            await reloadRoute()
        case .unknownInstance(let address): addressToConnect = address
        case .openTaskPage(let page): browserURL = page
        }
    }

    func pair(link: PairingLink) async {
        linkPairing?.cancel()
        wantsScanner = false
        linkToPair = link
        let pairingModel = makePairingModel()
        linkPairing = pairingModel
        await pairingModel.pair(link: link)
    }

    /// Redeems the code once the person confirmed the instance on the confirmation screen.
    func confirmLinkPairing() async {
        guard let pairingModel = linkPairing else { return }
        await pairingModel.confirm()
        if case .paired = pairingModel.state, linkPairing === pairingModel {
            linkPairing = nil
            linkPairingSuccessCount += 1
        }
    }

    /// Set when the person asks to scan again after a pairing problem; `ConnectView` takes it and opens the scanner.
    var wantsScanner = false

    /// Ends the link pairing and asks for the scanner.
    func scanAgain() {
        dismissLinkPairing()
        wantsScanner = true
    }

    /// Gives up on a redeem that takes too long without asking the instance anything else.
    func abandonLinkRedeem() {
        linkPairing?.abandonRedeem()
    }

    func retryLinkPairing() async {
        guard let link = linkToPair else { return }
        await pair(link: link)
    }

    func dismissLinkPairing() {
        linkPairing?.cancel()
        linkPairing = nil
        linkToPair = nil
    }

    func reloadRoute() async {
        if let prepare {
            self.prepare = nil
            await prepare(instances)
        }
        let active = await instances.active()
        if let running = runningInstance, running != active?.id {
            runningInstance = nil
            await sessions.stop(running)
        }
        if let active {
            // An unreadable token still shows the signed-in screen, which says so and offers a retry.
            // Only a missing token means "Pair again".
            if await instances.state(of: active.id) == .needsPairing {
                await sessions.stop(active.id)
                route = .pairAgain(active)
            } else {
                runningInstance = active.id
                route = .signedIn(active)
            }
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
        wantsScanner = false
        await reloadRoute()
    }

    func markRevoked(_ instance: Instance) async {
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
