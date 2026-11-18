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
        let version = DeviceDescription.appVersion()
        let system = DeviceDescription.current().systemDescription
        #if DEBUG
            if let testMode = TestMode.current() {
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
        let files: any FileStore = storage?.files ?? SessionEnvironment.releaseFiles()
        let uploads = storage.map {
            UploadSessions(
                appBundleID: $0.appBundleID, appGroup: $0.appGroup, files: $0.files, clock: SystemClock(),
                instances: instances, tokens: tokens, appVersion: version, systemDescription: system)
        }
        let environment: @Sendable () -> SessionEnvironment = {
            .app(
                version: version, system: system, files: files, allowsInsecureRealtime: false,
                uploads: uploads.map { sessions in { @Sendable (instance: UUID) in sessions.link(for: instance) } })
        }
        let parts = AppParts.make(
            storage: storage, isTestMode: false,
            context: AppPartsContext(
                instances: instances, tokens: tokens, makeEnvironment: environment, defaults: defaults,
                uploads: uploads))
        self.parts = parts
        Self.attachLaunchWork(parts)
        BackgroundRefresh.register(identifier: BackgroundRefresh.identifier) { parts.backgroundRefreshSteps }
        _model = State(
            initialValue: AppModel(
                instances: instances, tokens: tokens,
                makeClient: { url in
                    APIClient(
                        baseURL: url, token: nil, transport: URLSessionTransport(), appVersion: version,
                        systemDescription: system)
                },
                makeEnvironment: environment, observers: parts.observers, push: parts.push, widgets: parts.widgets))
    }

    /// Runs for every launch, with or without a scene, so a background relaunch for an upload session reaches the
    /// sessions.
    private static func attachLaunchWork(_ parts: AppParts) {
        PushAppDelegate.register(launchUploads: parts.uploads)
        parts.uploads?.removeExpiredStaging()
    }

    var body: some Scene {
        WindowGroup {
            #if DEBUG
                if TestMode.value("yakTestScreen") == "live-activity-gallery" {
                    LiveActivityGallery(
                        stateName: ProcessInfo.processInfo.environment["YAK_TEST_GALLERY_STATE"] ?? "working")
                } else if let shareCase = TestMode.value("yakTestShare") {
                    ShareTestHost(caseName: shareCase).preferredColorScheme(TestMode.colorScheme())
                } else if TestMode.value("yakTestScreen") == "widget-gallery" {
                    WidgetGallery(
                        stateName: ProcessInfo.processInfo.environment["YAK_TEST_GALLERY_STATE"] ?? "needs-you")
                } else if UserDefaults.standard.bool(forKey: "yakMarkdownGallery") {
                    MarkdownGallery()
                } else if let page = UserDefaults.standard.string(forKey: "yakChatGallery") {
                    ChatGallery(page: page).preferredColorScheme(TestMode.colorScheme())
                } else {
                    rootView.preferredColorScheme(TestMode.colorScheme())
                        .overlay(alignment: .top) {
                            if let intent = TestMode.intentName { IntentTestResultView(name: intent) }
                        }
                }
            #else
                rootView
            #endif
        }
    }

    private var rootView: some View {
        RootView(model: model)
            .appRoot(parts, model: model)
            .task {
                for await link in QuickActions.links() { await model.handle(url: link) }
            }
            .task {
                delegate.attach(parts.push)
                delegate.attach(uploads: parts.uploads)
                await parts.push.start()
            }
    }
}
