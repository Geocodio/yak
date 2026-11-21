import Foundation
import Testing

@testable import Yak

#if canImport(os)
    import os
#endif

@Suite struct LogReaderTests {
    @Test func theProcessLogHoldsAMarkerWithinTheBound() async throws {
        let marker = "log-reader-marker-\(UUID().uuidString.prefix(8))"
        // The read starts just before the marker, so the 5,000-entry limit (oldest first) cannot cut it off behind
        // the log lines that other suites write in parallel.
        let since = Date().addingTimeInterval(-1)
        Logger(subsystem: "Yak", category: "Test").notice("\(marker, privacy: .public)")
        var readTime: TimeInterval = .infinity
        var isFound = false
        // The log store takes the entry in a moment after it is written, so a read that misses it is repeated.
        _ = await TestTimeScale.poll {
            let collector = LogCollector()
            let started = Date()
            do { try await LogReader().read(since: since, limit: 5_000, into: collector) } catch { return false }
            readTime = Date().timeIntervalSince(started)
            isFound = collector.snapshot().contains { $0.message.contains(marker) }
            return isFound
        }
        #expect(isFound)
        // The model cuts a read off after five seconds; the reader itself only has to finish. A hosted machine reads
        // the log store in tens of seconds, so the bound is the shared wait bound.
        #expect(readTime < Double(TestTimeScale.waitBound.components.seconds))
    }
}
