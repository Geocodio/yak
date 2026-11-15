import Foundation

/// Hands every subscriber the current value at once, then every later value. Safe from any actor.
public final class Broadcast<Value: Sendable>: @unchecked Sendable {
    private let lock = NSLock()
    private var current: Value
    private var continuations: [Int: AsyncStream<Value>.Continuation] = [:]
    private var nextIdentifier = 0
    private var isFinished = false

    public init(_ initial: Value) {
        current = initial
    }

    public var value: Value {
        lock.withLock { current }
    }

    public func send(_ value: Value) {
        // Yielding under the lock keeps every subscriber's last value equal to `current`. Yielding to
        // an unbounded stream never blocks or calls `onTermination`, so the lock cannot deadlock.
        lock.withLock {
            guard !isFinished else { return }
            current = value
            for receiver in continuations.values { receiver.yield(value) }
        }
    }

    /// A new stream per call. It starts with the current value and ends when `finish()` is called. After `finish()` it yields the last value and ends.
    public func stream() -> AsyncStream<Value> {
        AsyncStream(bufferingPolicy: .unbounded) { continuation in
            let identifier: Int? = lock.withLock {
                continuation.yield(current)
                guard !isFinished else { return nil }
                nextIdentifier += 1
                continuations[nextIdentifier] = continuation
                return nextIdentifier
            }
            guard let identifier else {
                continuation.finish()
                return
            }
            continuation.onTermination = { [weak self] _ in
                self?.lock.withLock { _ = self?.continuations.removeValue(forKey: identifier) }
            }
        }
    }

    public func finish() {
        let receivers: [AsyncStream<Value>.Continuation] = lock.withLock {
            isFinished = true
            defer { continuations = [:] }
            return Array(continuations.values)
        }
        for receiver in receivers { receiver.finish() }
    }
}
