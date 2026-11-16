import Foundation
import Observation
import YakKit

#if canImport(os)
    import os
#endif

/// What a push tap asks the shell to show.
enum PushPending: Equatable {
    case pairing(URL)
    case webPage(URL)
}

/// Everything push does for the app: permission, registration with every instance that can push to this build,
/// the badge, the foreground rule and the cleanup after an instance goes.
@MainActor @Observable
final class PushController: InstanceLifecycleObserver {
    private(set) var authorization: NotificationAuthorization = .notDetermined
    private(set) var statuses: [UUID: PushRegistrationStatus] = [:]
    private(set) var liveActivitiesEnabled = true
    /// Set by `didPair` when the explanation screen shows.
    var explanation: NotificationExplanationRequest?
    /// What a tap asks the shell to show; the shell clears it with `clearPending()` once it shows it.
    private(set) var pending: PushPending?
    /// Makes an instance the active one and shows it; the app sets it so the shell follows, and the default
    /// only stores the choice.
    @ObservationIgnored var activate: (@MainActor (Instance) async -> Void)?

    @ObservationIgnored let registrar: PushRegistrar
    @ObservationIgnored let instances: InstanceStore
    @ObservationIgnored let liveActivities: LiveActivityController
    @ObservationIgnored private let permission: any NotificationPermission
    @ObservationIgnored private let center: any NotificationCenterClient
    @ObservationIgnored private let normalize: @Sendable (String) -> URL?
    @ObservationIgnored private var visible: Set<VisibleThread> = []
    @ObservationIgnored private var statusTask: Task<Void, Never>?
    @ObservationIgnored private let linkChannel = AsyncStream<URL>.makeStream(bufferingPolicy: .unbounded)

    init(
        instances: InstanceStore, registrar: PushRegistrar, permission: any NotificationPermission,
        center: any NotificationCenterClient, activityHost: any ActivityHost = SystemActivityHost(),
        normalize: @escaping @Sendable (String) -> URL? = InstanceAddress.normalize
    ) {
        self.instances = instances
        self.liveActivities = LiveActivityController(host: activityHost, registrar: registrar, instances: instances)
        self.registrar = registrar
        self.permission = permission
        self.center = center
        self.normalize = normalize
    }

    // MARK: Lifecycle

    /// Loads the registrar's records, follows its statuses and registers with APNs when the person allows it.
    func start() async {
        if statusTask == nil {
            let stream = await registrar.statuses()
            statusTask = Task { [weak self] in
                for await next in stream { self?.statuses = next }
            }
        }
        await registrar.start()
        await liveActivities.start()
        await readSystemState()
        await registerIfAllowed()
        await refreshBadge()
    }

    func appBecameActive() async {
        await readSystemState()
        await registerIfAllowed()
        await refreshBadge()
    }

    func readSystemState() async {
        authorization = await permission.authorization()
        liveActivitiesEnabled = permission.liveActivitiesEnabled()
    }

    func registerIfAllowed() async {
        guard authorization.allowsRegistration else { return }
        permission.registerForRemoteNotifications()
        await registrar.sync()
    }

    /// The only caller of `permission.request()`: the explanation's and Settings' explicit taps reach it.
    func requestPermission() async -> NotificationRequestResult {
        let result = await permission.request()
        authorization = await permission.authorization()
        if result == .granted { await registerIfAllowed() }
        return result
    }

    func didRegister(deviceToken: Data) async {
        await registrar.setToken(PushRegistrar.hex(deviceToken), kind: .alert)
    }

