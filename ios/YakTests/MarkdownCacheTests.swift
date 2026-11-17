import Foundation
import SwiftUI
import Testing
import UIKit
import YakKit

@testable import Yak

private final class ParseCounter: @unchecked Sendable {
    private let lock = NSLock()
    private var value = 0
    var count: Int { lock.withLock { value } }
    func increment() { lock.withLock { value += 1 } }
}

private struct CountingRenderer: MarkdownRendering {
    let counter: ParseCounter
    nonisolated func parse(_ markdown: String) -> String {
        counter.increment()
        return markdown
    }
    @MainActor func view(_ document: String) -> AnyView { AnyView(Text(verbatim: document)) }
}

/// A clock that only moves when the test advances it; sleepers resume once their time has come, or throw when
/// their task is cancelled, as the real clock does.
final class ManualClock: YakClock, @unchecked Sendable {
    private let lock = NSLock()
    private var current = Date(timeIntervalSince1970: 1_000)
    private var nextID = 0
    private var sleepers: [(id: Int, due: Date, continuation: CheckedContinuation<Void, Error>)] = []

    func now() -> Date { lock.withLock { current } }

    func sleep(for duration: Duration) async throws {
        let id = lock.withLock { () -> Int in
            nextID += 1
            return nextID
        }
        try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { continuation in
                let isCancelled = lock.withLock { () -> Bool in
                    if Task.isCancelled { return true }
                    let seconds = Double(duration.components.seconds) + Double(duration.components.attoseconds) / 1e18
                    sleepers.append((id, current.addingTimeInterval(seconds), continuation))
                    return false
                }
                if isCancelled { continuation.resume(throwing: CancellationError()) }
            }
        } onCancel: {
            let cancelled = lock.withLock { () -> CheckedContinuation<Void, Error>? in
                guard let index = sleepers.firstIndex(where: { $0.id == id }) else { return nil }
                return sleepers.remove(at: index).continuation
            }
            cancelled?.resume(throwing: CancellationError())
        }
    }

    var sleeperCount: Int { lock.withLock { sleepers.count } }

    /// How many sleepers wake after exactly `duration` from now.
    func sleeperCount(waking duration: Duration) -> Int {
        let seconds = Double(duration.components.seconds) + Double(duration.components.attoseconds) / 1e18
        return lock.withLock { sleepers.filter { abs($0.due.timeIntervalSince(current) - seconds) < 0.0005 }.count }
    }

    func advance(_ duration: Duration) {
        let seconds = Double(duration.components.seconds) + Double(duration.components.attoseconds) / 1e18
        let ready: [CheckedContinuation<Void, Error>] = lock.withLock {
            current = current.addingTimeInterval(seconds)
            // Floating point sums of milliseconds can land a hair short of a due time.
            let limit = current.addingTimeInterval(0.0005)
            let due = sleepers.filter { $0.due <= limit }
            sleepers.removeAll { $0.due <= limit }
            return due.map(\.continuation)
        }
        for continuation in ready { continuation.resume() }
    }
}

private func makeItem(_ key: String, _ text: String, streaming: Bool = false) -> ConversationItem {
    var item = ConversationItem(kind: .text, key: key, sequence: 1, text: text)
    item.isStreaming = streaming
    return item
}

@Suite struct MarkdownCacheTests {
    private func makeCache() -> (MarkdownCache<CountingRenderer>, ParseCounter, ManualClock) {
        let counter = ParseCounter()
        let clock = ManualClock()
        let cache = MarkdownCache(renderer: CountingRenderer(counter: counter), clock: clock, onUpdate: { _ in })
        return (cache, counter, clock)
    }

    @Test func parsesAFinishedItemOnce() async {
        let (cache, counter, _) = makeCache()
        for _ in 0..<3 { await cache.update(with: [makeItem("a", "hello")]) }
        #expect(counter.count == 1)
        #expect(await cache.document(for: makeItem("a", "hello")) == "hello")
    }

