import Foundation
import YakKit

/// A clock that only moves when the test advances it; sleepers resume once their time has come, or throw when
/// their task is cancelled, as the real clock does.
final class ManualClock: YakClock, @unchecked Sendable {
    private let lock = NSLock()
    private var current = Date(timeIntervalSince1970: 1_000)
    private var nextID = 0
    private var sleepers: [(id: Int, due: Date, continuation: CheckedContinuation<Void, Error>)] = []

    func now() -> Date { lock.withLock { current } }

    func sleep(for duration: Duration) async throws {
        let id = lock.withLock { () -> Int in
            nextID += 1
            return nextID
        }
        try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { continuation in
                let isCancelled = lock.withLock { () -> Bool in
                    if Task.isCancelled { return true }
                    let seconds = Double(duration.components.seconds) + Double(duration.components.attoseconds) / 1e18
                    sleepers.append((id, current.addingTimeInterval(seconds), continuation))
                    return false
                }
                if isCancelled { continuation.resume(throwing: CancellationError()) }
            }
        } onCancel: {
            let cancelled = lock.withLock { () -> CheckedContinuation<Void, Error>? in
                guard let index = sleepers.firstIndex(where: { $0.id == id }) else { return nil }
                return sleepers.remove(at: index).continuation
            }
            cancelled?.resume(throwing: CancellationError())
        }
    }

    var sleeperCount: Int { lock.withLock { sleepers.count } }

    /// How many sleepers wake after exactly `duration` from now.
    func sleeperCount(waking duration: Duration) -> Int {
        let seconds = Double(duration.components.seconds) + Double(duration.components.attoseconds) / 1e18
        return lock.withLock { sleepers.filter { abs($0.due.timeIntervalSince(current) - seconds) < 0.0005 }.count }
    }

    func advance(_ duration: Duration) {
        let seconds = Double(duration.components.seconds) + Double(duration.components.attoseconds) / 1e18
        let ready: [CheckedContinuation<Void, Error>] = lock.withLock {
            current = current.addingTimeInterval(seconds)
            // Floating point sums of milliseconds can land a hair short of a due time.
            let limit = current.addingTimeInterval(0.0005)
            let due = sleepers.filter { $0.due <= limit }
            sleepers.removeAll { $0.due <= limit }
            return due.map(\.continuation)
        }
        for continuation in ready { continuation.resume() }
    }
}
