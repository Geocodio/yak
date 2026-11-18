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
                makeEnvironment: environment, observers: parts.observers, push: parts.push, widgets: parts.widgets))
    }

    var body: some Scene {
        WindowGroup(for: WindowRequest.self) { $request in
            #if DEBUG
                if TestMode.value("yakTestScreen") == "live-activity-gallery" {
                    LiveActivityGallery(
                        stateName: ProcessInfo.processInfo.environment["YAK_TEST_GALLERY_STATE"] ?? "working")
                } else if TestMode.value("yakTestScreen") == "widget-gallery" {
                    WidgetGallery(
                        stateName: ProcessInfo.processInfo.environment["YAK_TEST_GALLERY_STATE"] ?? "needs-you")
                } else if UserDefaults.standard.bool(forKey: "yakMarkdownGallery") {
                    MarkdownGallery()
                } else if let page = UserDefaults.standard.string(forKey: "yakChatGallery") {
                    ChatGallery(page: page).preferredColorScheme(TestMode.colorScheme())
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
        RootView(model: model, request: request)
            // An open window takes a link, a widget tap or an activity; none of them opens another window.
            .handlesExternalEvents(preferring: ["*"], allowing: ["*"])
            .appRoot(parts, model: model)
            .task { model.followQuickActions() }
            .task {
                delegate.attach(parts.push)
                await parts.push.start()
            }
    }
}
