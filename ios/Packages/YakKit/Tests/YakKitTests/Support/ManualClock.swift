import Foundation

@testable import YakKit

/// A clock tests step by hand. Sleepers wake in deadline order when `advance(by:)` passes their deadline.
final class ManualClock: YakClock, @unchecked Sendable {
    private struct Sleeper {
        let identifier: Int
        let deadline: Date
        let continuation: CheckedContinuation<Void, any Error>
    }

    private let lock = NSLock()
    private let start: Date
    private var current: Date
    private var sleepers: [Sleeper] = []
    private var nextIdentifier = 0

    init(start: Date = Date(timeIntervalSince1970: 1_800_000_000)) {
        self.start = start
        current = start
    }

    func now() -> Date { lock.withLock { current } }

    func sleep(for duration: Duration) async throws {
        let identifier = lock.withLock { () -> Int in
            nextIdentifier += 1
            return nextIdentifier
        }
        try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, any Error>) in
                let isCancelled: Bool = lock.withLock {
                    if Task.isCancelled { return true }
                    sleepers.append(
                        Sleeper(
                            identifier: identifier, deadline: current.addingTimeInterval(duration.seconds),
                            continuation: continuation))
                    return false
                }
                if isCancelled { continuation.resume(throwing: CancellationError()) }
            }
        } onCancel: {
            let removed: Sleeper? = lock.withLock {
                guard let index = sleepers.firstIndex(where: { $0.identifier == identifier }) else { return nil }
                return sleepers.remove(at: index)
            }
            removed?.continuation.resume(throwing: CancellationError())
        }
    }

    /// Moves time forward and wakes, one at a time and in deadline order, every sleeper now due. A
    /// sleeper registered after this returns waits for the next call.
    func advance(by duration: Duration) async {
        let target = lock.withLock { current.addingTimeInterval(duration.seconds) }
        while true {
            let due: Sleeper? = lock.withLock {
                let candidates = sleepers.filter { $0.deadline <= target }
                guard let next = candidates.min(by: { $0.deadline < $1.deadline }) else {
                    current = target
                    return nil
                }
                sleepers.removeAll { $0.identifier == next.identifier }
                current = max(current, next.deadline)
                return next
            }
            guard let due else { return }
            due.continuation.resume()
            // Lets the woken task run before the next sleeper wakes.
            for _ in 0..<50 { await Task.yield() }
        }
    }

    func waitForSleepers(_ count: Int) async {
        while lock.withLock({ sleepers.count }) < count { await Task.yield() }
    }

    /// `offset` is measured from `start`.
    func waitForSleeper(endingAt offset: Duration) async {
        while !sleeperDeadlines.contains(offset) { await Task.yield() }
    }

    /// Offsets from `start`, sorted.
    var sleeperDeadlines: [Duration] {
        lock.withLock {
            sleepers.map { Duration.seconds($0.deadline.timeIntervalSince(start)) }.sorted()
        }
    }
}

extension Duration {
    var seconds: TimeInterval {
        let parts = components
        return TimeInterval(parts.seconds) + TimeInterval(parts.attoseconds) / 1e18
    }
}
