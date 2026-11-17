import Foundation

/// How long a test waits for something that must happen. The base is ten seconds; CI counts as a factor of 6 and
/// `YAK_TEST_TIMEOUT_SCALE` (a Double, default 1) sets the factor anywhere else. A slow machine runs the same tests with more room.
enum TestTiming {
    static let scale: Double = {
        let environment = ProcessInfo.processInfo.environment
        if let text = environment["YAK_TEST_TIMEOUT_SCALE"], let factor = Double(text), factor > 0 { return factor }
        return (environment["CI"] ?? "").isEmpty ? 1 : 6
    }()

    static let defaultTimeout: Duration = .seconds(10 * scale)
}
