import Foundation
import Observation
import SwiftUI
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
    /// The running session of the signed-in instance; set together with the route.
    private(set) var activeSession: InstanceSession?
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
    /// A window the app shows, held weakly.
    private struct WeakScene { weak var scene: SceneModel? }
    private var scenes: [WeakScene] = []
    private weak var lastActiveScene: SceneModel?
    /// The window links, pushes, quick actions and continued activities go to: the most recently active one that is
    /// still connected, else the last one that connected. They never open a window of their own.
    var routingScene: SceneModel? { lastActiveScene ?? scenes.last { $0.scene != nil }?.scene }

    /// A window that is in front becomes the routing one. A window that is missing from the list (it was removed
    /// and is back in front) is registered again.
    func sceneBecameActive(_ scene: SceneModel) {
        prune()
        if !scenes.contains(where: { $0.scene === scene }) { scenes.append(WeakScene(scene: scene)) }
        lastActiveScene = scene
    }

    /// A window leaves the list when its scene really disconnects: nothing else holds its model then, and the weak
    /// reference empties. A screen going away, or a cover over the window, never removes it. `prune` clears what the
    /// emptied references left behind.
    private func prune() {
        scenes.removeAll { $0.scene == nil }
        let live = Set(scenes.compactMap(\.scene).map(ObjectIdentifier.init))
        phases = phases.filter { live.contains($0.key) }
    }

    /// Removes a window at once (a scene that disconnected while something still holds its model).
    func sceneDisconnected(_ scene: SceneModel) {
        scenes.removeAll { $0.scene == nil || $0.scene === scene }
        phases[ObjectIdentifier(scene)] = nil
        if lastActiveScene === scene { lastActiveScene = nil }
        if presentingScene === scene { presentingScene = nil }
        if explanationPresenter === scene { explanationPresenter = nil }
    }

    @ObservationIgnored private var hasAnnouncedActive = false
    @ObservationIgnored private var hasAnnouncedBackground = false

    /// True for the first window to ask after the app became active, so work that belongs to the app (the push
    /// registration) runs once, not once per window.
    func claimActiveAnnouncement() -> Bool {
        defer { hasAnnouncedActive = true }
        return !hasAnnouncedActive
    }

    /// True for the first window to ask after the app went to the background, so the Spotlight refresh runs once.
    func claimBackgroundAnnouncement() -> Bool {
        defer { hasAnnouncedBackground = true }
        return !hasAnnouncedBackground
    }

    /// What a window's phase did to the app as a whole.
    enum AppPhaseChange: Equatable { case none, enteredBackground, enteredForeground }

    @ObservationIgnored private var phases: [ObjectIdentifier: ScenePhase] = [:]
    /// Whether every window is in the background; the live updates stop only then.
    private(set) var isInBackground = false

    /// A window's phase changed. The app goes to the background when every window is there, and live updates and the
    /// feed pause only then; one window leaving never pauses another that is still in front.
    @discardableResult
    func scenePhaseChanged(_ scene: SceneModel, to phase: ScenePhase) async -> AppPhaseChange {
        prune()
        phases[ObjectIdentifier(scene)] = phase
        switch phase {
        case .active:
            sceneBecameActive(scene)
            hasAnnouncedBackground = false
            let wasInBackground = isInBackground
            isInBackground = false
            await sessions.enterForeground()
            return wasInBackground ? .enteredForeground : .none
        case .background:
            let isEveryWindowInBackground = scenes.compactMap(\.scene).allSatisfy {
                phases[ObjectIdentifier($0)] == .background
            }
            guard isEveryWindowInBackground, !isInBackground else { return .none }
            isInBackground = true
            hasAnnouncedActive = false
            BackgroundRefresh.schedule(identifier: BackgroundRefresh.identifier)
            await sessions.enterBackground()
            await widgets?.enteredBackground()
            return .enteredBackground
        default:
            return .none
        }
    }

    @ObservationIgnored private var quickActionsTask: Task<Void, Never>?

    /// Turns the Home Screen quick actions into links, once for the app, each through the lock's gate. Every window calls it; only the first starts
    /// the loop, and closing a window never ends it.
    func followQuickActions(through appLock: AppLockController) {
        guard quickActionsTask == nil else { return }
        quickActionsTask = Task { [weak self] in
            await AppParts.route(QuickActions.links(), through: appLock) { await self?.handle(url: $0) }
        }
    }

    /// A link, widget tap or activity a window received: it opens in that window, and only once the lock is open.
    func receive(url: URL, in scene: SceneModel?, through appLock: AppLockController) {
        AppParts.open(url, through: appLock) { [weak self, weak scene] url in
            if let scene { await self?.handle(url: url, in: scene) } else { await self?.handle(url: url) }
        }
    }

    /// How many windows are registered.
    var connectedSceneCount: Int { scenes.compactMap(\.scene).count }

    /// A link that arrived before the window chose its first screen; the cold start reads it once.
    private(set) var launchLink: DeepLink?
    /// The task page to show in the in-app browser.
    var browserURL: URL? { didSet { claimPresenter() } }
    /// A task link or push waiting for the shell to open its native screen.
    var taskToOpen: String?
    /// An instance a link named that this phone is not paired with; the shell shows the connect flow for it.
    var addressToConnect: URL? { didSet { claimPresenter() } }
    /// The window that shows the browser and the connect sheet: the routing one when they opened. They are app-wide
    /// values and show in one window only.
    @ObservationIgnored private weak var presentingScene: SceneModel?

    private func claimPresenter() {
        if browserURL == nil, addressToConnect == nil {
            presentingScene = nil
        } else if presentingScene == nil {
            presentingScene = routingScene
        }
    }

    /// The window that shows the notification explanation, claimed when it first shows.
    @ObservationIgnored private weak var explanationPresenter: SceneModel?

    /// Whether the notification explanation shows in this window: the routing one when it started to show, and the
    /// same one until it is dismissed, so it never moves between windows.
    func presentsExplanation(in scene: SceneModel, isShowing: Bool) -> Bool {
        guard isShowing else {
            explanationPresenter = nil
            return false
        }
        if explanationPresenter == nil { explanationPresenter = routingScene }
        return explanationPresenter === scene
    }

    /// Whether the app-wide sheets (the browser and the connect sheet) show in this window.
    func presentsSheets(in scene: SceneModel) -> Bool { (presentingScene ?? routingScene) === scene }

    /// The task id a link or push waits to open, taken by the routing window only.
    func takeTaskToOpen(for scene: SceneModel) -> String? {
        guard routingScene === scene, let id = taskToOpen else { return nil }
        taskToOpen = nil
        return id
    }

    /// What a push tap asks a screen to show, taken by the routing window only.
    func takePendingPush(for scene: SceneModel) -> PushPending? {
        guard routingScene === scene, let pending = push?.pending else { return nil }
        push?.clearPending()
        return pending
    }
    /// Set by the shell from the window's size class, for links that open a thread.
    var isCompactWidth = true
    /// The instance whose session is running, so leaving it stops the session.
    private var runningInstance: UUID?
    /// Runs once before the first route is chosen; UI tests seed their instance here.
    private var prepare: (@Sendable (InstanceStore) async -> Void)?
    let network: NetworkMonitor
    private let observers: InstanceObservers
    /// Push: the badge and the foreground rule. Nil in unit tests that do not need it.
    let push: PushController?
    /// The widgets' reloads. Nil in unit tests that do not need it.
    let widgets: WidgetReloadScheduler?
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
        network: NetworkMonitor = NetworkMonitor(),
        observers: [any InstanceLifecycleObserver] = [],
        observerTimeout: Duration = .seconds(5),
        push: PushController? = nil,
        widgets: WidgetReloadScheduler? = nil
    ) {
        self.push = push
        self.widgets = widgets
        self.observers = InstanceObservers(observers: observers, timeout: observerTimeout)
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
        push?.activate = { [weak self] instance in await self?.activate(instance) }
        push?.openLink = { [weak self] link in await self?.handle(url: link) }
    }

    /// A push tap names another instance: it becomes the active one and the route follows.
    func activate(_ instance: Instance) async {
        guard await instances.active()?.id != instance.id else { return }
        do { try await instances.setActive(instance.id) } catch {
            logFailure("Switching instance failed")
            return
        }
        await reloadRoute()
    }

    /// The model for a pairing screen; it refreshes the route once pairing succeeds.
    func makePairingModel() -> PairingModel {
        PairingModel(
            pairing: pairing, device: { DeviceDescription.current() },
            onPaired: { [weak self] instance in
                self?.routingScene?.justPairedUserName = instance.userName
                await self?.reloadRoute()
                await self?.observers.paired(instance)
            },
            pairedInstances: { [weak self] in await self?.instances.all() ?? [] },
            normalizeAddress: normalizeAddress)
    }

    /// The model of a window; `restored` is what the window saved last time.
    func makeSceneModel(restored: Data?, request: WindowRequest? = nil) -> SceneModel {
        let state = SceneModel.restoredState(from: restored)
        let model: SceneModel
        if case .signedIn(let active) = route {
            model = SceneModel(state: state, request: request, activeInstance: active.id)
        } else {
            model = SceneModel(state: state)
        }
        scenes.removeAll { $0.scene == nil }
        scenes.append(WeakScene(scene: model))
        return model
    }

    /// A link a window received: that window takes it, because it is the one the event reached.
    func handle(url: URL, in scene: SceneModel) async {
        sceneBecameActive(scene)
        await handle(url: url)
    }

    /// A `yak://pair` link starts pairing up to the confirmation; the other links go to the window.
    func handle(url: URL) async {
        let url = InstanceMatch.pairedLink(url, instances: await instances.all(), normalize: normalizeLinkAddress)
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
        guard let scene = routingScene, scene.hasAppliedColdStart, case .signedIn(let active) = route else {
            if case .pair = link { return }
            if hasLoaded, case .connect = route {
                switch link {
                case .thread(let address, _), .task(let address, _), .newThread(let address, _, _):
                    addressToConnect = address
                    return
                case .pair: break
                }
            }
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
        case .openTaskPage(let page): openTask(page: page)
        case .switchInstanceThenOpenTaskPage(let target, let page):
            do { try await instances.setActive(target.id) } catch { logFailure("Switching instance failed") }
            await reloadRoute()
            openTask(page: page)
        }
    }

    /// A task page of a paired instance: the native screen, or the browser when the id is not a native one.
    /// The address always comes from a paired instance, and the browser takes only a safe https address.
    private func openTask(page: URL) {
        if let id = TaskLink.id(from: page) {
            taskToOpen = id
        } else {
            browserURL = WebLinkRule.safeURL(page.absoluteString)
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
            activeSession = nil
            await sessions.stop(running)
        }
        if let active {
            // An unreadable token still shows the signed-in screen, which says so and offers a retry.
            // Only a missing token means "Pair again".
            if await instances.state(of: active.id) == .needsPairing {
                await sessions.stop(active.id)
                activeSession = nil
                route = .pairAgain(active)
            } else {
                runningInstance = active.id
                // A window that belongs to another instance starts over; a link switch already claimed it.
                for scene in scenes.compactMap(\.scene) {
                    if scene.state.instanceID != active.id {
                        scene.reset(instanceID: active.id)
                    } else {
                        scene.instanceChanged(to: active.id)
                    }
                }
                activeSession = sessions.session(for: active)
                route = .signedIn(active)
            }
        } else {
            activeSession = nil
            route = .connect
        }
        hasLoaded = true
    }

    /// Asks the instance who this phone is. A revoked or unknown token sends the person to
    /// "Pair again"; any other failure keeps the saved copy and offers a retry.
    func refreshMe(for instance: Instance) async {
        // Refreshing an instance other than the signed-in one only updates its stored count; the screen
        // keeps showing the signed-in instance.
        let isBackground: Bool = {
            if case .signedIn(let current) = route { return current.id != instance.id }
            return false
        }()
        func show(_ next: RefreshState) { if !isBackground { refreshState = next } }
        show(.loading)
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
            show(.tokenUnreadable)
            return
        }
        do {
            let me = try await makeClient(instance.url).authenticated(token: token).send(.me())
            try await instances.update(instance.id, from: me)
            show(.idle)
            if let updated = await instances.all().first(where: { $0.id == instance.id }) {
                if !isBackground { route = .signedIn(updated) }
                await observers.refreshed(updated)
            }
        } catch let failure as APIFailure {
            switch failure {
            case .unauthorized(let code) where code == "device_revoked" || code == "invalid_token":
                await markRevoked(instance)
            case .unauthorized, .server, .notYak, .encodingFailed: show(.failed)
            case .network: show(.unreachable)
            case .appTooOld: show(.appTooOld)
            case .serverTooOld: show(.serverTooOld)
            }
        } catch {
            show(.failed)
        }
    }

    /// The person removes an instance: the parts that registered something tell the server first, then the session
    /// tells the server this device is gone and erases every local copy.
    func remove(_ instance: Instance) async {
        await observers.willRemove(instance, removalTimeout: Self.removalTimeout)
        await sessions.existingSession(for: instance.id)?.eraseOfflineCopies()
        await sessions.session(for: instance).unpair()
        if await instances.all().contains(where: { $0.id == instance.id }) {
            do { try await instances.remove(instance.id) } catch { logFailure("Removing an instance failed") }
        }
        refreshState = .idle
        wantsScanner = false
        await reloadRoute()
        await observers.removed(instance, reason: .unpaired)
    }

    func markRevoked(_ instance: Instance) async {
        do { try await instances.markRevoked(instance.id) } catch { logFailure("Marking revoked failed") }
        // Before the route changes, which stops the session: its copy store must refuse every later write.
        await sessions.existingSession(for: instance.id)?.eraseOfflineCopies()
        refreshState = .idle
        await reloadRoute()
        await observers.removed(instance, reason: .revoked)
    }

    /// Five seconds for the token deletions and a second of margin.
    static let removalTimeout: Duration = .seconds(6)

    private func logFailure(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "AppModel").error("\(message, privacy: .public)")
        #endif
    }
}
