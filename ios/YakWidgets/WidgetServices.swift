import Foundation
import YakKit

/// What the widget extension reads and registers with. It opens the shared storage; the extension never writes
/// the instance list or a token.
struct WidgetServices: Sendable {
    let refresher: WidgetRefresher
    let registration: WidgetPushRegistration

    static func open(bundle: Bundle = .main) -> WidgetServices? {
        guard let storage = SharedStorage.open(bundle: bundle) else { return nil }
        let version = bundle.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "0"
        let environment = storage.environment(appVersion: version, system: "iOS widget")
        let instances = storage.instanceStore(bundle: bundle)
        return WidgetServices(
            refresher: WidgetRefresher(instances: instances, tokens: storage.tokens, environment: environment),
            registration: WidgetPushRegistration(
                instances: instances, tokens: storage.tokens, environment: environment,
                build: storage.pushBuild(bundle: bundle)))
    }
}
