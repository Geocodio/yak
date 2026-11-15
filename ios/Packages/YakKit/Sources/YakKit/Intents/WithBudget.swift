import Foundation

/// The work's result, or nil when the budget ends first; the work is cancelled then.
func withBudget<Result: Sendable>(
    _ budget: Duration, clock: any YakClock, _ work: @escaping @Sendable () async -> Result
) async -> Result? {
    await withTaskGroup(of: Result?.self) { group in
        group.addTask { await work() }
        group.addTask {
            do { try await clock.sleep(for: budget) } catch { return nil }
            return nil
        }
        let first = await group.next() ?? nil
        group.cancelAll()
        return first
    }
}
