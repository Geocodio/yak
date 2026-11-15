import SwiftUI
import YakKit

@main
struct YakApp: App {
    @State private var model: AppModel

    init() {
        let version = DeviceDescription.appVersion()
        let system = DeviceDescription.current().systemDescription
        #if DEBUG
            if let testMode = TestMode.current() {
                _model = State(initialValue: testMode.makeModel(version: version, system: system))
                return
            }
        #endif
        let tokens = KeychainTokenStore(accessGroup: nil)
        let instances = InstanceStore(defaults: UserDefaults.standard, tokens: tokens, now: { Date() })
        _model = State(
            initialValue: AppModel(
                instances: instances, tokens: tokens,
                makeClient: { url in
                    APIClient(
                        baseURL: url, token: nil, transport: URLSessionTransport(), appVersion: version,
                        systemDescription: system)
                },
                makeEnvironment: {
                    .app(
                        version: version, system: system, files: SessionEnvironment.releaseFiles(),
                        allowsInsecureRealtime: false)
                }))
    }

    var body: some Scene {
        WindowGroup {
            RootView(model: model)
                .onOpenURL { url in Task { await model.handle(url: url) } }
        }
    }
}
