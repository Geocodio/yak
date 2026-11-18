import Foundation
import YakKit

#if canImport(os)
    import os
#endif

/// What the intents use. They run in the app's process, so the value is built from the shared storage the first time an
/// intent asks, whether or not `YakApp` has started; test mode replaces it with the stub's environment.
struct IntentServicesValue: Sendable {
    let instances: InstanceStore
    let environment: SessionEnvironment
    let tokens: any TokenStore
    /// The repository the new thread screen used last on an instance.
    let lastRepository: @Sendable (UUID) -> String?

    var refresher: WidgetRefresher {
        WidgetRefresher(instances: instances, tokens: tokens, environment: environment)
    }
    var submission: StartThreadSubmission {
        StartThreadSubmission(instances: instances, tokens: tokens, environment: environment)
    }
}

enum IntentServices {
    private static let replacement = OSAllocatedUnfairLock<IntentServicesValue?>(initialState: nil)

    /// Test mode and unit tests hand their own value in; nil restores the shared storage.
    static func use(_ value: IntentServicesValue?) { replacement.withLock { $0 = value } }

    /// The services, or nil when the shared storage cannot be opened.
    static func resolve() -> IntentServicesValue? {
        if let value = replacement.withLock({ $0 }) { return value }
        guard let storage = SharedStorage.open() else { return nil }
        let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "0"
        return IntentServicesValue(
            instances: storage.instanceStore(),
            environment: storage.environment(appVersion: version, system: "iOS intent"),
            tokens: storage.tokens,
            lastRepository: { UserDefaults.standard.string(forKey: NewThreadModel.lastRepositoryKey($0)) })
    }
}

/// An intent's failure that Siri and Shortcuts speak as it is.
struct IntentMessage: Error, CustomLocalizedStringResourceConvertible {
    let message: String
    var localizedStringResource: LocalizedStringResource { LocalizedStringResource(stringLiteral: message) }
}
