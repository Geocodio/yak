import Foundation
import Observation
import YakKit

/// The two privacy choices, kept in the key-value store. Both read as off when never set. A store that cannot be read
/// (the app group's defaults before the first unlock after a reboot) is told apart from a missing value by a probe
/// key written the first time the store works, so the app can cover its content until the store can be read.
@MainActor @Observable
final class PrivacySettings {
    static let appLockKey = "privacy.appLockOn"
    static let spotlightPrivateKey = "privacy.spotlightIncludesPrivate"
    static let probeKey = "privacy.storeProbe"

    @ObservationIgnored private let store: any KeyValueStore

    var appLockOn: Bool { didSet { save(appLockOn, forKey: Self.appLockKey) } }
    var spotlightIncludesPrivate: Bool { didSet { save(spotlightIncludesPrivate, forKey: Self.spotlightPrivateKey) } }

    /// False while the store cannot be read; the values above are then only defaults.
    @ObservationIgnored private(set) var isReadable: Bool

    init(store: any KeyValueStore) {
        self.store = store
        isReadable = Self.probe(store)
        appLockOn = Self.read(store, Self.appLockKey)
        spotlightIncludesPrivate = Self.read(store, Self.spotlightPrivateKey)
    }

    /// Reads both choices again. Returns whether the store could be read; when it could not, nothing changes.
    @discardableResult
    func reload() -> Bool {
        isReadable = Self.probe(store)
        guard isReadable else { return false }
        let lock = Self.read(store, Self.appLockKey)
        if lock != appLockOn { appLockOn = lock }
        let spotlight = Self.read(store, Self.spotlightPrivateKey)
        if spotlight != spotlightIncludesPrivate { spotlightIncludesPrivate = spotlight }
        return true
    }

    private static func probe(_ store: any KeyValueStore) -> Bool {
        if store.data(forKey: probeKey) != nil { return true }
        store.set(Data("1".utf8), forKey: probeKey)
        return store.data(forKey: probeKey) != nil
    }

    private static func read(_ store: any KeyValueStore, _ key: String) -> Bool {
        guard let data = store.data(forKey: key), let value = try? JSONDecoder().decode(Bool.self, from: data) else {
            return false
        }
        return value
    }

    private func save(_ value: Bool, forKey key: String) {
        store.set(Data((value ? "true" : "false").utf8), forKey: key)
    }
}

/// Filled in right after `AppModel` exists, so parts built before it can reach it later.
@MainActor final class ModelBox {
    weak var model: AppModel?
}
