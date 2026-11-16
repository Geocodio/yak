import Foundation
import Observation
import YakKit

#if canImport(os)
    import os
#endif

/// Everything push does for the app: permission, registration with every instance that can push to this build,
/// the badge, the foreground rule and the cleanup after an instance goes.
@MainActor @Observable
final class PushController: InstanceLifecycleObserver {
    private(set) var authorization: NotificationAuthorization = .notDetermined
    private(set) var statuses: [UUID: PushRegistrationStatus] = [:]
    private(set) var liveActivitiesEnabled = true

    @ObservationIgnored let registrar: PushRegistrar
    @ObservationIgnored private let instances: InstanceStore
    @ObservationIgnored private let permission: any NotificationPermission
    @ObservationIgnored private let center: any NotificationCenterClient
    @ObservationIgnored private let normalize: @Sendable (String) -> URL?
    @ObservationIgnored private var visible: Set<VisibleThread> = []
    @ObservationIgnored private var statusTask: Task<Void, Never>?

    init(
        instances: InstanceStore, registrar: PushRegistrar, permission: any NotificationPermission,
        center: any NotificationCenterClient,
        normalize: @escaping @Sendable (String) -> URL? = InstanceAddress.normalize
    ) {
        self.instances = instances
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
        await readSystemState()
        await registerIfAllowed()
        await refreshBadge()
    }

    func appBecameActive() async {
        await readSystemState()
        await registerIfAllowed()
        await refreshBadge()
    }

    private func readSystemState() async {
        authorization = await permission.authorization()
        liveActivitiesEnabled = permission.liveActivitiesEnabled()
    }

    private func registerIfAllowed() async {
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
        await registrar.sync()
    }

    /// The server is told before the bearer token goes: each stored token is deleted, at most 5 seconds in all.
    func instanceWillBeRemoved(_ instance: Instance) async {
        await registrar.unregister(instance.id, budget: .seconds(5))
    }

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        await instanceRemoved(instance)
    }

    func instanceRefreshed(_ instance: Instance) async {
        await refreshBadge()
    }

    /// Local cleanup, also after the server revoked this phone.
    func instanceRemoved(_ instance: Instance) async {
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

    /// A tap on a notification: the payload's count is stored and the badge follows.
    func didTap(_ payload: PushPayload) async {
        await applyCount(of: payload, among: await instances.all())
        await refreshBadge()
    }

    private func logFailure(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "Push").error("\(message, privacy: .public)")
        #endif
    }
}
