import CoreSpotlight
import SwiftUI
import YakKit

/// What the parts are built from: the stores the app opened and the seams tests replace.
struct AppPartsContext {
    let instances: InstanceStore
    let tokens: any TokenStore
    let makeEnvironment: @Sendable () -> SessionEnvironment
    let defaults: any KeyValueStore
    var permission: any NotificationPermission = SystemNotificationPermission()
    var center: any NotificationCenterClient = SystemNotificationCenter()
    var activityHost: any ActivityHost = SystemActivityHost()
    var normalizeAddress: @Sendable (String) -> URL? = InstanceAddress.normalize
    var uploads: UploadSessions?
    var faults = DebugFaultSwitch()
    var authenticator: any DeviceOwnerAuthenticator = LocalDeviceOwnerAuthenticator()
    var appLockBackgroundLimit: TimeInterval = AppLockPolicy.backgroundLimit
    var coversWhenActive = false
    var buildKind: any BuildKindSource = BuildKindReader()
    var spotlightWriter: any SpotlightWriter = CoreSpotlightWriter()
    var logs: any LogSource = LogReader()
}

/// App-wide parts, built once per process, by the app and by test mode in the same way. A feature adds one stored
/// property, one line in `make` and, where it follows the instances, one line in `observers`.
@MainActor
final class AppParts {
    let storage: SharedStorage?
    let isTestMode: Bool
    let push: PushController
    /// The background upload sessions; nil when the app has no files folder to keep them in.
    let uploads: UploadSessions?
    /// Reloads the widgets' timelines when what they show changed.
    let widgets: WidgetReloadScheduler
    /// The pieces of the background refresh, one line per feature in `make`; their budgets stay inside
    /// `BackgroundRefresh.totalBudget`.
    private(set) var backgroundRefreshSteps: [BackgroundRefreshStep]
    /// Erases an instance's preview web data when it is unpaired or revoked, and sweeps leftovers at launch.
    let previewData: PreviewWebDataObserver
    /// App lock, Spotlight, the debug menu and the privacy choices behind them.
    let system: SystemFeatures
    /// The one walkthrough player: audio session, picture in picture, which scene shows it.
    let walkthroughPlayback: WalkthroughPlayback
    /// One line per observer; `AppModel` tells each after a pairing, a removal and a revoke.
    private(set) var observers: [any InstanceLifecycleObserver]

    private init(
        storage: SharedStorage?, isTestMode: Bool, push: PushController, uploads: UploadSessions?,
        widgets: WidgetReloadScheduler, steps: [BackgroundRefreshStep], previewData: PreviewWebDataObserver,
        walkthroughPlayback: WalkthroughPlayback, system: SystemFeatures, observers: [any InstanceLifecycleObserver]
    ) {
        self.storage = storage
        self.isTestMode = isTestMode
        self.push = push
        self.uploads = uploads
        self.widgets = widgets
        self.backgroundRefreshSteps = steps
        self.previewData = previewData
        self.walkthroughPlayback = walkthroughPlayback
        self.system = system
        self.observers = observers
    }

    /// Opens stores and builds objects only: no network and no disk work beyond that.
    static func make(storage: SharedStorage?, isTestMode: Bool, context: AppPartsContext) -> AppParts {
        let registrar = PushRegistrar(
            instances: context.instances, tokens: context.tokens, environment: context.makeEnvironment())
        let push = PushController(
            instances: context.instances, registrar: registrar, permission: context.permission,
            center: context.center, activityHost: context.activityHost, normalize: context.normalizeAddress)
        let previewData = PreviewWebDataObserver(defaults: context.defaults)
        let walkthroughPlayback = WalkthroughPlayback.system(isOnline: { true })
        let widgetRegistration = WidgetPushRegistration(
            instances: context.instances, tokens: context.tokens, environment: context.makeEnvironment(),
            build: PushBuild.current())
        let widgets = WidgetReloadScheduler(
            instances: context.instances, registerPending: { _ = await widgetRegistration.registerPending(budget: $0) })
        let settings = PrivacySettings(store: context.defaults)
        let appLock = AppLockController(
            settings: settings, authenticator: context.authenticator, clock: SystemClock(),
            backgroundLimit: context.appLockBackgroundLimit, coversWhenActive: context.coversWhenActive,
            closePlayback: { walkthroughPlayback.close() })
        push.gate = { [weak appLock] action in
            if let appLock { appLock.whenOpen(action) } else { Task { await action() } }
        }
        let box = ModelBox()
        let spotlight = SpotlightIndexer(
            writer: context.spotlightWriter, settings: settings, appLock: appLock, store: context.defaults,
            instances: context.instances, activeLists: { await Self.activeLists(box) }, clock: SystemClock())
        let system = SystemFeatures(
            settings: settings, appLock: appLock, spotlight: spotlight,
            debug: DebugMenuModel(
                faults: context.faults, logs: context.logs, buildKind: context.buildKind, store: context.defaults,
                clock: SystemClock()),
            faults: context.faults,
            modelBox: box)
        let steps = [
            BackgroundRefreshStep(name: "instances", budget: .seconds(12)) { budget in
                await push.refreshInstances(budget: budget)
            },
            BackgroundRefreshStep(name: "uploads", budget: .seconds(7)) { budget in
                await context.uploads?.retryFailed(budget: budget)
            },
            BackgroundRefreshStep(name: "widgets", budget: .seconds(3)) { _ in
                await widgets.backgroundRefreshFinished()
            },
        ]
        return AppParts(
            storage: storage, isTestMode: isTestMode, push: push, uploads: context.uploads, widgets: widgets,
            steps: steps, previewData: previewData, walkthroughPlayback: walkthroughPlayback, system: system,
            observers: [push, previewData, WidgetLifecycle(scheduler: widgets), walkthroughPlayback, spotlight]
                + (context.uploads.map { [$0] } ?? []))
    }