    @Test func aChangedFinishedTextParsesAgain() async {
        let (cache, counter, _) = makeCache()
        await cache.update(with: [makeItem("a", "one")])
        await cache.update(with: [makeItem("a", "two")])
        #expect(counter.count == 2)
        #expect(await cache.document(for: makeItem("a", "two")) == "two")
    }

    @Test func streamingParsesAtMostTenTimesASecond() async {
        let (cache, counter, clock) = makeCache()
        for step in 1...20 {
            await cache.update(with: [makeItem("a", "text \(step)", streaming: true)])
            guard step < 20 else { break }
            // A sleeper registers asynchronously; the clock must not move before it has.
            if step == 2 || step == 11 { while clock.sleeperCount == 0 { await Task.yield() } }
            clock.advance(.milliseconds(10))
            // The trailing parse scheduled by the second update is due once 100 ms have passed.
            if step == 10 { for _ in 0..<1000 where counter.count < 2 { await Task.yield() } }
        }
        // Two seconds of updates would be twenty parses without the throttle; the elapsed throttle parses again.
        #expect(counter.count == 2)
        clock.advance(.milliseconds(100))
        for _ in 0..<1000 where counter.count < 3 { await Task.yield() }
        #expect(counter.count == 3)
        #expect(await cache.document(for: makeItem("a", "")) == "text 20")
    }

    @Test func evictingAKeyDropsItsPendingParse() async {
        let (cache, counter, clock) = makeCache()
        await cache.update(with: [makeItem("a", "one", streaming: true)])
        await cache.update(with: [makeItem("a", "two", streaming: true)])
        while clock.sleeperCount == 0 { await Task.yield() }
        let others = (0..<MarkdownCache<CountingRenderer>.capacity).map { makeItem("key-\($0)", "text \($0)") }
        await cache.update(with: others)
        let parsesBefore = counter.count
        clock.advance(.milliseconds(100))
        for _ in 0..<200 { await Task.yield() }
        #expect(counter.count == parsesBefore)
        #expect(await cache.document(for: makeItem("a", "")) == nil)
    }

    @Test func finishingAStreamingItemParsesTheFinalText() async {
        let (cache, _, _) = makeCache()
        await cache.update(with: [makeItem("a", "par", streaming: true)])
        await cache.update(with: [makeItem("a", "partial", streaming: true)])
        await cache.update(with: [makeItem("a", "partial done")])
        #expect(await cache.document(for: makeItem("a", "")) == "partial done")
    }

    @Test func dropsTheLeastRecentlyUsed() async {
        let (cache, _, _) = makeCache()
        let items = (0...400).map { makeItem("key-\($0)", "text \($0)") }
        await cache.update(with: items)
        #expect(await cache.document(for: items[0]) == nil)
        #expect(await cache.document(for: items[400]) == "text 400")
    }

    private static let appSample = """
        # Plan

        The limit is in `RateLimit` and ~~was~~ is **fixed**, see [notes](https://example.com/n).

        - one
          - nested
        - [x] done
        - [ ] open

        1. first
        2. second

        ```php
        $a = 1;
        ```

        > Note

        | Header | Value |
        |---|:-:|
        | a \\| b | 2 |

        ---
        """

    @MainActor private func render(_ document: [MarkdownRow]) {
        let renderer = AppMarkdownRenderer()
        let controller = UIHostingController(rootView: renderer.view(document))
        controller.view.frame = CGRect(x: 0, y: 0, width: 320, height: 600)
        controller.view.layoutIfNeeded()
        #expect(controller.view.sizeThatFits(CGSize(width: 320, height: CGFloat.greatestFiniteMagnitude)).height > 0)
    }

