import Foundation
import UniformTypeIdentifiers
import YakKit
import os

/// A time bound on work that answers through a callback and may never call it, such as a provider whose file is still
/// in iCloud or whose source app hung. The late answer of a timed-out call is ignored.
enum TimeBound {
    /// Runs `start`, which calls its callback once, and returns what the callback gave, or `fallback` after `seconds`.
    @MainActor static func run<Value: Sendable>(
        seconds: Double, fallback: Value, _ start: (@escaping @Sendable (Value) -> Void) -> Void
    ) async -> Value {
        let gate = Gate<Value>()
        return await withCheckedContinuation { continuation in
            gate.install(
                continuation,
                timer: Task {
                    do { try await Task.sleep(for: .seconds(seconds)) } catch { return }
                    gate.finish(fallback)
                })
            start { gate.finish($0) }
        }
    }

    /// Resumes the continuation once, from whichever of the callback and the timer comes first.
    private final class Gate<Value: Sendable>: @unchecked Sendable {
        private let lock = NSLock()
        private var continuation: CheckedContinuation<Value, Never>?
        private var timer: Task<Void, Never>?
        private var isDone = false

        func install(_ continuation: CheckedContinuation<Value, Never>, timer: Task<Void, Never>) {
            lock.withLock {
                self.continuation = continuation
                self.timer = timer
            }
        }

        func finish(_ value: Value) {
            let pending: (CheckedContinuation<Value, Never>?, Task<Void, Never>?) = lock.withLock {
                guard !isDone else { return (nil, nil) }
                isDone = true
                defer {
                    continuation = nil
                    timer = nil
                }
                return (continuation, timer)
            }
            pending.1?.cancel()
            pending.0?.resume(returning: value)
        }
    }
}

/// The providers of one drop. Each file is opened where it lives (no copy), once to ask its size and again, only if it
/// is accepted, to read it; every load is bounded in time, so one stuck file never holds the drop.
@MainActor
struct DroppedFiles {
    /// The longest one provider may take to answer.
    static let timeoutPerFile = 15.0
    private nonisolated static let logger = Logger(subsystem: "Yak", category: "Drop")

    let providers: [NSItemProvider]
    var timeoutPerFile = Self.timeoutPerFile

    struct Unreadable: Error {}

    /// The type to load: the first one that is data or an image.
    private func typeIdentifier(_ provider: NSItemProvider) -> (identifier: String, type: UTType)? {
        for identifier in provider.registeredTypeIdentifiers {
            if let type = UTType(identifier), type.conforms(to: .data) || type.conforms(to: .image) {
                return (identifier, type)
            }
        }
        return nil
    }

    /// The size and the name of file `index`, read without copying it. A missing size means the file could not be read.
    func stat(_ index: Int) async -> (item: DroppedItem, name: String) {
        guard providers.indices.contains(index), let found = typeIdentifier(providers[index]) else {
            return (DroppedItem(byteCount: nil, isImage: false), "")
        }
        let provider = providers[index]
        let isImage = found.type.conforms(to: .image)
        let answer = await TimeBound.run(seconds: timeoutPerFile, fallback: Optional<Stat>.none) { finish in
            _ = provider.loadInPlaceFileRepresentation(forTypeIdentifier: found.identifier) { url, _, error in
                guard let url, error == nil else {
                    Self.logger.error("A dropped file could not be opened")
                    finish(nil)
                    return
                }
                let size = (try? url.resourceValues(forKeys: [.fileSizeKey]).fileSize).flatMap { $0 }
                finish(Stat(byteCount: size, name: url.lastPathComponent))
            }
        }
        if answer == nil { Self.logger.error("A dropped file did not answer in time or failed") }
        return (DroppedItem(byteCount: answer?.byteCount, isImage: isImage), answer?.name ?? "")
    }

    /// Reads file `index` up to `limit + 1` bytes, so one byte past the limit shows that the file grew after the stat.
    func read(_ index: Int, limit: Int) async throws -> (Data, String, String) {
        guard providers.indices.contains(index), let found = typeIdentifier(providers[index]) else {
            throw Unreadable()
        }
        let provider = providers[index]
        let contentType = found.type.preferredMIMEType ?? "application/octet-stream"
        let read = await TimeBound.run(seconds: timeoutPerFile, fallback: Optional<Read>.none) { finish in
            _ = provider.loadInPlaceFileRepresentation(forTypeIdentifier: found.identifier) { url, _, error in
                guard let url, error == nil, let handle = try? FileHandle(forReadingFrom: url) else {
                    finish(nil)
                    return
                }
                defer { try? handle.close() }
                let data = (try? handle.read(upToCount: limit + 1)) ?? Data()
                finish(Read(data: data, name: url.lastPathComponent))
            }
        }
        guard let read else { throw Unreadable() }
        return (read.data, read.name, contentType)
    }

    private struct Stat: Sendable {
        let byteCount: Int?
        let name: String
    }

    private struct Read: Sendable {
        let data: Data
        let name: String
    }
}
