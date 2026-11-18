import Foundation
import YakKit

#if canImport(os)
    import os
#endif

/// What the importer needs from a shared item. `NSItemProvider` is wrapped by `SystemShareProvider`; tests fake it.
protocol ShareItemProvider: Sendable {
    var suggestedName: String? { get }
    var kind: ShareItemKind { get }
    /// An image prepared from its file, never held at full size.
    func loadImage(maximumBytes: Int) async throws -> PreparedAttachment
    func loadURL() async throws -> URL
    /// At most `maximumBytes` bytes of text.
    func loadText(maximumBytes: Int) async throws -> Data
}

enum ShareItemKind: Sendable { case image, link, text, other }

struct ShareImportProgress: Equatable, Sendable {
    var current: Int
    var total: Int
    var isSlow: Bool
}

struct ShareImport: Equatable, Sendable {
    var inputs: [ShareInput] = []
    var unreadable = 0
    var tooLarge: [String] = []
}

/// Loads shared items one at a time: each image is prepared from its file and written to the staging folder before
/// the next is read.
struct ShareItemImporter: Sendable {
    /// Each provider load is given up after this and counted as unreadable.
    static let loadTimeout: Duration = .seconds(30)
    /// After this on one item, the model shows the "Still preparing" line.
    static let slowAfter: Duration = .seconds(8)
    /// Shared text is read through a data representation of at most this many bytes, then cut there.
    static let textByteLimit = 81_920

    let files: DiskFileStore
    let staging: String
    let clock: any YakClock
    let maximumBytes: @Sendable () -> Int

    init(files: DiskFileStore, staging: String, clock: any YakClock, maximumBytes: @escaping @Sendable () -> Int) {
        self.files = files
        self.staging = staging
        self.clock = clock
        self.maximumBytes = maximumBytes
    }

    func load(_ providers: [any ShareItemProvider], progress: @escaping @Sendable (ShareImportProgress) -> Void) async
        -> ShareImport
    {
        var result = ShareImport()
        var imageNumber = 0
        for (index, provider) in providers.enumerated() {
            if Task.isCancelled { break }
            progress(ShareImportProgress(current: index + 1, total: providers.count, isSlow: false))
            let slow = Task {
                do { try await clock.sleep(for: Self.slowAfter) } catch { return }
                progress(ShareImportProgress(current: index + 1, total: providers.count, isSlow: true))
            }
            let outcome = await Self.race(timeout: Self.loadTimeout, clock: clock) { await load(provider) }
            slow.cancel()
            switch outcome {
            case nil: result.unreadable += 1
            case .some(let loaded):
                switch loaded {
                case .image(let prepared):
                    imageNumber += 1
                    let name = "\(imageNumber)-" + ComposerAttachments.safeFileName(prepared.fileName)
                    do {
                        try files.write(prepared.data, to: "\(staging)/\(name)", protection: .untilFirstUnlock)
                        result.inputs.append(.image(fileName: name))
                    } catch {
                        result.unreadable += 1
                    }
                case .link(let url): result.inputs.append(.link(url))
                case .text(let text): result.inputs.append(.text(text))
                case .tooLarge(let name): result.tooLarge.append(name)
                case .unreadable: result.unreadable += 1
                }
            }
        }
        return result
    }

    private enum Loaded: Sendable {
        case image(PreparedAttachment)
        case link(URL)
        case text(String)
        case tooLarge(String)
        case unreadable
    }

    private func load(_ provider: any ShareItemProvider) async -> Loaded {
        switch provider.kind {
        case .image:
            do {
                return .image(try await provider.loadImage(maximumBytes: maximumBytes()))
            } catch let problem as AttachmentProblem {
                if case .tooLarge = problem { return .tooLarge(provider.suggestedName ?? "An image") }
                return .unreadable
            } catch {
                return .unreadable
            }
        case .link:
            do { return .link(try await provider.loadURL()) } catch { return .unreadable }
        case .text:
            do {
                let data = try await provider.loadText(maximumBytes: Self.textByteLimit)
                return .text(Self.decode(data.prefix(Self.textByteLimit)))
            } catch {
                return .unreadable
            }
        case .other: return .unreadable
        }
    }

