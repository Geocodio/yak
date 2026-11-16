import Foundation
import Testing
import YakKit

@testable import Yak

/// Ordinary content is shown whole: realistic long answers do not page, no block breaks into pieces, and the
/// text round-trips exactly. Every document goes through both renderers.
@Suite struct MarkdownOrdinaryTests {
    private static func rendered(_ text: String) async -> [(name: String, rows: [MarkdownRow])] {
        [
            ("swift-markdown", await AppMarkdownRenderer().parse(text)),
            ("fallback", PlainMarkdownRenderer().parse(text)),
        ]
    }

    private static func isPageBreak(_ row: MarkdownRow) -> Bool {
        if case .pageBreak = row.kind { true } else { false }
    }

    private static func isNotice(_ row: MarkdownRow) -> Bool {
        if case .notice = row.kind { true } else { false }
    }

    /// No page break, no notice that the formatting stopped, and no row that continues the block above it.
    private static func expectShownWhole(_ rows: [MarkdownRow], _ label: String) {
        #expect(!rows.contains(where: isPageBreak), "\(label) has a page break")
        #expect(!rows.contains(where: isNotice), "\(label) lost its formatting")
        #expect(rows.allSatisfy { $0.decoration.spacing != .piece || $0.decoration.markerColumns > 0 }, "\(label)")
        #expect(MarkdownPager(rows: rows).pages.count == 1, "\(label) has more than one page")
    }

    @Test func threeHundredParagraphsAreThreeHundredRows() async {
        for (name, rows) in await Self.rendered(MarkdownSamples.paragraphs) {
            Self.expectShownWhole(rows, "\(name)")
            #expect(rows.count == 300, "\(name): \(rows.count) rows")
            #expect(rows.map(\.text) == MarkdownSamples.paragraphTexts, "\(name)")
        }
    }

    @Test func aHundredAndFiftyItemsAreOneRowEach() async {
        let expected = MarkdownSamples.listItems.enumerated().map { index, item in
            index % 10 == 0 ? "\(item) (docs)" : item
        }
        for (name, rows) in await Self.rendered(MarkdownSamples.list) {
            Self.expectShownWhole(rows, "\(name)")
            #expect(rows.count == 150, "\(name): \(rows.count) rows")
            #expect(rows.map(\.text) == expected, "\(name)")
            #expect(rows.allSatisfy { $0.decoration.marker == .bullet }, "\(name)")
        }
    }

    @Test func aSixtyRowTableWithEightColumnsIsOneRow() async {
        for (name, rows) in await Self.rendered(MarkdownSamples.table) {
            Self.expectShownWhole(rows, "\(name)")
            #expect(rows.count == 1, "\(name): \(rows.count) rows")
            guard case .table(let header, let body, let widths)? = rows.first?.kind else {
                Issue.record("\(name): expected a table")
                continue
            }
            #expect(header.map { String($0.characters) } == MarkdownSamples.tableHeader, "\(name)")
            #expect(body.map { $0.map { String($0.characters) } } == MarkdownSamples.tableBody, "\(name)")
            #expect(widths.count == 8, "\(name)")
        }
    }

    @Test func aSixHundredLineCodeBlockIsOneRowAndCopiesExactly() async {
        for (name, rows) in await Self.rendered(MarkdownSamples.codeBlock) {
            Self.expectShownWhole(rows, "\(name)")
            #expect(rows.count == 1, "\(name): \(rows.count) rows")
            #expect(rows.first?.text == MarkdownSamples.codeLines.joined(separator: "\n"), "\(name)")
        }
    }

    @Test(arguments: [40, 70, 100])
    func aSixHundredLineTestLogWithAnEmojiOnEveryLineIsOneCodeRow(columns: Int) async {
        let lines = (0..<600).map { index -> String in
            let line = "\(index % 7 == 6 ? "\u{274C}" : "\u{2705}") test_case_\(index) passed in 0.0\(index % 10)s "
            return line + String(repeating: ".", count: max(0, columns - line.count))
        }
        let text = "Results:\n\n```\n" + lines.joined(separator: "\n") + "\n```\n\nAll good."
        for (name, rows) in await Self.rendered(text) {
            Self.expectShownWhole(rows, "\(name) \(columns) columns")
            let code = rows.filter { if case .code = $0.kind { true } else { false } }
            #expect(code.count == 1, "\(name) \(columns) columns: \(code.count) code rows")
            #expect(code.first?.text == lines.joined(separator: "\n"), "\(name) \(columns) columns")
        }
    }

