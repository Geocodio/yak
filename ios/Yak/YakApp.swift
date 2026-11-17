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
        let environment: @Sendable () -> SessionEnvironment = {
            .app(version: version, system: system, files: files, allowsInsecureRealtime: false)
        }
        let parts = AppParts.make(
            storage: storage, isTestMode: false,
            context: AppPartsContext(
                instances: instances, tokens: tokens, makeEnvironment: environment, defaults: defaults))
        self.parts = parts
        BackgroundRefresh.register(identifier: BackgroundRefresh.identifier) { parts.backgroundRefreshSteps }
        _model = State(
            initialValue: AppModel(
                instances: instances, tokens: tokens,
                makeClient: { url in
                    APIClient(
                        baseURL: url, token: nil, transport: URLSessionTransport(), appVersion: version,
                        systemDescription: system)
                },
                makeEnvironment: environment, observers: parts.observers, push: parts.push))
    }

    var body: some Scene {
        WindowGroup {
            #if DEBUG
                if TestMode.value("yakTestScreen") == "live-activity-gallery" {
                    LiveActivityGallery(
                        stateName: ProcessInfo.processInfo.environment["YAK_TEST_GALLERY_STATE"] ?? "working")
                } else {
                    root
                }
            #else
                root
            #endif
        }
    }

    private var root: some View {
        RootView(model: model)
            .appRoot(parts, model: model)
            .task {
                delegate.attach(parts.push)
                await parts.push.start()
            }
            #if DEBUG
                .preferredColorScheme(TestMode.colorScheme())
            #endif
    }
}