    /// A cut inside a multi-byte character drops the partial character instead of decoding it as a replacement.
    static func decode(_ data: some DataProtocol) -> String {
        var bytes = Data(data)
        for _ in 0..<4 {
            if let text = String(data: bytes, encoding: .utf8) { return text }
            if bytes.isEmpty { break }
            bytes.removeLast()
        }
        return String(decoding: bytes, as: UTF8.self)
    }

    /// How long a cancelled load may take to stop before the importer goes on regardless.
    static let cancelGrace: Duration = .seconds(5)

    /// The first of the load and the timeout wins. The loser is cancelled and waited for up to `cancelGrace`, so two
    /// full-size decodes never run at once in the extension's small memory.
    private static func race<Value: Sendable>(
        timeout: Duration, clock: any YakClock, _ work: @escaping @Sendable () async -> Value
    ) async -> Value? {
        await withCheckedContinuation { (continuation: CheckedContinuation<Value?, Never>) in
            let once = Once<Value?>(continuation)
            let loading = Task {
                let value = await work()
                once.finish(once.hasTimedOut ? nil : value)
            }
            Task {
                do { try await clock.sleep(for: timeout) } catch { return }
                once.markTimedOut()
                loading.cancel()
                do { try await clock.sleep(for: cancelGrace) } catch {}
                once.finish(nil)
            }
        }
    }

    private final class Once<Value: Sendable>: @unchecked Sendable {
        private let lock = NSLock()
        private var continuation: CheckedContinuation<Value, Never>?
        private var timedOut = false
        var hasTimedOut: Bool { lock.withLock { timedOut } }
        func markTimedOut() { lock.withLock { timedOut = true } }
        init(_ continuation: CheckedContinuation<Value, Never>) { self.continuation = continuation }
        func finish(_ value: Value) {
            let taken = lock.withLock { () -> CheckedContinuation<Value, Never>? in
                defer { continuation = nil }
                return continuation
            }
            taken?.resume(returning: value)
        }
    }
}

/// The system's item provider as a shared item.
struct SystemShareProvider: ShareItemProvider, @unchecked Sendable {
    let provider: NSItemProvider

    var suggestedName: String? { provider.suggestedName }

    var kind: ShareItemKind {
        if provider.hasItemConformingToTypeIdentifier("public.image") { return .image }
        if provider.hasItemConformingToTypeIdentifier("public.url") { return .link }
        if provider.hasItemConformingToTypeIdentifier("public.plain-text") { return .text }
        return .other
    }

    func loadImage(maximumBytes: Int) async throws -> PreparedAttachment {
        let copy: URL = try await withCheckedThrowingContinuation { continuation in
            provider.loadFileRepresentation(forTypeIdentifier: "public.image") { url, error in
                guard let url else {
                    continuation.resume(throwing: error ?? CocoaError(.fileReadUnknown))
                    return
                }
                // The system removes the file when this handler returns, so it is copied first.
                let target = FileManager.default.temporaryDirectory
                    .appendingPathComponent("share-\(UUID().uuidString)-\(url.lastPathComponent)")
                do {
                    try FileManager.default.copyItem(at: url, to: target)
                    continuation.resume(returning: target)
                } catch {
                    continuation.resume(throwing: error)
                }
            }
        }
        defer { try? FileManager.default.removeItem(at: copy) }
        return try await AttachmentPreparer.prepare(
            fileAt: copy, fileName: suggestedName.map { $0 + ".jpg" } ?? "Photo.jpg", contentType: "image/jpeg",
            maximumBytes: maximumBytes)
    }

    func loadURL() async throws -> URL {
        try await withCheckedThrowingContinuation { continuation in
            _ = provider.loadObject(ofClass: URL.self) { url, error in
                if let url {
                    continuation.resume(returning: url)
                } else {
                    continuation.resume(throwing: error ?? CocoaError(.fileReadUnknown))
                }
            }
        }
    }

    func loadText(maximumBytes: Int) async throws -> Data {
        try await withCheckedThrowingContinuation { continuation in
            provider.loadFileRepresentation(forTypeIdentifier: "public.plain-text") { url, error in
                guard let url else {
                    continuation.resume(throwing: error ?? CocoaError(.fileReadUnknown))
                    return
                }
                do {
                    let handle = try FileHandle(forReadingFrom: url)
                    defer { try? handle.close() }
                    continuation.resume(returning: try handle.read(upToCount: maximumBytes) ?? Data())
                } catch {
                    continuation.resume(throwing: error)
                }
            }
        }
    }
}
