import SwiftUI
import YakKit

@main
struct YakApp: App {
    @State private var model: AppModel

    init() {
        let tokens = KeychainTokenStore(accessGroup: nil)
        let instances = InstanceStore(defaults: UserDefaults.standard, tokens: tokens, now: { Date() })
        let version = DeviceDescription.appVersion()
        let system = "iOS \(DeviceDescription.current().osVersion); \(DeviceDescription.hardwareModel())"
        _model = State(
            initialValue: AppModel(
                instances: instances, tokens: tokens,
                makeClient: { url in
                    APIClient(
                        baseURL: url, token: nil, transport: URLSessionTransport(), appVersion: version,
                        systemDescription: system)
                }))
    }

    var body: some Scene {
        WindowGroup {
            RootView(model: model)
                .onOpenURL { url in Task { await model.handle(url: url) } }
        }
    }
}
