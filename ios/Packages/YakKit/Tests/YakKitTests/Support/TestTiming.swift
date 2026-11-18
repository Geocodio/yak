import Foundation
import Testing

/// How long a test waits for something that must happen. The base is thirty seconds (a passing wait returns at once); CI counts as a factor of 6 and
/// `YAK_TEST_TIMEOUT_SCALE` (a Double, default 1) sets the factor anywhere else. A slow machine runs the same tests with more room.
enum TestTiming {
    static let scale: Double = {
        let environment = ProcessInfo.processInfo.environment
        if let text = environment["YAK_TEST_TIMEOUT_SCALE"], let factor = Double(text), factor > 0 { return factor }
        return (environment["CI"] ?? "").isEmpty ? 1 : 6
    }()

    static let defaultTimeout: Duration = .seconds(30 * scale)
}

/// Polls `condition` every 5 ms, sleeping between looks so a loaded machine is not spun on, until it holds or
/// `TestTiming.defaultTimeout` passes; a condition that never holds records an issue.
func waitUntil(
    sourceLocation: SourceLocation = #_sourceLocation, _ condition: @Sendable () async -> Bool
) async {
    let deadline = ContinuousClock.now + TestTiming.defaultTimeout
    while ContinuousClock.now < deadline {
        if await condition() { return }
        try? await Task.sleep(for: .milliseconds(5))
    }
    Issue.record("waitUntil gave up: the condition was not met", sourceLocation: sourceLocation)
}
