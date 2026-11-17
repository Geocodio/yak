import Foundation
import Testing

/// How much longer a wall-clock limit may run on this machine. A limit's base value is the bar on a quiet Mac; a
/// loaded or hosted machine multiplies it: `YAK_TEST_TIMEOUT_SCALE` when set, otherwise 6 when `CI` is set, else 1.
enum TestTimeScale {
    nonisolated static let factor: Double = factor(in: ProcessInfo.processInfo.environment)

    nonisolated static func factor(in environment: [String: String]) -> Double {
        if let value = environment["YAK_TEST_TIMEOUT_SCALE"].flatMap(Double.init), value > 0 { return value }
        return environment["CI"] == nil ? 1 : 6
    }
}

@Test func theTimeScaleReadsTheEnvironment() {
    #expect(TestTimeScale.factor(in: [:]) == 1)
    #expect(TestTimeScale.factor(in: ["CI": "true"]) == 6)
    #expect(TestTimeScale.factor(in: ["CI": "true", "YAK_TEST_TIMEOUT_SCALE": "2.5"]) == 2.5)
    #expect(TestTimeScale.factor(in: ["YAK_TEST_TIMEOUT_SCALE": "zero"]) == 1)
}