    @Test(arguments: [2, 3])
    func severalEmojiLogsEachStayOneRowAndPageByTheirMemory(count: Int) async {
        let text = MarkdownLayoutTests.Input.emojiLogs(count)
        for (name, rows) in await Self.rendered(text) {
            let code = rows.filter { if case .code = $0.kind { true } else { false } }
            #expect(code.count == count, "\(name): \(code.count) code rows")
            #expect(rows.filter(Self.isPageBreak).count >= 1, "\(name) shows \(count) logs on one page")
            #expect(MarkdownPager(rows: rows).pages.count >= 2, "\(name)")
        }
    }

    @Test func nestedListsKeepTheirDepthAndNumbersInBothRenderers() async {
        let text = "1. Top item\n   - bullet\n     1. deep"
        for (name, rows) in await Self.rendered(text) {
            #expect(rows.map(\.text) == ["Top item", "bullet", "deep"], "\(name)")
            #expect(rows.map(\.decoration.indent) == [0, 1, 2], "\(name)")
            #expect(rows.map(\.decoration.marker) == [.number(1), .bullet, .number(1)], "\(name)")
        }
        for (name, rows) in await Self.rendered("- a\n    - b\n    - c\n- d") {
            #expect(rows.map(\.text) == ["a", "b", "c", "d"], "\(name)")
            #expect(rows.map(\.decoration.indent) == [0, 1, 1, 0], "\(name)")
        }
        for (name, rows) in await Self.rendered("1. one\n2. two\n    1. sub\n    2. sub2\n3. three") {
            #expect(rows.map(\.text) == ["one", "two", "sub", "sub2", "three"], "\(name)")
            #expect(rows.map(\.decoration.indent) == [0, 0, 1, 1, 0], "\(name)")
            #expect(
                rows.map(\.decoration.marker) == [.number(1), .number(2), .number(1), .number(2), .number(3)],
                "\(name)")
        }
    }

    @Test func aLongAnswerThatMixesEverythingShowsWholeOnOnePage() async {
        for (name, rows) in await Self.rendered(MarkdownSamples.mixed) {
            Self.expectShownWhole(rows, "\(name)")
            #expect(rows.count == MarkdownSamples.mixedRowCount, "\(name): \(rows.count) rows")
        }
    }

    @Test func emojiInEverySentenceStaysWhole() async {
        for (name, rows) in await Self.rendered(MarkdownSamples.emojiText) {
            Self.expectShownWhole(rows, "\(name)")
            #expect(rows.map(\.text) == MarkdownSamples.emojiParagraphs, "\(name)")
        }
    }

    @Test func aParagraphBreaksOnlyWhenItIsThousandsOfCharactersLong() async {
        let words = String(repeating: "word ", count: 700)
        for (name, rows) in await Self.rendered(words) {
            #expect(rows.count == 1, "\(name): \(rows.count) rows for \(words.count) characters")
        }
        let emoji = (0..<30).map { "Sentence \($0) with an emoji \u{1F680} and a flag \u{1F1E9}\u{1F1F0}." }.joined(
            separator: " ")
        for (name, rows) in await Self.rendered(emoji) {
            #expect(rows.count == 1, "\(name): \(rows.count) rows for \(emoji.count) characters")
        }
    }

    // MARK: Code

    @Test func anEmojiInsideCodeStaysOnItsLine() async {
        let code =
            "print(\"done \u{2705}\")\nlet flag = \"\u{1F1E9}\u{1F1F0}\"\nlet family = \"\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}\""
        for (name, rows) in await Self.rendered("```\n" + code + "\n```") {
            #expect(rows.count == 1, "\(name)")
            #expect(rows.first?.text == code, "\(name)")
            #expect(rows.first?.text.split(separator: "\n").count == 3, "\(name)")
        }
    }

    @Test func familyAndProfessionEmojiAreNeverCutApart() async {
        let sequences = [
            "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}",
            "\u{1F468}\u{1F3FD}\u{200D}\u{1F469}\u{1F3FB}\u{200D}\u{1F467}\u{1F3FF}\u{200D}\u{1F466}\u{1F3FE}",
            "\u{1F469}\u{200D}\u{1F4BB}", "\u{1F1E9}\u{1F1F0}", "\u{1F3F3}\u{FE0F}\u{200D}\u{1F308}",
        ]
        let line = sequences.joined(separator: " ")
        let text = String(repeating: line + "\n", count: 40)
        let expected = String(text.dropLast())
        for (name, rows) in await Self.rendered("```\n" + expected + "\n```") {
            #expect(rows.map(\.text).joined(separator: "\n") == expected, "\(name)")
            #expect(!rows.map(\.text).joined().unicodeScalars.contains("\u{200B}"), "\(name)")
        }
        for (name, rows) in await Self.rendered(String(repeating: line + " ", count: 400)) {
            #expect(!rows.map(\.text).joined().unicodeScalars.contains("\u{200B}"), "\(name)")
            #expect(rows.map(\.text).joined().contains(line), "\(name)")
        }
    }

    @Test func aLongCodeLineIsKeptWholeUpToItsLimit() async {
        let lines = [100, 400, 1_200, MarkdownRow.maximumCodeLineScalars].map { String(repeating: "x", count: $0) }
        let code = lines.joined(separator: "\n")
        for (name, rows) in await Self.rendered("```\n" + code + "\n```") {
            #expect(rows.map(\.text).joined(separator: "\n") == code, "\(name)")
        }
    }

    @Test func aCodeLineRunsPastItsLimitByOneCluster() {
        let family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}"
        let line = String(repeating: "x", count: MarkdownRow.maximumCodeLineScalars - 1) + family
        let builder = MarkdownRowBuilder()
        builder.addCode(line, MarkdownRow.Decoration())
        #expect(builder.rows.map(\.text) == [line])
    }

    @Test func aPathologicalClusterIsTheOnlyThingCut() {
        let chain = String(repeating: "\u{1F44D}\u{200D}", count: 28_000)
        let builder = MarkdownRowBuilder()
        builder.addCode(chain, MarkdownRow.Decoration())
        let shown = builder.rows.map(\.text).joined()
        #expect(shown.contains("\u{200B}"))
        #expect(shown.unicodeScalars.filter { $0 != "\u{200B}" && $0 != "\n" }.count == chain.unicodeScalars.count)
    }

    // MARK: Paging

    private static func pagedRows() -> [MarkdownRow] {
        let builder = MarkdownRowBuilder()
        let decoration = MarkdownRow.Decoration()
        for index in 0..<400 {
            builder.addPlain(String(repeating: "\u{2764}", count: 300) + " \(index)", decoration)
        }
        return builder.rows
    }

    @Test func tappingThroughThePagesMovesTheReaderAndKeepsTheirPlace() {
        let rows = Self.pagedRows()
        var pager = MarkdownPager(rows: rows)
        let count = pager.pages.count
        #expect(count >= 3, "\(count) pages")
        #expect(pager.current == 0 && !pager.hasPrevious && pager.hasNext)
        #expect(pager.showingPrevious == nil)

        var visited: [Int] = []
        while let next = pager.showingNext {
            // "Show more" lands at the top of the new page.
            #expect(next.target == MarkdownPager.ScrollTarget(row: next.pager.range.lowerBound, isTop: true))
            #expect(next.pager.current == pager.current + 1)
            pager = next.pager
            visited.append(pager.current)
        }
        #expect(visited == Array(1..<count))
        #expect(!pager.hasNext && pager.hasPrevious)

        while let previous = pager.showingPrevious {
            // "Show previous" lands on the end of the page before, where the reader left off.
            #expect(
                previous.target == MarkdownPager.ScrollTarget(row: previous.pager.range.upperBound - 1, isTop: false))
            pager = previous.pager
        }
        #expect(pager.current == 0)
    }

    @Test func everyRowBelongsToExactlyOnePageAndNoPageIsEmpty() {
        let rows = Self.pagedRows()
        let pager = MarkdownPager(rows: rows)
        let shown = pager.pages.flatMap { Array($0) }
        let expected = rows.indices.filter { !Self.isPageBreak(rows[$0]) }
        #expect(shown == expected)
        #expect(pager.pages.allSatisfy { !$0.isEmpty })
        for page in pager.pages {
            #expect(page.reduce(0) { $0 + rows[$1].displayCost } <= MarkdownRow.maximumPageCost)
        }
    }

    @Test func aPageNumberPastTheEndIsClamped() {
        let rows = Self.pagedRows()
        #expect(MarkdownPager(rows: rows, page: 99).current == MarkdownPager(rows: rows).pages.count - 1)
        #expect(MarkdownPager(rows: rows, page: -3).current == 0)
        #expect(MarkdownPager(rows: []).pages == [0..<0])
    }
}
