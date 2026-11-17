import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor
struct UploadThreadModelTests {
    private let item = UUID()

    private func summary(_ progress: Double? = nil, failed: Bool = false) -> UploadSummary {
        UploadSummary(progress: progress.map { [item: $0] } ?? [:], failed: failed ? [item] : [])
    }

    @Test func uploadLines() {
        func line(_ summary: UploadSummary, stalled: Set<UUID> = [], offline: Bool = false) -> UploadLineState? {
            UploadLineState.make(item: item, summary: summary, stalled: stalled, isOffline: offline)
        }
        #expect(line(summary(0.427)) == .uploading(percent: 42))
        #expect(line(summary(0.427), offline: true) == .waitingForNetwork)
        #expect(line(summary(0.5, failed: true)) == .failedInBackground)
        #expect(line(UploadSummary()) == nil)
        #expect(line(UploadSummary(progress: [UUID(): 0.3])) == nil)
        #expect(line(summary(0.42), stalled: [item]) == .stalled(percent: 42))
    }

    @Test func aTextOnlyItemBehindAFailedUploadKeepsTheOutboxLine() {
        let other = UUID()
        let behind = UploadSummary(progress: [:], failed: [item])
        #expect(UploadLineState.make(item: other, summary: behind, stalled: [], isOffline: false) == nil)
    }

    @Test func aProgressValueThatStandsStillBecomesSlow() async throws {
        let clock = ManualClock()
        let watch = UploadWatch()
        let (stream, continuation) = AsyncStream.makeStream(of: UploadSummary.self)
        watch.start(summaries: stream, clock: clock)
        continuation.yield(summary(0.42))
        try await waitUntil { clock.sleeperCount == 1 }
        clock.advance(.milliseconds(7900))
        #expect(watch.stalled.isEmpty)
        clock.advance(.milliseconds(100))
        try await waitUntil { watch.stalled.contains(item) }
        continuation.yield(summary(0.5))
        try await waitUntil { !watch.stalled.contains(item) && watch.summary.progress[item] == 0.5 }
        watch.stop()
    }

    private func waitUntil(_ condition: @MainActor () -> Bool) async throws {
        for _ in 0..<400 where !condition() { try await Task.sleep(for: .milliseconds(10)) }
        #expect(condition())
    }
}

@MainActor
struct AttachmentRefreshRuleTests {
    @Test func expiredLinksRefreshAtMostOncePerMinute() {
        let loading = AttachmentLoading()
        let start = Date(timeIntervalSince1970: 1_000)
        #expect(loading.claimRefresh(at: start))
        #expect(!loading.claimRefresh(at: start.addingTimeInterval(30)))
        #expect(!loading.claimRefresh(at: start.addingTimeInterval(59)))
        #expect(loading.claimRefresh(at: start.addingTimeInterval(61)))
    }
}
