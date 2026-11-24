import SwiftUI
import YakKit

#if canImport(os)
    import os
#endif

@main
struct YakApp: App {
    @UIApplicationDelegateAdaptor(PushAppDelegate.self) private var delegate
    @State private var model: AppModel
    private let parts: AppParts

    init() {
        WebQuickLook.removeStaleFolders()
        let version = DeviceDescription.appVersion()
        let system = DeviceDescription.current().systemDescription
        #if DEBUG
            if let testMode = TestMode.current() {
                GestureTips.configure(TestSystemOptions.current().tips == .show ? .shown : .hidden)
                let made = testMode.make(version: version, system: system)
                self.parts = made.parts
                Self.attachLaunchWork(made.parts)
                _model = State(initialValue: made.model)
                return
            }
        #endif
        let storage = SharedStorage.open()
        if let storage {
            _ = storage.migrateFromStandardStores()
        } else {
            #if canImport(os)
                Logger(subsystem: "Yak", category: "Startup")
                    .error("No app group storage; the standard stores are used")
            #endif
        }
        let tokens = storage?.tokens ?? KeychainTokenStore(accessGroup: nil)
        let defaults: any KeyValueStore = storage?.defaults ?? UserDefaults.standard
        let instances = InstanceStore(defaults: defaults, tokens: tokens, now: { Date() }, build: PushBuild.current())
        let showTipsAgain = defaults.data(forKey: DebugMenuModel.showTipsKey) != nil
        if showTipsAgain { defaults.set(nil, forKey: DebugMenuModel.showTipsKey) }
        GestureTips.configure(showTipsAgain ? .resetThenNormal : .normal)
        Task {
            do { try await instances.persistMissingTints() } catch {
                #if canImport(os)
                    Logger(subsystem: "Yak", category: "Startup")
                        .error(
                            "Instance tints were not saved: \(String(describing: type(of: error)), privacy: .public)")
                #endif
            }
        }
        let files: any FileStore = storage?.files ?? SessionEnvironment.releaseFiles()
        let uploads = storage.map {
            UploadSessions(
                appBundleID: $0.appBundleID, appGroup: $0.appGroup, files: $0.files, clock: SystemClock(),
                instances: instances, tokens: tokens, appVersion: version, systemDescription: system)
        }
        let faults = DebugFaultSwitch()
        let environment: @Sendable () -> SessionEnvironment = {
            .app(
                version: version, system: system, files: files, allowsInsecureRealtime: false, faults: faults,
                uploads: uploads.map { sessions in { @Sendable (instance: UUID) in sessions.link(for: instance) } })
        }
        let parts = AppParts.make(
            storage: storage, isTestMode: false,
            context: AppPartsContext(
                instances: instances, tokens: tokens, makeEnvironment: environment, defaults: defaults,
                uploads: uploads, faults: faults))
        self.parts = parts
        Self.attachLaunchWork(parts)
        BackgroundRefresh.register(identifier: BackgroundRefresh.identifier) { parts.backgroundRefreshSteps }
        let model = AppModel(
            instances: instances, tokens: tokens,
            makeClient: { url in
                APIClient(
                    baseURL: url, token: nil, transport: URLSessionTransport(), appVersion: version,
                    systemDescription: system)
            },
            makeEnvironment: environment, observers: parts.observers, push: parts.push, widgets: parts.widgets)
        parts.system.modelBox.model = model
        _model = State(initialValue: model)
    }

    /// Runs for every launch, with or without a scene, so a background relaunch for an upload session reaches the
    /// sessions.
    private static func attachLaunchWork(_ parts: AppParts) {
        PushAppDelegate.register(launchUploads: parts.uploads)
        parts.uploads?.removeExpiredStaging()
    }

    var body: some Scene {
        WindowGroup(for: WindowRequest.self) { $request in
            #if DEBUG
                if TestMode.value("yakTestScreen") == "live-activity-gallery" {
                    LiveActivityGallery(
                        stateName: ProcessInfo.processInfo.environment["YAK_TEST_GALLERY_STATE"] ?? "working")
                } else if let shareCase = TestMode.value("yakTestShare") {
                    ShareTestHost(caseName: shareCase).preferredColorScheme(TestMode.colorScheme())
                } else if TestMode.value("yakTestScreen") == "widget-gallery" {
                    WidgetGallery(
                        stateName: ProcessInfo.processInfo.environment["YAK_TEST_GALLERY_STATE"] ?? "needs-you")
                } else {
                    rootView($request).preferredColorScheme(TestMode.colorScheme())
                        .overlay(alignment: .top) {
                            if let intent = TestMode.intentName { IntentTestResultView(name: intent) }
                        }
                }
            #else
                rootView($request)
            #endif
        }
        .commands { YakCommands() }
    }

    private func rootView(_ request: Binding<WindowRequest?>) -> some View {
        RootView(model: model, request: request, appLock: parts.system.appLock)
            // An open window takes a link, a widget tap or an activity; none of them opens another window.
            .handlesExternalEvents(preferring: ["*"], allowing: ["*"])
            .appRoot(parts, model: model)
            .task { model.followQuickActions(through: parts.system.appLock) }
            .task {
                delegate.attach(parts.push)
                delegate.attach(uploads: parts.uploads)
                await parts.push.start()
            }
    }
}
