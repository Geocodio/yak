import Foundation
import Testing
import YakKit

@testable import Yak

private final class Store: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct FixedKind: BuildKindSource {
    let value: DebugBuildKind
    func kind() async -> DebugBuildKind { value }
}

private struct FakeLog: LogSource {
    var entries: [RawLogEntry] = []
    var holdsAfter = false
    var fails = false
    var clock: (any YakClock)?
    struct Unreadable: Error {}

    func read(since: Date, limit: Int, into collector: LogCollector) async throws {
        if fails { throw Unreadable() }
        for entry in entries.prefix(limit) { collector.append(entry) }
        if holdsAfter, let clock { try await clock.sleep(for: .seconds(3600)) }
    }
}

private func entry(_ message: String, _ index: Int = 0) -> RawLogEntry {
    RawLogEntry(
        date: Date(timeIntervalSince1970: 1_800_000_000 + Double(index)), level: .notice, subsystem: "Yak",
        category: "Test", message: message)
}

@MainActor @Suite struct DebugMenuModelTests {
    private func make(
        _ log: FakeLog = FakeLog(), kind: DebugBuildKind = .debug, faults: DebugFaultSwitch = DebugFaultSwitch(),
        clock: ManualClock = ManualClock(), store: Store = Store()
    ) -> DebugMenuModel {
        DebugMenuModel(faults: faults, logs: log, buildKind: FixedKind(value: kind), store: store, clock: clock)
    }

    /// The model's timer and the fake log's hold both sleep on the clock. The read adds its entries before it holds,
    /// so once both sleep, the entries are collected and advancing the clock can only cut off the hold.
    private func waitForTheReadToHold(_ clock: ManualClock) async {
        let isHolding = await TestTimeScale.poll {
            clock.sleeperCount(waking: .seconds(5)) > 0 && clock.sleeperCount(waking: .seconds(3600)) > 0
        }
        #expect(isHolding)
    }

    @Test func faultsAreOffAtLaunch() {
        let earlier = DebugFaultSwitch()
        make(faults: earlier).setFault(.offline)
        #expect(make().fault == .off)
        #expect(DebugFaultSwitch().fault == .off)
    }

    @Test func settingAFaultReachesTheSwitch() {
        let faults = DebugFaultSwitch()
        let model = make(faults: faults)
        model.setFault(.serverError)
        #expect(faults.fault == .serverError && model.fault == .serverError)
    }

    @Test func theLogIsRedactedAndBounded() async {
        let entries =
            (0..<6_000).map { entry("line \($0) Bearer test-token-0123456789abcdefghij", $0) }
            + [entry("body {\"title\": \"Private notes on billing\"}", 6_001)]
        let model = make(FakeLog(entries: entries))
        await model.loadLog()
        guard case .loaded(let lines, let partial) = model.log else {
            Issue.record("expected lines, got \(model.log)")
            return
        }
        #expect(lines.count == 200 && !partial)
        #expect(!lines.contains { $0.text.contains("test-token") })
        let copy = model.copyLog()
        #expect(!copy.contains("test-token") && !copy.contains("Private notes"))
    }

    @Test func aSlowLogShowsWhatWasRead() async {
        let clock = ManualClock()
        let log = FakeLog(entries: (0..<50).map { entry("line \($0)", $0) }, holdsAfter: true, clock: clock)
        let model = make(log, clock: clock)
        let load = Task { await model.loadLog() }
        await waitForTheReadToHold(clock)
        clock.advance(.seconds(5))
        await load.value
        guard case .loaded(let lines, let partial) = model.log else {
            Issue.record("expected partial lines, got \(model.log)")
            return
        }
        #expect(partial && lines.count == 50)
    }

    @Test func nothingReadInTimeIsFailed() async {
        let clock = ManualClock()
        let model = make(FakeLog(holdsAfter: true, clock: clock), clock: clock)
        let load = Task { await model.loadLog() }
        await waitForTheReadToHold(clock)
        clock.advance(.seconds(5))
        await load.value
        #expect(model.log == .failed("Reading the log took too long."))
    }

    @Test func anEmptyLog() async {
        let model = make()
        await model.loadLog()
        #expect(model.log == .empty)
    }

    @Test func aFailedLog() async {
        let model = make(FakeLog(fails: true))
        await model.loadLog()
        #expect(model.log == .failed("The log could not be read."))
    }

    @Test func appStoreBuildsHaveNoMenu() async {
        let appStore = make(kind: .appStore)
        #expect(!appStore.isAvailable)
        await appStore.start()
        #expect(!appStore.isAvailable)
        let flight = make(kind: .testFlight)
        #expect(!flight.isAvailable)
        await flight.start()
        #expect(flight.isAvailable)
    }

    @Test func showTipsAgainStoresTheFlag() {
        let store = Store()
        let model = make(store: store)
        model.showTipsAgain()
        #expect(store.data(forKey: DebugMenuModel.showTipsKey) != nil)
        #expect(model.tipsWillShowAgain)
    }
}
