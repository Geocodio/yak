import SwiftUI
import YakKit

#if canImport(os)
    import os
#endif

@main
struct YakApp: App {
    @State private var model: AppModel
    private let parts: AppParts

    init() {
        let version = DeviceDescription.appVersion()
        let system = DeviceDescription.current().systemDescription
        #if DEBUG
            if let testMode = TestMode.current() {
                let parts = AppParts.make(storage: nil, isTestMode: true)
                self.parts = parts
                _model = State(initialValue: testMode.makeModel(version: version, system: system, parts: parts))
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
        let instances = InstanceStore(defaults: defaults, tokens: tokens, now: { Date() })
        let parts = AppParts.make(storage: storage, isTestMode: false)
        self.parts = parts
        let files: any FileStore = storage?.files ?? SessionEnvironment.releaseFiles()
        _model = State(
            initialValue: AppModel(
                instances: instances, tokens: tokens,
                makeClient: { url in
                    APIClient(
                        baseURL: url, token: nil, transport: URLSessionTransport(), appVersion: version,
                        systemDescription: system)
                },
                makeEnvironment: {
                    .app(version: version, system: system, files: files, allowsInsecureRealtime: false)
                },
                observers: parts.observers))
    }

    var body: some Scene {
        WindowGroup {
            #if DEBUG
                if UserDefaults.standard.bool(forKey: "yakMarkdownGallery") {
                    MarkdownGallery()
                } else {
                    rootView.preferredColorScheme(TestMode.colorScheme())
                }
            #else
                rootView
            #endif
        }
    }

    private var rootView: some View {
        RootView(model: model)
            .appRoot(parts, model: model)
    }
}
