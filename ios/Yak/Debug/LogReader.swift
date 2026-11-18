import Foundation
import OSLog
import YakKit

struct RawLogEntry: Sendable {
    let date: Date
    let level: LogLevel
    let subsystem: String
    let category: String
    let message: String
}

/// Entries found so far, kept where the model can read them when a read is cut off.
final class LogCollector: @unchecked Sendable {
    private let lock = NSLock()
    private var entries: [RawLogEntry] = []

    func append(_ entry: RawLogEntry) { lock.withLock { entries.append(entry) } }
    func snapshot() -> [RawLogEntry] { lock.withLock { entries } }
}

protocol LogSource: Sendable {
    /// Adds at most `limit` entries since `since` to `collector`, oldest first, for the subsystems "Yak" and "YakKit".
    func read(since: Date, limit: Int, into collector: LogCollector) async throws
}

/// The process's own log, through `OSLogStore`; the only place that names it.
struct LogReader: LogSource {
    func read(since: Date, limit: Int, into collector: LogCollector) async throws {
        let store = try OSLogStore(scope: .currentProcessIdentifier)
        let sequence = try store.getEntries(
            at: store.position(date: since),
            matching: NSPredicate(format: "subsystem == %@ OR subsystem == %@", "Yak", "YakKit"))
        var count = 0
        for entry in sequence {
            try Task.checkCancellation()
            guard let log = entry as? OSLogEntryLog else { continue }
            collector.append(
                RawLogEntry(
                    date: log.date, level: Self.level(of: log.level), subsystem: log.subsystem,
                    category: log.category, message: log.composedMessage))
            count += 1
            if count >= limit { break }
        }
    }

    private static func level(of level: OSLogEntryLog.Level) -> LogLevel {
        switch level {
        case .debug: .debug
        case .info: .info
        case .notice: .notice
        case .error: .error
        case .fault: .fault
        default: .notice
        }
    }
}
