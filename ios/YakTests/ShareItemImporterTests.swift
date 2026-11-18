import Foundation
import Testing
import YakKit

@testable import Yak

private final class InFlight: @unchecked Sendable {
    private let lock = NSLock()
    private var current = 0
    private(set) var peak = 0
    private(set) var requestedTextBytes: [Int] = []
    func enter() {
        lock.withLock {
            current += 1
            peak = max(peak, current)
        }
    }
    func leave() { lock.withLock { current -= 1 } }
    func asked(_ bytes: Int) { lock.withLock { requestedTextBytes.append(bytes) } }
}

private struct FakeProvider: ShareItemProvider {
    enum Behavior: Sendable {
        case image, tooLarge, unreadable, hangs, stubborn
        case text(Data)
    }
    let behavior: Behavior
    let counter: InFlight
    var kind: ShareItemKind {
        if case .text = behavior { return .text }
        return .image
    }
    var suggestedName: String? { "Photo" }

    func loadImage(maximumBytes: Int) async throws -> PreparedAttachment {
        counter.enter()
        defer { counter.leave() }
        try await Task.sleep(for: .milliseconds(5))
        switch behavior {
        case .image:
            return PreparedAttachment(
                data: Data([1, 2, 3]), fileName: "p.jpg", contentType: "image/jpeg", isImage: true)
        case .tooLarge: throw AttachmentProblem.tooLarge(maximumBytes: maximumBytes)
        case .hangs: try await Task.sleep(for: .seconds(3600))
        case .stubborn:
            // Ignores cancellation, like a system provider decoding a large photo.
            await Task.detached { try? await Task.sleep(for: .milliseconds(300)) }.value
        default: throw AttachmentProblem.unreadable
        }
        throw AttachmentProblem.unreadable
    }
    func loadURL() async throws -> URL { throw URLError(.badURL) }
    func loadText(maximumBytes: Int) async throws -> Data {
        counter.asked(maximumBytes)
        if case .text(let data) = behavior { return data }
        return Data()
    }
}

private func importer(_ clock: any YakClock = SystemClock()) -> ShareItemImporter {
    let files = DiskFileStore(root: FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString))
    return ShareItemImporter(files: files, staging: "share-staging/test", clock: clock, maximumBytes: { 1000 })
}

struct ShareItemImporterTests {
    @Test func itemsLoadOneAtATime() async {
        let counter = InFlight()
        let providers = (0..<10).map { _ in FakeProvider(behavior: .image, counter: counter) }
        let result = await importer().load(providers) { _ in }
        #expect(counter.peak == 1)
        #expect(result.inputs.count == 10)
    }

    @Test func anUnreadableItemIsCountedAndALargeOneIsNamed() async {
        let counter = InFlight()
        let providers = [
            FakeProvider(behavior: .unreadable, counter: counter), FakeProvider(behavior: .tooLarge, counter: counter),
            FakeProvider(behavior: .image, counter: counter),
        ]
        let result = await importer().load(providers) { _ in }
        #expect(result.unreadable == 1)
        #expect(result.tooLarge == ["Photo"])
        #expect(result.inputs.count == 1)
    }

    @Test func aProviderThatNeverAnswersTimesOut() async throws {
        let clock = ManualClock()
        let counter = InFlight()
        let providers = [
            FakeProvider(behavior: .hangs, counter: counter), FakeProvider(behavior: .image, counter: counter),
        ]
        let slow = SlowFlag()
        let loading = Task { await importer(clock).load(providers) { if $0.isSlow { slow.set() } } }
        for _ in 0..<400 where clock.sleeperCount < 2 { try await Task.sleep(for: .milliseconds(10)) }
        clock.advance(.seconds(8))
        for _ in 0..<400 where !slow.value { try await Task.sleep(for: .milliseconds(10)) }
        #expect(slow.value)
        clock.advance(.seconds(22))
        let result = await loading.value
        #expect(result.unreadable == 1)
        #expect(result.inputs.count == 1)
    }

    @Test func aTimedOutLoadEndsBeforeTheNextOneStarts() async throws {
        let clock = ManualClock()
        let counter = InFlight()
        let providers = [
            FakeProvider(behavior: .stubborn, counter: counter), FakeProvider(behavior: .image, counter: counter),
        ]
        let loading = Task { await importer(clock).load(providers) { _ in } }
        for _ in 0..<400 where clock.sleeperCount < 2 { try await Task.sleep(for: .milliseconds(10)) }
        clock.advance(.seconds(30))
        let result = await loading.value
        #expect(counter.peak == 1)
        #expect(result.unreadable == 1)
        #expect(result.inputs.count == 1)
    }

    @Test func hugeTextIsReadOnlyUpToTheCap() async {
        let counter = InFlight()
        // The cap falls inside a three-byte character.
        let text = String(repeating: "\u{20AC}", count: 40_000)
        let provider = FakeProvider(behavior: .text(Data(text.utf8)), counter: counter)
        let result = await importer().load([provider]) { _ in }
        #expect(counter.requestedTextBytes == [ShareItemImporter.textByteLimit])
        guard case .text(let loaded)? = result.inputs.first else {
            Issue.record("no text")
            return
        }
        #expect(loaded.utf8.count <= ShareItemImporter.textByteLimit)
        #expect(!loaded.contains("\u{FFFD}"))
    }
}

private final class SlowFlag: @unchecked Sendable {
    private let lock = NSLock()
    private var flag = false
    func set() { lock.withLock { flag = true } }
    var value: Bool { lock.withLock { flag } }
}
