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
}

/// App-wide parts, built once per process, by the app and by test mode in the same way. A feature adds one stored
/// property, one line in `make` and, where it follows the instances, one line in `observers`.
@MainActor
final class AppParts {
    let storage: SharedStorage?
    let isTestMode: Bool
    let push: PushController
    /// Reloads the widgets' timelines when what they show changed.
    let widgets: WidgetReloadScheduler
    /// The pieces of the background refresh, one line per feature in `make`; their budgets stay inside
    /// `BackgroundRefresh.totalBudget`.
    private(set) var backgroundRefreshSteps: [BackgroundRefreshStep]
    /// Erases an instance's preview web data when it is unpaired or revoked, and sweeps leftovers at launch.
    let previewData: PreviewWebDataObserver
    /// The one walkthrough player: audio session, picture in picture, which scene shows it.
    let walkthroughPlayback: WalkthroughPlayback
    /// One line per observer; `AppModel` tells each after a pairing, a removal and a revoke.
    private(set) var observers: [any InstanceLifecycleObserver]

    private init(
        storage: SharedStorage?, isTestMode: Bool, push: PushController, widgets: WidgetReloadScheduler,
        steps: [BackgroundRefreshStep], previewData: PreviewWebDataObserver, walkthroughPlayback: WalkthroughPlayback,
        observers: [any InstanceLifecycleObserver]
    ) {
        self.storage = storage
        self.isTestMode = isTestMode
        self.push = push
        self.widgets = widgets
        self.backgroundRefreshSteps = steps
        self.previewData = previewData
        self.walkthroughPlayback = walkthroughPlayback
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
        let steps = [
            BackgroundRefreshStep(name: "instances", budget: .seconds(12)) { budget in
                await push.refreshInstances(budget: budget)
            },
            BackgroundRefreshStep(name: "widgets", budget: .seconds(3)) { _ in
                await widgets.backgroundRefreshFinished()
            },
        ]
        return AppParts(
            storage: storage, isTestMode: isTestMode, push: push, widgets: widgets, steps: steps,
            previewData: previewData, walkthroughPlayback: walkthroughPlayback,
            observers: [push, previewData, WidgetLifecycle(scheduler: widgets), walkthroughPlayback])
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
            .modifier(PushRoot(push: parts.push, widgets: parts.widgets, model: model))
            .environment(\.previewDefaults, parts.previewData.defaults)
            .environment(\.walkthroughPlayback, parts.walkthroughPlayback)
            .onChange(of: model.network.isOnline, initial: true) { _, isOnline in
                parts.walkthroughPlayback.networkChanged(isOnline: isOnline)
            }
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
