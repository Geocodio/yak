import Foundation
import Observation
import YakKit

/// The two privacy choices, kept in the key-value store. Both read as off when never set or unreadable.
@MainActor @Observable
final class PrivacySettings {
    static let appLockKey = "privacy.appLockOn"
    static let spotlightPrivateKey = "privacy.spotlightIncludesPrivate"

    @ObservationIgnored private let store: any KeyValueStore

    var appLockOn: Bool { didSet { save(appLockOn, forKey: Self.appLockKey) } }
    var spotlightIncludesPrivate: Bool { didSet { save(spotlightIncludesPrivate, forKey: Self.spotlightPrivateKey) } }

    init(store: any KeyValueStore) {
        self.store = store
        appLockOn = Self.read(store, Self.appLockKey)
        spotlightIncludesPrivate = Self.read(store, Self.spotlightPrivateKey)
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
