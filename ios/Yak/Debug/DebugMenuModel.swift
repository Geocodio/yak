import Foundation
import Observation
import YakKit

/// What the Debug menu shows and does: the request faults, the redacted log and the build kind that allows it.
@MainActor @Observable
final class DebugMenuModel {
    enum LogState: Equatable {
        case idle, loading
        case loaded(lines: [LogLine], partial: Bool)
        case empty
        case failed(String)
    }

    nonisolated static let showTipsKey = "debug.showTipsAgain"
    static let logBound: Duration = .seconds(5)
    static let logWindow: TimeInterval = 600
    static let logEntryLimit = 5_000

    @ObservationIgnored private let faults: DebugFaultSwitch
    @ObservationIgnored private let logs: any LogSource
    @ObservationIgnored private let buildKind: any BuildKindSource
    @ObservationIgnored private let store: any KeyValueStore
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private var ring = LogRing()

    /// Nil until the build kind is known (at most 5 seconds).
    private(set) var kind: DebugBuildKind?
    private(set) var fault: DebugFault
    private(set) var log: LogState = .idle
    private(set) var tipsWillShowAgain = false

    var isAvailable: Bool { kind.map(DebugMenuAvailability.isAvailable) ?? false }

    init(
        faults: DebugFaultSwitch, logs: any LogSource, buildKind: any BuildKindSource, store: any KeyValueStore,
        clock: any YakClock
    ) {
        self.faults = faults
        self.logs = logs
        self.buildKind = buildKind
        self.store = store
        self.clock = clock
        fault = faults.fault
    }

    func start() async {
        guard kind == nil else { return }
        kind = await buildKind.kind()
    }

    func setFault(_ next: DebugFault) {
        faults.set(next)
        fault = next
    }

    /// The text for the clipboard: only redacted lines ever reach it.
    func copyLog() -> String { ring.text }

    func showTipsAgain() {
        store.set(Data("true".utf8), forKey: Self.showTipsKey)
        tipsWillShowAgain = true
    }

    /// The last 10 minutes, at most 5,000 entries, read for at most 5 seconds. What was read by then shows as partial.
    func loadLog() async {
        guard log != .loading else { return }
        log = .loading
        let collector = LogCollector()
        let since = clock.now().addingTimeInterval(-Self.logWindow)
        let outcome = await read(since: since, into: collector)
        var next = LogRing()
        for entry in collector.snapshot() {
            next.append(date: entry.date, level: entry.level, category: entry.category, message: entry.message)
        }
        ring = next
        let lines = next.lines
        switch outcome {
        case .failed: log = .failed(String(localized: "debug.log.failed"))
        case .timedOut:
            log =
                lines.isEmpty
                ? .failed(String(localized: "debug.log.tooLong")) : .loaded(lines: lines, partial: true)
        case .finished: log = lines.isEmpty ? .empty : .loaded(lines: lines, partial: false)
        }
    }

    private func read(since: Date, into collector: LogCollector) async -> LogReadOutcome {
        let logs = self.logs
        let clock = self.clock
        return await withCheckedContinuation { (continuation: CheckedContinuation<LogReadOutcome, Never>) in
            let gate = ReadGate(continuation)
            let work = Task {
                do {
                    try await logs.read(since: since, limit: Self.logEntryLimit, into: collector)
                    gate.finish(.finished)
                } catch is CancellationError {
                    gate.finish(.timedOut)
                } catch {
                    gate.finish(.failed)
                }
            }
            let timer = Task {
                do { try await clock.sleep(for: Self.logBound) } catch { return }
                if gate.finish(.timedOut) { work.cancel() }
            }
            gate.onFinish = { timer.cancel() }
        }
    }
}

/// Lets the first of the read and the timer resume the waiting caller.
private final class ReadGate: @unchecked Sendable {
    private let lock = NSLock()
    private var continuation: CheckedContinuation<LogReadOutcome, Never>?
    var onFinish: (@Sendable () -> Void)?

    init(_ continuation: CheckedContinuation<LogReadOutcome, Never>) { self.continuation = continuation }

    /// True when this call was the one that resumed the caller.
    @discardableResult func finish(_ outcome: LogReadOutcome) -> Bool {
        let taken = lock.withLock { () -> CheckedContinuation<LogReadOutcome, Never>? in
            defer { continuation = nil }
            return continuation
        }
        guard let taken else { return false }
        onFinish?()
        taken.resume(returning: outcome)
        return true
    }
}

private enum LogReadOutcome { case finished, failed, timedOut }