    /// The active instance and its cached thread lists, read off the main actor; nil without a model, a ready
    /// instance or an active session, so then only erasures run.
    private static func activeLists(_ box: ModelBox) async -> (instance: SpotlightInstance, lists: [ThreadList])? {
        guard let model = box.model, let active = await model.instances.active(),
            await model.instances.state(of: active.id) == .ready,
            let cache = await model.sessions.session(for: active).instanceCache()
        else { return nil }
        var lists: [ThreadList] = []
        for filter in ThreadListFilter.allCases {
            if let list = await cache.threadList(filter) { lists.append(list) }
        }
        return (SpotlightInstance(id: active.id, name: active.name), lists)
    }

    /// The one place an incoming link passes: links, widget and Live Activity taps, App Intents that open the app and
    /// Home Screen quick actions all arrive here, and none opens anything while the lock is up.
    static func open(
        _ url: URL, through appLock: AppLockController, handle: @escaping @MainActor (URL) async -> Void
    ) {
        appLock.whenOpen { await handle(url) }
    }

    /// Quick actions arrive as a stream of links, not through `onOpenURL`; each goes through `open`.
    static func route(
        _ links: AsyncStream<URL>, through appLock: AppLockController, handle: @escaping @MainActor (URL) async -> Void
    ) async {
        for await url in links { open(url, through: appLock, handle: handle) }
    }

    /// The type of every part this build made, so a test can compare test mode with a release build.
    var partTypeNames: [String] {
        let parts = Mirror(reflecting: self).children.filter { $0.label != "storage" && $0.label != "isTestMode" }
        return parts.compactMap { $0.label }.sorted() + observers.map { String(describing: type(of: $0)) }
    }
}

extension View {
    /// Every scene-root modifier, one line per feature, in one place.
    func appRoot(_ parts: AppParts, model: AppModel) -> some View {
        onOpenURL { url in AppParts.open(url, through: parts.system.appLock) { await model.handle(url: $0) } }
            .task {
                await AppParts.route(QuickActions.links(), through: parts.system.appLock) {
                    await model.handle(url: $0)
                }
            }
            .environment(parts.system)
            .appLockWindow(parts.system.appLock)
            .modifier(ShortcutGate(controller: parts.system.appLock))
            .modifier(SpotlightRoot(system: parts.system, model: model))
            .modifier(PushRoot(push: parts.push, widgets: parts.widgets, model: model))
            .environment(\.previewDefaults, parts.previewData.defaults)
            .environment(\.walkthroughPlayback, parts.walkthroughPlayback)
            .onChange(of: model.network.isOnline, initial: true) { _, isOnline in
                parts.walkthroughPlayback.networkChanged(isOnline: isOnline)
            }
            .task { await parts.previewData.sweep(instances: model.instances) }
            #if DEBUG
                .overlay(alignment: .topLeading) { TestInstanceID(model: model) }
                .onOpenURL { url in
                    if let payload = TestMode.pushTap(url: url) { Task { await parts.push.didTap(payload) } }
                }
            #endif
    }
}

/// Push's scene-root work: it becomes active with the app, follows the network and asks for the next refresh when
/// the app goes to the background.
private struct PushRoot: ViewModifier {
    let push: PushController
    let widgets: WidgetReloadScheduler
    let model: AppModel
    @Environment(\.scenePhase) private var scenePhase

    func body(content: Content) -> some View {
        content
            .onChange(of: scenePhase) { _, phase in
                switch phase {
                case .active: Task { await push.appBecameActive() }
                case .background:
                    BackgroundRefresh.schedule(identifier: BackgroundRefresh.identifier)
                    Task { await widgets.enteredBackground() }
                default: break
                }
            }
            .onChange(of: model.network.isOnline) { _, isOnline in
                Task { await push.networkChanged(isOnline: isOnline) }
            }
    }
}

/// Spotlight's scene-root work: the index follows the launch, the background and an item the person taps.
private struct SpotlightRoot: ViewModifier {
    let system: SystemFeatures
    let model: AppModel

    func body(content: Content) -> some View {
        content
            .task(id: model.hasLoaded) {
                guard model.hasLoaded else { return }
                await system.spotlight.reindex()
            }
            .onReceive(NotificationCenter.default.publisher(for: UIApplication.didEnterBackgroundNotification)) { _ in
                Task { await system.spotlight.reindexInBackground() }
            }
            .onReceive(
                NotificationCenter.default.publisher(for: UIApplication.protectedDataDidBecomeAvailableNotification)
            ) {
                _ in Task { await system.spotlight.reindex() }
            }
            .onContinueUserActivity(CSSearchableItemActionType) { activity in
                guard let identifier = SpotlightIndexer.identifier(of: activity) else { return }
                system.appLock.whenOpen {
                    if let url = await system.spotlight.link(forIdentifier: identifier) { await model.handle(url: url) }
                }
            }
    }
}
