import Foundation
import Observation
import UIKit
import YakKit

/// Whether the system has the app's protected files open, which is false whenever the device is locked. App group
/// defaults then answer empty, and keep unwritten values in memory only, until the device is unlocked.
protocol ProtectedDataAvailability: Sendable {
    @MainActor var isAvailable: Bool { get }
}

struct SystemProtectedData: ProtectedDataAvailability {
    @MainActor var isAvailable: Bool { UIApplication.shared.isProtectedDataAvailable }
}

/// The two privacy choices, kept in the key-value store. Both read as off when never set. While protected data is
/// unavailable the store is unreadable: the values are only defaults, nothing is written, and the app covers its
/// content until `reload()` finds the data available.
@MainActor @Observable
final class PrivacySettings {
    static let appLockKey = "privacy.appLockOn"
    static let spotlightPrivateKey = "privacy.spotlightIncludesPrivate"

    @ObservationIgnored private let store: any KeyValueStore
    @ObservationIgnored private let protectedData: any ProtectedDataAvailability

    var appLockOn: Bool { didSet { save(appLockOn, forKey: Self.appLockKey) } }
    var spotlightIncludesPrivate: Bool { didSet { save(spotlightIncludesPrivate, forKey: Self.spotlightPrivateKey) } }

    /// False while the store cannot be read; the values above are then only defaults.
    @ObservationIgnored private(set) var isReadable: Bool

    init(store: any KeyValueStore, protectedData: any ProtectedDataAvailability = SystemProtectedData()) {
        self.store = store
        self.protectedData = protectedData
        isReadable = protectedData.isAvailable
        appLockOn = Self.read(store, Self.appLockKey)
        spotlightIncludesPrivate = Self.read(store, Self.spotlightPrivateKey)
    }

    /// Reads both choices again. Returns whether the store could be read; when it could not, nothing changes.
    @discardableResult
    func reload() -> Bool {
        isReadable = protectedData.isAvailable
        guard isReadable else { return false }
        let lock = Self.read(store, Self.appLockKey)
        if lock != appLockOn { appLockOn = lock }
        let spotlight = Self.read(store, Self.spotlightPrivateKey)
        if spotlight != spotlightIncludesPrivate { spotlightIncludesPrivate = spotlight }
        return true
    }

    private static func read(_ store: any KeyValueStore, _ key: String) -> Bool {
        guard let data = store.data(forKey: key), let value = try? JSONDecoder().decode(Bool.self, from: data) else {
            return false
        }
        return value
    }

    private func save(_ value: Bool, forKey key: String) {
        guard isReadable else { return }
        store.set(Data((value ? "true" : "false").utf8), forKey: key)
    }
}

/// Filled in right after `AppModel` exists, so parts built before it can reach it later.
@MainActor final class ModelBox {
    weak var model: AppModel?
}
