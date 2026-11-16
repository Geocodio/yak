import Foundation

@testable import YakKit

final class InMemoryKeyValueStore: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    private var writes = 0

    /// How many times `set` was called.
    var writeCount: Int { lock.withLock { writes } }

    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) {
        lock.withLock {
            writes += 1
            values[key] = value
        }
    }
}
