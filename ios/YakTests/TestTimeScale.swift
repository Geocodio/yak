import Foundation
import Testing

/// How much longer a wall-clock limit may run on this machine. A limit's base value is the bar on a quiet Mac; a
/// loaded or hosted machine multiplies it: `YAK_TEST_TIMEOUT_SCALE` when set, otherwise 6 when `CI` is set, else 1.
enum TestTimeScale {
    nonisolated static let factor: Double = factor(in: ProcessInfo.processInfo.environment)

    nonisolated static func factor(in environment: [String: String]) -> Double {
        if let value = environment["YAK_TEST_TIMEOUT_SCALE"].flatMap(Double.init), value > 0 { return value }
        return (environment["CI"] ?? "").isEmpty ? 1 : 6
    }
}

extension TestTimeScale {
    /// How long a test waits for something that must happen: 30 s on a quiet Mac, times the factor on a loaded or hosted one.
    /// A passing wait returns at once; only a failing one spends the whole bound.
    nonisolated static var waitBound: Duration { .seconds(30 * factor) }

    /// Polls `condition` every 5 ms until it holds or `waitBound` passes; returns the last answer.
    static func poll(
        isolation: isolated (any Actor)? = #isolation, _ condition: () async -> Bool
    ) async -> Bool {
        let deadline = ContinuousClock.now.advanced(by: waitBound)
        while ContinuousClock.now < deadline {
            if await condition() { return true }
            try? await Task.sleep(for: .milliseconds(5))
        }
        return await condition()
    }
}

@Test func theTimeScaleReadsTheEnvironment() {
    #expect(TestTimeScale.factor(in: [:]) == 1)
    #expect(TestTimeScale.factor(in: ["CI": "true"]) == 6)
    #expect(TestTimeScale.factor(in: ["CI": "true", "YAK_TEST_TIMEOUT_SCALE": "2.5"]) == 2.5)
    #expect(TestTimeScale.factor(in: ["YAK_TEST_TIMEOUT_SCALE": "zero", "CI": ""]) == 1)
}
