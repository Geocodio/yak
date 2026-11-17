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
    var faults = DebugFaultSwitch()
    var authenticator: any DeviceOwnerAuthenticator = LocalDeviceOwnerAuthenticator()
    var appLockBackgroundLimit: TimeInterval = AppLockPolicy.backgroundLimit
    var coversWhenActive = false
}

/// App-wide parts, built once per process, by the app and by test mode in the same way. A feature adds one stored
/// property, one line in `make` and, where it follows the instances, one line in `observers`.
@MainActor
final class AppParts {
    let storage: SharedStorage?
    let isTestMode: Bool
    let push: PushController
    /// The pieces of the background refresh, one line per feature in `make`; their budgets stay inside
    /// `BackgroundRefresh.totalBudget`.
    private(set) var backgroundRefreshSteps: [BackgroundRefreshStep]
    /// Erases an instance's preview web data when it is unpaired or revoked, and sweeps leftovers at launch.
    let previewData: PreviewWebDataObserver
    /// App lock, Spotlight, the debug menu and the privacy choices behind them.
    let system: SystemFeatures
    /// One line per observer; `AppModel` tells each after a pairing, a removal and a revoke.
    private(set) var observers: [any InstanceLifecycleObserver]

    private init(
        storage: SharedStorage?, isTestMode: Bool, push: PushController, steps: [BackgroundRefreshStep],
        previewData: PreviewWebDataObserver, system: SystemFeatures, observers: [any InstanceLifecycleObserver]
    ) {
        self.storage = storage
        self.isTestMode = isTestMode
        self.push = push
        self.backgroundRefreshSteps = steps
        self.previewData = previewData
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
        let settings = PrivacySettings(store: context.defaults)
        let appLock = AppLockController(
            settings: settings, authenticator: context.authenticator, clock: SystemClock(),
            backgroundLimit: context.appLockBackgroundLimit, coversWhenActive: context.coversWhenActive)
        let system = SystemFeatures(settings: settings, appLock: appLock, faults: context.faults, modelBox: ModelBox())
        let steps = [
            BackgroundRefreshStep(name: "instances", budget: .seconds(12)) { budget in
                await push.refreshInstances(budget: budget)
            }
        ]
        return AppParts(
            storage: storage, isTestMode: isTestMode, push: push, steps: steps,
            previewData: previewData, system: system, observers: [push, previewData])
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
        onOpenURL { url in Task { await model.handle(url: url) } }
            .environment(parts.system)
            .appLockWindow(parts.system.appLock)
            .modifier(PushRoot(push: parts.push, model: model, schedulesRefresh: !parts.isTestMode))
            .environment(\.previewDefaults, parts.previewData.defaults)
            .task { await parts.previewData.sweep(instances: model.instances) }
            #if DEBUG
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
    let model: AppModel
    /// Test mode registers no background task, and submitting one that is not registered aborts the app.
    let schedulesRefresh: Bool
    @Environment(\.scenePhase) private var scenePhase

    func body(content: Content) -> some View {
        content
            .onChange(of: scenePhase) { _, phase in
                switch phase {
                case .active: Task { await push.appBecameActive() }
                case .background:
                    if schedulesRefresh { BackgroundRefresh.schedule(identifier: BackgroundRefresh.identifier) }
                default: break
                }
            }
            .onChange(of: model.network.isOnline) { _, isOnline in
                Task { await push.networkChanged(isOnline: isOnline) }
            }
    }
}
