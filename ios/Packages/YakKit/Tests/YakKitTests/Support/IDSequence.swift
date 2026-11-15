import Foundation

/// Hands out 00000000-0000-4000-8000-000000000001, then ...0002, and so on.
struct IDSequence: Sendable {
    private final class Counter: @unchecked Sendable {
        let lock = NSLock()
        var value = 0
    }

    private let counter = Counter()

    func next() -> UUID {
        let value = counter.lock.withLock { () -> Int in
            counter.value += 1
            return counter.value
        }
        return UUID(uuidString: "00000000-0000-4000-8000-" + String(format: "%012d", value))!
    }
}
