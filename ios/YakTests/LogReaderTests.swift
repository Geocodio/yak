import Foundation
import Testing

@testable import Yak

#if canImport(os)
    import os
#endif

@Suite struct LogReaderTests {
    @Test func theProcessLogHoldsAMarkerWithinTheBound() async throws {
        let marker = "log-reader-marker-\(UUID().uuidString.prefix(8))"
        Logger(subsystem: "Yak", category: "Test").notice("\(marker, privacy: .public)")
        let collector = LogCollector()
        let started = Date()
        try await LogReader().read(since: Date().addingTimeInterval(-600), limit: 5_000, into: collector)
        #expect(Date().timeIntervalSince(started) < 5)
        #expect(collector.snapshot().contains { $0.message.contains(marker) })
    }
}
