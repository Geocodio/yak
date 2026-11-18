import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor @Suite struct PrivacySettingsTests {
    @Test func bothAreOffWhenNeverSet() {
        let settings = PrivacySettings(store: MemoryStore())
        #expect(!settings.appLockOn)
        #expect(!settings.spotlightIncludesPrivate)
    }

    @Test func valuesPersistInTheStore() {
        let store = MemoryStore()
        let first = PrivacySettings(store: store)
        first.appLockOn = true
        first.spotlightIncludesPrivate = true
        let second = PrivacySettings(store: store)
        #expect(second.appLockOn)
        #expect(second.spotlightIncludesPrivate)
        second.appLockOn = false
        #expect(!PrivacySettings(store: store).appLockOn)
    }

    @Test func garbageInTheStoreReadsAsOff() {
        let store = MemoryStore()
        store.set(Data("maybe".utf8), forKey: PrivacySettings.appLockKey)
        store.set(Data([0xFF, 0x00]), forKey: PrivacySettings.spotlightPrivateKey)
        let settings = PrivacySettings(store: store)
        #expect(!settings.appLockOn)
        #expect(!settings.spotlightIncludesPrivate)
    }
}

#if DEBUG
    @Suite struct TestSystemOptionsTests {
        @Test func theAuthenticationListIsReadInOrder() {
            #expect(TestSystemOptions.parseAuthentication("cancel,succeed") == [.cancel, .succeed])
            #expect(TestSystemOptions.parseAuthentication("") == [.succeed])
            #expect(TestSystemOptions.parseAuthentication(nil) == [.succeed])
            #expect(TestSystemOptions.parseAuthentication("sing") == [.succeed])
            let long = Array(repeating: "fail", count: 1000).joined(separator: ",")
            #expect(TestSystemOptions.parseAuthentication(long).count == 8)
        }
    }
#endif

private final class MemoryStore: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}
