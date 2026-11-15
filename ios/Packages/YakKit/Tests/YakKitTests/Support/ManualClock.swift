import Foundation
import Testing

@testable import YakKit

/// A clock tests step by hand. Sleepers wake in deadline order when `advance(by:)` passes their deadline.
final class ManualClock: YakClock, @unchecked Sendable {
    private struct Sleeper {
        let identifier: Int
        let deadline: Duration
        let continuation: CheckedContinuation<Void, any Error>
    }

    private let lock = NSLock()
    private let start: Date
    private var elapsed: Duration = .zero
    private var sleepers: [Sleeper] = []
    private var nextIdentifier = 0

    init(start: Date = Date(timeIntervalSince1970: 1_800_000_000)) {
        self.start = start
    }

    func now() -> Date { lock.withLock { start.addingTimeInterval(elapsed.seconds) } }

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
                            identifier: identifier, deadline: elapsed + duration,
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
        let target = lock.withLock { elapsed + duration }
        while true {
            let due: Sleeper? = lock.withLock {
                let candidates = sleepers.filter { $0.deadline <= target }
                guard let next = candidates.min(by: { $0.deadline < $1.deadline }) else {
                    elapsed = target
                    return nil
                }
                sleepers.removeAll { $0.identifier == next.identifier }
                elapsed = max(elapsed, next.deadline)
                return next
            }
            guard let due else { return }
            due.continuation.resume()
            // Lets the woken task run before the next sleeper wakes.
            for _ in 0..<50 { await Task.yield() }
        }
    }

    /// Real-time bound for the polling waits, so a missing sleeper fails the test instead of hanging.
    private static let waitLimit: Duration = .seconds(10)

    func waitForSleepers(_ count: Int) async {
        let deadline = ContinuousClock.now + Self.waitLimit
        while lock.withLock({ sleepers.count }) < count {
            if ContinuousClock.now > deadline {
                Issue.record("Timed out waiting for \(count) sleepers")
                return
            }
            await Task.yield()
        }
    }

    /// `offset` is measured from `start`.
    func waitForSleeper(endingAt offset: Duration) async {
        let deadline = ContinuousClock.now + Self.waitLimit
        while !sleeperDeadlines.contains(offset) {
            if ContinuousClock.now > deadline {
                Issue.record("Timed out waiting for a sleeper ending at \(offset)")
                return
            }
            await Task.yield()
        }
    }

    /// Offsets from `start`, sorted.
    var sleeperDeadlines: [Duration] {
        lock.withLock {
            sleepers.map(\.deadline).sorted()
        }
    }
}

extension Duration {
    var seconds: TimeInterval {
        let parts = components
        return TimeInterval(parts.seconds) + TimeInterval(parts.attoseconds) / 1e18
    }
}
