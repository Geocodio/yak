import Foundation
import YakKit

#if canImport(os)
    import os
#endif

/// The app group's storage: the instance list's defaults, the container for files and the Keychain tokens. The app
/// and its extensions (widgets, the share sheet) open the same group, so they read the same instances and tokens.
/// It is compiled into every target that needs it.
struct SharedStorage: Sendable {
    let appGroup: String
    let appBundleID: String
    let containerURL: URL
    /// The app group's defaults.
    let defaults: UserDefaults
    /// Tokens in the Keychain. The access group is the first one in the entitlements, which is the app group's.
    let tokens: KeychainTokenStore
    /// Files in the app group's container.
    let files: DiskFileStore

    static let appGroupKey = "YakAppGroup"
    static let appBundleIDKey = "YakAppBundleID"

    /// Reads `YakAppGroup` and `YakAppBundleID`; nil when either is missing or the container cannot be opened.
    static func open(bundle: Bundle = .main) -> SharedStorage? {
        open(infoDictionary: bundle.infoDictionary)
    }

    /// The same, from an Info.plist dictionary, with the container found by `container`. Tests pass their own.
    static func open(
        infoDictionary: [String: Any]?,
        container: (String) -> URL? = { FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: $0) }
    ) -> SharedStorage? {
        guard let appGroup = infoDictionary?[appGroupKey] as? String, !appGroup.isEmpty,
            let appBundleID = infoDictionary?[appBundleIDKey] as? String, !appBundleID.isEmpty,
            let containerURL = container(appGroup), let defaults = UserDefaults(suiteName: appGroup)
        else { return nil }
        return SharedStorage(
            appGroup: appGroup, appBundleID: appBundleID, containerURL: containerURL, defaults: defaults,
            tokens: KeychainTokenStore(accessGroup: nil), files: DiskFileStore(root: containerURL))
    }

    /// The app only: moves the instance list from the standard defaults once. Extensions never call it. True when a
    /// list moved. A list already in the group wins, and one that does not decode stays where it is.
    func migrateFromStandardStores(from standard: any KeyValueStore = UserDefaults.standard) -> Bool {
        InstanceStore.moveStoredList(from: standard, to: defaults)
    }
}