    func didFailToRegister(_ error: any Error) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "Push")
                .error("APNs registration failed: \(String(describing: type(of: error)), privacy: .public)")
        #endif
    }

    func networkChanged(isOnline: Bool) async {
        await registrar.networkChanged(isOnline: isOnline)
    }

    // MARK: Foreground, visibility and badge

    func setVisible(thread id: String, instance: URL, isVisible: Bool) {
        let thread = VisibleThread(instance: instance, id: id)
        if isVisible { visible.insert(thread) } else { visible.remove(thread) }
    }

    /// How a push shows while the app is in front. It also stores the payload's count for its instance.
    func foreground(for payload: PushPayload) async -> PushForeground {
        let paired = await instances.all()
        await applyCount(of: payload, among: paired)
        await refreshBadge()
        return PushForeground.make(payload, visible: visible, instances: paired, normalize: normalize)
    }

    func applyCount(of payload: PushPayload, among paired: [Instance]) async {
        guard let yak = payload.yak, let count = yak.needsYouCount, let address = normalize(yak.instance),
            let match = InstanceMatch.instance(forNormalized: address, in: paired)
        else { return }
        do { try await instances.setNeedsYouCount(match.id, count) } catch {
            logFailure("The count was not stored: \(String(describing: type(of: error)))")
        }
    }

    /// Sets the icon badge to the sum over the instances that are ready.
    func refreshBadge() async {
        await center.setBadge(await instances.badgeCount())
    }

    // MARK: Instances

    func instancePaired(_ instance: Instance) async {
        await didPair(instance)
    }

    /// The server is told before the bearer token goes: each stored token is deleted, at most 5 seconds in all.
    func instanceWillBeRemoved(_ instance: Instance) async {
        await registrar.unregister(instance.id, budget: .seconds(5))
    }

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        await instanceRemoved(instance)
    }

    /// A fresh `me` answer can change what the instance can do for this build, so the registrar applies it.
    func instanceRefreshed(_ instance: Instance) async {
        liveActivities.instanceRefreshed(instance)
        await registrar.sync()
        await refreshBadge()
    }

    /// Local cleanup, also after the server revoked this phone.
    func instanceRemoved(_ instance: Instance) async {
        await liveActivities.endAll(instance: instance)
        let delivered = await center.delivered()
        let identifiers = PushCleanup.identifiers(in: delivered, from: instance)
        if !identifiers.isEmpty { center.remove(identifiers) }
        await registrar.forget(instance.id)
        await refreshBadge()
    }

    /// The background refresh: `me` for every instance within the budget, then the badge.
    func refreshInstances(budget: Duration) async {
        _ = await registrar.refreshInstances(budget: budget)
        await refreshBadge()
    }

    /// The links taps produce. A link yielded before anyone listens is delivered to the first listener, so a
    /// tap that launched the app is not lost; one window group listens.
    func links() -> AsyncStream<URL> { linkChannel.stream }

    /// A snapshot of an open thread: a turn that runs may start a Live Activity.
    func observe(thread id: String, instanceURL: URL, snapshot: ConversationSnapshot) async {
        guard let instance = InstanceMatch.instance(forNormalized: instanceURL, in: await instances.all()) else {
            return
        }
        await liveActivities.observe(threadID: id, instance: instance, snapshot: snapshot)
    }

    func clearPending() { pending = nil }

    /// A tap on a notification: the payload's count is stored, the badge follows, and the destination opens
    /// through the same link path as an opened link.
    func didTap(_ payload: PushPayload) async {
        let paired = await instances.all()
        await applyCount(of: payload, among: paired)
        await refreshBadge()
        guard !paired.isEmpty else {
            pending = nil
            return
        }
        guard let destination = PushDestination.make(payload, instances: paired, normalize: normalize) else { return }
        switch destination {
        case .thread, .task:
            if let link = destination.linkURL { linkChannel.continuation.yield(link) }
        case .unknownInstance(let address):
            pending = .pairing(address)
        case .webPage(let instance, let url):
            await makeActive(instance)
            pending = .webPage(url)
        case .instance(let instance):
            await makeActive(instance)
        }
    }

    private func makeActive(_ instance: Instance) async {
        if let activate {
            await activate(instance)
            return
        }
        do { try await instances.setActive(instance.id) } catch {
            logFailure("Switching instance failed: \(String(describing: type(of: error)))")
        }
    }

    private func logFailure(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "Push").error("\(message, privacy: .public)")
        #endif
    }
}