    @MainActor @Test func theAppRendererParsesTablesAndCode() async {
        let document = await AppMarkdownRenderer().parse(Self.appSample)
        // heading, paragraph, 3 items and a nested one, 2 ordered items, code, quote, table, rule
        #expect(document.count == 12)
        #expect(document[0].decoration.headingLevel == 1 && document[0].text == "Plan")
        guard case .text(let paragraph) = document[1].kind else {
            Issue.record("expected a paragraph, got \(document.map(\.text))")
            return
        }
        #expect(paragraph.runs.contains { $0.inlinePresentationIntent == .stronglyEmphasized })
        #expect(paragraph.runs.contains { $0.inlinePresentationIntent == .strikethrough })
        #expect(paragraph.runs.contains { $0.link == URL(string: "https://example.com/n") })
        #expect(
            document[2...5].map(\.decoration.marker) == [
                .bullet, .bullet, .task(isChecked: true), .task(isChecked: false),
            ])
        #expect(document[6...7].map(\.decoration.marker) == [.number(1), .number(2)])
        #expect(document[8].text == "$a = 1;")
        #expect(document[9].decoration.quoteDepth == 1)
        guard case .table(let header, let rows, _) = document[10].kind else {
            Issue.record("expected a table")
            return
        }
        #expect(header.map { String($0.characters) } == ["Header", "Value"])
        #expect(rows.map { $0.map { String($0.characters) } } == [["a | b", "2"]])
        guard case .rule = document[11].kind else {
            Issue.record("expected a rule")
            return
        }
        render(document)
    }

    @MainActor @Test func anUnclosedFenceRendersAsCode() async {
        let document = await AppMarkdownRenderer().parse("Intro\n\n```swift\nlet a = 1\nlet b = ")
        guard document.count == 2, case .code(let code) = document[1].kind else {
            Issue.record("expected a paragraph then code, got \(document.map(\.text))")
            return
        }
        #expect(code == "let a = 1\nlet b = ")
        render(document)
    }

    @MainActor @Test func aHalfTableRendersAsText() async {
        let document = await AppMarkdownRenderer().parse("| Header | Value |\n|---")
        #expect(!document.isEmpty)
        render(document)
    }

    @MainActor @Test func rawHtmlRendersAsText() async {
        let document = await AppMarkdownRenderer().parse("<div class=\"x\">hello</div>\n\nInline <b>bold</b> here.")
        let text = document.map(\.text).joined(separator: " ")
        #expect(text.contains("<div class=\"x\">hello</div>"))
        #expect(text.contains("<b>") && text.contains("bold") && text.contains("here."))
        render(document)
    }

    @Test func anImageKeepsItsAddress() async {
        let document = await AppMarkdownRenderer().parse("See ![chart](https://example.com/c.png) now.")
        #expect(document.map(\.text) == ["See chart (https://example.com/c.png) now."])
    }

    @Test func aVeryLongDocumentParsesOffTheMainActor() async {
        let paragraph = "A **line** with `code` and more.\n\n"
        let text = String(repeating: paragraph, count: 5_500)
        #expect(text.count > 100_000)
        let rowCount = await Task.detached { await AppMarkdownRenderer().parse(text).count }.value
        // The document budget cuts it; the rest is plain text in further rows.
        #expect(rowCount > MarkdownBlocks.maximumWeight)
    }

    /// Hostile nesting, parsed on a cooperative-pool thread as the cache does; swift-markdown recurses once per level.
    @Test(arguments: [100, 1000, 5000])
    func deepNestingDoesNotCrash(levels: Int) async {
        let samples = [
            String(repeating: "> ", count: levels) + "deep",
            (0..<levels).map { String(repeating: "  ", count: $0) + "- item" }.joined(separator: "\n"),
            String(repeating: "- ", count: levels) + "deep",
            String(repeating: "**", count: levels) + "deep",
            String(repeating: "*a ", count: levels) + "deep",
            String(repeating: "[", count: levels) + "deep",
        ]
        for sample in samples {
            let blocks = await Task.detached { await AppMarkdownRenderer().parse(sample) }.value
            #expect(!blocks.isEmpty)
        }
    }

    @Test func ordinaryMarkdownKeepsItsStructureBelowTheNestingLimit() async {
        let text = String(repeating: "> ", count: 20) + "quoted\n\n" + String(repeating: "**bold** ", count: 300)
        let blocks = await Task.detached { await AppMarkdownRenderer().parse(text) }.value
        #expect(blocks.first?.decoration.quoteDepth == 5, "expected a quote, got \(blocks.map(\.text))")
    }
}
