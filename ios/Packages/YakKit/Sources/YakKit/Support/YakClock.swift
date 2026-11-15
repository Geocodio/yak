import Foundation

/// The only source of time for the engine: tests step it by hand, the app uses `SystemClock`.
public protocol YakClock: Sendable {
    func now() -> Date
    /// Throws `CancellationError` when the calling task is cancelled.
    func sleep(for duration: Duration) async throws
}

public struct SystemClock: YakClock {
    public init() {}

    public func now() -> Date { Date() }

    public func sleep(for duration: Duration) async throws {
        try await Task.sleep(for: duration)
    }
}
