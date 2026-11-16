import Foundation
import Testing
import YakKit

@testable import Yak

/// Hostile Markdown must come back without a crash and without a long wait, whatever the nesting, the line
/// endings or the length. Every parse runs off the main actor, as the cache runs it.
@Suite struct MarkdownHardeningTests {
    private static let timeLimit = Duration.seconds(20)

    /// Parses off the main actor and reports how long it took.
    private func parseOffMainActor(_ text: String) async -> (blocks: [MarkdownRow], elapsed: Duration) {
        await Task.detached {
            let clock = ContinuousClock()
            let start = clock.now
            let blocks = await AppMarkdownRenderer().parse(text)
            return (blocks, clock.now - start)
        }.value
    }

    private func expectSurvives(_ text: String, _ label: String) async {
        let result = await parseOffMainActor(text)
        #expect(!result.blocks.isEmpty, "\(label) produced nothing")
        #expect(result.elapsed < Self.timeLimit, "\(label) took \(result.elapsed)")
    }

    // MARK: Building blocks

    /// Repeating pieces. Each repeats `levels` times on one line, then closes where the syntax needs closing.
    enum Piece: CaseIterable {
        case quote, tightQuote, bulletSpace, bulletTab, orderedDotTab, orderedParenTab, plusTab, star
        case emphasis, strong, strikethrough, link, inlineCode, indentedList

        func text(levels: Int) -> String {
            let open = { (marker: String) in String(repeating: marker, count: levels) }
            switch self {
            case .quote: return open("> ") + "x"
            case .tightQuote: return open(">") + "x"
            case .bulletSpace: return open("- ") + "x"
            case .bulletTab: return open("-\t") + "x"
            case .orderedDotTab: return open("1.\t") + "x"
            case .orderedParenTab: return open("1)\t") + "x"
            case .plusTab: return open("+\t") + "x"
            case .star: return open("*\t") + "x"
            case .emphasis: return open("*a ") + "x " + open("a* ")
            case .strong: return open("**a ") + "x " + open("a** ")
            case .strikethrough: return open("~~a ") + "x " + open("a~~ ")
            case .link: return open("![a ") + "x" + open("](u) ")
            case .inlineCode: return open("`a") + "x"
            case .indentedList: return String(repeating: " ", count: levels) + "- x"
            }
        }
    }

    private static let separators = ["\n", "\r", "\r\n", "\u{2028}", "\u{2029}", "\u{85}", "\u{0B}", "\u{0C}"]

    /// A text of `chunks` copies of the piece, each at most 1 000 levels, joined by the separator, so a scanner
    /// that splits on one kind of line ending sees a different line structure from the parser.
    private func chunked(_ piece: Piece, levels: Int, separator: String) -> String {
        let perChunk = 1_000
        var parts: [String] = []
        var remaining = levels
        while remaining > 0 {
            parts.append(piece.text(levels: min(perChunk, remaining)))
            remaining -= perChunk
        }
        return parts.joined(separator: separator)
    }

    // MARK: Nesting

    @Test(arguments: [5_000, 50_000], Piece.allCases)
    func deepNestingSurvives(levels: Int, piece: Piece) async {
        // Some of these are over the length cap and some under it; both paths must survive.
        await expectSurvives(piece.text(levels: levels), "\(piece) x \(levels)")
    }

    @Test(arguments: [5_000, 50_000], Piece.allCases)
    func deepNestingSurvivesAnyLineEnding(levels: Int, piece: Piece) async {
        for separator in ["\r", "\r\n", "\u{2028}", "\u{2029}", "\u{85}"] {
            await expectSurvives(
                chunked(piece, levels: levels, separator: separator),
                "\(piece) x \(levels) with \(separator.unicodeScalars.map(\.value))")
        }
    }

    @Test func aMixOfEverythingSurvives() async {
        let text = Piece.allCases.map { $0.text(levels: 3_000) }.joined(separator: "\r\n")
        await expectSurvives(text, "mix")
        await expectSurvives(Piece.allCases.map { $0.text(levels: 3_000) }.joined(separator: "\r"), "mix with returns")
    }

    @Test func staircaseIndentationSurvives() async {
        let text = (0..<600).map { String(repeating: "\t", count: $0 % 7) + String(repeating: " ", count: $0) + "- x" }
        for separator in ["\n", "\r", "\r\n"] {
            await expectSurvives(text.joined(separator: separator), "staircase")
        }
    }

    @Test func randomNestingsSurvive() async {
        var random = SplitMix64(seed: 0x59414B)
        for caseNumber in 0..<300 {
            var parts: [String] = []
            for _ in 0..<Int.random(in: 1...6, using: &random) {
                let piece = Piece.allCases.randomElement(using: &random)!
                let levels = [1, 10, 100, 1_000, 5_000, 20_000].randomElement(using: &random)!
                parts.append(piece.text(levels: levels))
            }
            let separator = (Self.separators + ["", " ", "\n\n"]).randomElement(using: &random)!
            let text = parts.joined(separator: separator)
            await expectSurvives(text, "random case \(caseNumber)")
        }
    }

    @Test func textOverTheLengthCapShowsWholeAsPlainText() async {
        let text = String(repeating: "word **not bold** \n", count: 20_000)
        #expect(text.utf8.count > SwiftMarkdownRenderer.maximumInputBytes)
        let result = await parseOffMainActor(text)
        guard case .notice = result.blocks.first?.kind else {
            Issue.record("expected the notice first")
            return
        }
        let rest = result.blocks.dropFirst().filter {
            if case .pageBreak = $0.kind { false } else { true }
        }
        #expect(rest.allSatisfy { $0.decoration.isUnformatted })
        #expect(rest.map(\.text).joined() == text)
    }

    /// Everything past the budget stays readable: the formatted blocks and the plain text together hold every line.
    @Test(arguments: [false, true])
    func overBudgetTextIsKeptAsPlainText(isFallback: Bool) async {
        let prefix = isFallback ? String(repeating: " ", count: 401) + "x\n\n" : ""
        let inputs: [(String, String)] = [
            ("paragraphs", String(repeating: "a\n\n", count: 20_000)),
            ("items", String(repeating: "- a\n", count: 20_000)),
            (
                "rows",
                (0..<10).map { _ in "a|b\n-|-\n" + String(repeating: "1|2\n", count: 250) }.joined(separator: "\n")
            ),
        ]
        for (name, body) in inputs {
            let result = await parseOffMainActor(prefix + body)
            let tail = result.blocks.filter { $0.decoration.isUnformatted }
            guard !tail.isEmpty else {
                Issue.record("\(name): no plain text at the end")
                continue
            }
            let remainder = tail.map(\.text).joined().split(separator: "\n").count
            let formatted = Self.countLines(result.blocks.filter { !$0.decoration.isUnformatted }[...])
            let expected = name == "rows" ? 2_510 : 20_000 + (isFallback ? 1 : 0)
            // A table counts its header row as a line; delimiter rows belong to the plain text or to neither side.
            #expect(formatted + remainder >= expected - 15, "\(name): \(formatted) + \(remainder)")
            #expect(formatted + remainder <= expected + 15, "\(name): \(formatted) + \(remainder)")
        }
    }

    private static func countLines(_ rows: ArraySlice<MarkdownRow>) -> Int {
        rows.reduce(0) { total, row in
            switch row.kind {
            case .text: total + 1
            case .table(let header, let body, _): total + (header.isEmpty ? 0 : 1) + body.count
            default: total
            }
        }
    }

    @Test func pipesAreCountedFromTheLastLineEndTheParserKnows() {
        let group = String(repeating: "|", count: MarkdownNestingBound.maximumPipesPerLine / 2 + 500)
        for separator in ["\u{2028}", "\u{2029}", "\u{85}", "\u{0B}", "\u{0C}"] {
            #expect(
                MarkdownNestingBound.exceedsLimits(group + separator + group),
                "\(separator.unicodeScalars.map(\.value))")
        }
        for separator in ["\n", "\r", "\r\n"] {
            #expect(!MarkdownNestingBound.exceedsLimits(group + separator + group))
        }
    }

    @Test func aSmallTableAfterAnOverCapTableStillRendersAsATable() {
        let big =
            "|" + String(repeating: "a|", count: 100) + "\n|" + String(repeating: "-|", count: 100) + "\n"
            + String(repeating: "|\n", count: 100)
        let blocks = MarkdownBlocks.parse(big + "\n| a | b |\n|---|---|\n| 1 | 2 |\n")
        #expect(blocks.contains { if case .table(let header, _) = $0 { header == ["a", "b"] } else { false } })
    }

    @Test func textJustUnderTheLengthCapStillParses() async {
        let line = "a **b** c\n\n"
        let text = String(repeating: line, count: SwiftMarkdownRenderer.maximumInputBytes / line.utf8.count - 1)
        #expect(text.utf8.count <= SwiftMarkdownRenderer.maximumInputBytes)
        let result = await parseOffMainActor(text)
        #expect(result.blocks.count <= MarkdownRow.maximumRowCount(forBytes: text.utf8.count))
        #expect(result.blocks.contains { $0.decoration.isUnformatted })
        #expect(result.elapsed < Self.timeLimit)
    }

    // MARK: The bound

    @Test(arguments: ["\n", "\r", "\r\n", "\u{2028}", "\u{2029}", "\u{85}"])
    func theBoundSeesEveryKindOfMarkerAndLineEnding(separator: String) {
        let limit = MarkdownNestingBound.maximumLineNesting
        for marker in ["> ", ">", "- ", "-\t", "1.\t", "1)\t", "+\t", "*\t", "\t", "          "] {
            let prefix = String(repeating: marker, count: limit + 1)
            #expect(MarkdownNestingBound.exceedsLimits(prefix + "x"), "\(marker.unicodeScalars.map(\.value))")
            #expect(MarkdownNestingBound.exceedsLimits("ok" + separator + prefix + "x"))
            #expect(MarkdownNestingBound.exceedsLimits("ok" + separator + separator + prefix + separator + "ok"))
        }
    }

    @Test func theBoundCountsDelimitersAcrossEveryLineEndingExceptBlankLines() {
        let limit = MarkdownNestingBound.maximumDelimitersPerParagraph
        let delimiters = String(repeating: "*a ", count: limit / 2 + 1)
        for separator in ["\n", "\r", "\r\n", "\u{2028}", "\u{2029}", "\u{85}", "\n \u{2028} \n"] {
            #expect(MarkdownNestingBound.exceedsLimits(delimiters + separator + delimiters), "\(separator)")
        }
        #expect(!MarkdownNestingBound.exceedsLimits(delimiters + "\n\n" + delimiters))
        #expect(!MarkdownNestingBound.exceedsLimits(delimiters + "\r\n\r\n" + delimiters))
        #expect(!MarkdownNestingBound.exceedsLimits(delimiters + "\r\r" + delimiters))
        // Unicode line breaks and non-space whitespace never count as a blank line.
        #expect(MarkdownNestingBound.exceedsLimits(delimiters + "\n\u{A0}\n" + delimiters))
    }

    // MARK: Limits against the measured overflow

    /// Depths at which the 64 MB parse thread overflowed, measured with a probe that runs
    /// `Markdown.Document(parsing:)` on a `Thread` with `stackSize = 64 * 1024 * 1024` (macOS, Debug build,
    /// swift-markdown 0.9.0, Apple silicon, 2026-11-16). Each value is the smallest depth tried that crashed;
    /// the depth below it parsed. Nested quotes and indented lists are the fragile shapes; inline spans are
    /// recorded in `inlineOverflowDepth`.
    private static let quoteOverflowDepth = 80_000
    private static let indentedListOverflowDepth = 40_000
    private static let inlineOverflowDepth = 80_000

    @Test func theLimitsAreFarBelowTheMeasuredOverflow() {
        let marginFactor = 20
        // A level takes at least one character of the run, so the run limit is also the level limit.
        #expect(MarkdownNestingBound.maximumLineNesting * marginFactor <= Self.indentedListOverflowDepth)
        #expect(MarkdownNestingBound.maximumLineNesting * marginFactor <= Self.quoteOverflowDepth)
        #expect(MarkdownNestingBound.maximumDelimitersPerParagraph * marginFactor <= Self.inlineOverflowDepth)
    }

    // MARK: Ordinary content under the limits

    @Test func quotesJustUnderTheLineLimitKeepTheirStructure() async {
        let levels = MarkdownNestingBound.maximumLineNesting / 2
        let text = String(repeating: "> ", count: levels) + "deep quote"
        #expect(!MarkdownNestingBound.exceedsLimits(text))
        let result = await parseOffMainActor(text)
        #expect((result.blocks.first?.decoration.quoteDepth ?? 0) > 0, "expected a quote, got \(result.blocks)")
        #expect(Self.flatten(result.blocks).contains("deep quote"))
    }

    @Test func everyBlockKindUnderTheLimitsKeepsItsStructure() async {
        let text =
            "# Title\n\n- one\n- two\n\n1. first\n2. second\n\n> quoted\n\n```\ncode\n```\n\n---\n\nPlain **bold**."
        #expect(!MarkdownNestingBound.exceedsLimits(text))
        let result = await parseOffMainActor(text)
        let rows = result.blocks
        guard rows.count == 9, rows[0].decoration.headingLevel == 1, rows[1].decoration.marker == .bullet,
            rows[3].decoration.marker == .number(1), rows[5].decoration.quoteDepth == 1, case .code = rows[6].kind,
            case .rule = rows[7].kind
        else {
            Issue.record("unexpected rows \(rows.map(\.text))")
            return
        }
        guard case .text(let last) = rows[rows.count - 1].kind else { return }
        #expect(last.runs.contains { $0.inlinePresentationIntent == .stronglyEmphasized })
    }

    @Test func aParagraphJustUnderTheDelimiterLimitKeepsItsStyling() async {
        let limit = MarkdownNestingBound.maximumDelimitersPerParagraph
        let text = String(repeating: "a_b ", count: limit - 5) + "**bold**"
        #expect(!MarkdownNestingBound.exceedsLimits(text))
        let result = await parseOffMainActor(text)
        let runs = result.blocks.flatMap { row -> [AttributedString.Runs.Run] in
            if case .text(let text) = row.kind { Array(text.runs) } else { [] }
        }
        #expect(runs.contains { $0.inlinePresentationIntent == .stronglyEmphasized })
    }

    @Test func nestingPastTheWalkLimitStillShowsItsText() async {
        let text = String(repeating: "> ", count: 150) + "inner words"
        #expect(!MarkdownNestingBound.exceedsLimits(text))
        let result = await parseOffMainActor(text)
        #expect(Self.flatten(result.blocks).contains("inner words"))
        let emphasis = String(repeating: "*a ", count: 300) + "inner words " + String(repeating: "a* ", count: 300)
        #expect(!MarkdownNestingBound.exceedsLimits(emphasis))
        let styled = await parseOffMainActor(emphasis)
        #expect(Self.flatten(styled.blocks).contains("inner words"))
    }

    /// A wide table with many short rows, which the fallback pads to the header width.
    private static func wideTable(columns: Int, targetBytes: Int) -> String {
        let header =
            "|" + String(repeating: "a|", count: columns) + "\n|" + String(repeating: "-|", count: columns) + "\n"
        return header + String(repeating: "|\n", count: (targetBytes - header.utf8.count) / 2)
    }

    @Test(arguments: [2_000, 200])
    func wideTablesWithManyShortRowsStayCheapOnTheFallbackPath(columns: Int) async {
        let text = String(repeating: " ", count: 401) + "x\n\n" + Self.wideTable(columns: columns, targetBytes: 199_000)
        #expect(MarkdownNestingBound.exceedsLimits(text))
        await expectSurvives(text, "fallback table, \(columns) columns")
        let underscores =
            String(repeating: "_", count: 4_001) + "\n\n" + Self.wideTable(columns: columns, targetBytes: 199_000)
        await expectSurvives(underscores, "fallback table behind underscores, \(columns) columns")
    }

    @Test func aWideTableHeaderStaysCheapOnTheParserPath() async {
        let text = Self.wideTable(columns: 1_000, targetBytes: 199_000)
        #expect(!MarkdownNestingBound.exceedsLimits(text))
        let result = await parseOffMainActor(text)
        #expect(result.elapsed < Self.timeLimit)
        for row in result.blocks {
            if case .table(let header, _, _) = row.kind { #expect(header.count <= MarkdownBlocks.maximumTableCells) }
        }
        #expect(Self.flatten(result.blocks).contains("a"))
    }

    @Test func aLeadingByteOrderMarkCountsTowardTheRun() async {
        for count in [1_000, 60_000, 199_000] {
            let text = "\u{FEFF}" + String(repeating: ">", count: count) + "x"
            #expect(MarkdownNestingBound.exceedsLimits(text), "\(count) quotes behind a byte order mark")
        }
        await expectSurvives("\u{FEFF}" + String(repeating: ">", count: 60_000) + "x", "byte order mark quotes")
    }

    @Test func manyOverCapTableTriesStayLinear() async {
        let text = String(repeating: "_", count: 4_001) + "\n\n" + String(repeating: "-|\n", count: 60_000)
        await expectSurvives(text, "one-column table rows past the cell cap")
    }

    @Test func aListItemWithManyContinuationLinesStaysLinear() async {
        let text = String(repeating: "_", count: 4_001) + "\n\n- a\n" + String(repeating: "b\n", count: 95_000)
        let result = await parseOffMainActor(text)
        #expect(result.elapsed < .seconds(2), "took \(result.elapsed)")
    }

    @Test func aRowWithManyPipesTakesTheFallbackPath() async {
        let text = "a|b\n-|-\n" + String(repeating: "|", count: 199_000)
        #expect(MarkdownNestingBound.exceedsLimits(text))
        let result = await parseOffMainActor(text)
        #expect(result.elapsed < .seconds(2), "took \(result.elapsed)")
    }

    @Test func aTableUnderTheCellLimitStillRendersAsATable() async {
        let text = "| a | b |\n|---|---|\n| 1 | 2 |\n| 3 | 4 |\n"
        for blocks in [
            await parseOffMainActor(text).blocks,
            await parseOffMainActor(String(repeating: " ", count: 401) + "x\n\n" + text).blocks,
        ] {
            #expect(
                blocks.contains {
                    if case .table(let header, let rows, _) = $0.kind {
                        header.count == 2 && rows.count == 2
                    } else {
                        false
                    }
                })
        }
    }

    private static func flatten(_ rows: [MarkdownRow]) -> String { rows.map(\.text).joined(separator: "\n") }
}

/// Text that bypassed the line cap keeps every character and arrives in pieces within the cap.
@Suite struct MarkdownTextCapTests {
    private static func texts(_ rows: [MarkdownRow]) -> [String] {
        rows.compactMap { if case .text = $0.kind { $0.text } else { nil } }
    }

    @Test(arguments: [
        String(repeating: "a\n", count: 5_000) + "===",
        "<div>\n" + String(repeating: "a\n", count: 5_000),
    ])
    func longLineRunsAreCut(text: String) async {
        let pieces = Self.texts(await AppMarkdownRenderer().parse(text))
        #expect(pieces.count > 1)
        for piece in pieces {
            #expect(MarkdownBlocks.lineBreakCount(in: piece) <= MarkdownRow.maximumLines)
        }
    }

    @Test func overCapTableKeepsItsSource() async {
        let text = "a|b\n-|-\n" + String(repeating: "1|2\n", count: 2_000)
        let joined = Self.texts(await AppMarkdownRenderer().parse(text)).joined()
        #expect(joined.hasPrefix("a|b\n-|-\n1|2\n"))
        #expect(joined.components(separatedBy: "1|2").count - 1 == 2_000)
    }
}

/// A small seeded generator so a failing random case can be replayed.
private struct SplitMix64: RandomNumberGenerator {
    var state: UInt64
    init(seed: UInt64) { state = seed }
    mutating func next() -> UInt64 {
        state &+= 0x9E37_79B9_7F4A_7C15
        var value = state
        value = (value ^ (value >> 30)) &* 0xBF58_476D_1CE4_E5B9
        value = (value ^ (value >> 27)) &* 0x94D0_49BB_1331_11EB
        return value ^ (value >> 31)
    }
}

/// What one row may be asked to lay out: line breaks of every kind, styled runs and long texts stay within limits.
@Suite struct MarkdownViewBudgetTests {
    private static let separators = ["\n", "\r", "\r\n", "\u{0B}", "\u{0C}", "\u{85}", "\u{2028}", "\u{2029}"]

    private static func parse(_ text: String) async -> [MarkdownRow] {
        await Task.detached { await AppMarkdownRenderer().parse(text) }.value
    }

    private static func texts(_ rows: [MarkdownRow]) -> [AttributedString] {
        rows.compactMap { if case .text(let text) = $0.kind { text } else { nil } }
    }

    @Test(arguments: separators)
    func aParagraphBrokenOnAnyLineBreakIsCutIntoRows(separator: String) async {
        let text = String(repeating: "a" + separator, count: 20_000)
        let lines = Self.texts(await Self.parse(text)).map { MarkdownBlocks.lineBreakCount(in: String($0.characters)) }
        #expect(lines.count > 1)
        #expect(lines.allSatisfy { $0 <= MarkdownRow.maximumLines })
    }

    @Test func lineBreaksDecodedFromEntitiesAreCountedToo() async {
        let rows = await Self.parse(String(repeating: "a&#8232;", count: 20_000))
        let lines = Self.texts(rows).map { MarkdownBlocks.lineBreakCount(in: String($0.characters)) }
        #expect(lines.count > 1)
        #expect(lines.allSatisfy { $0 <= MarkdownRow.maximumLines })
    }

    @Test func normalizingKeepsCrlfAsOneBreak() {
        #expect(MarkdownBlocks.normalizingLineBreaks("a\r\nb\rc\u{2028}d\u{0C}e") == "a\nb\nc\nd\ne")
        #expect(MarkdownBlocks.lineBreakCount(in: "a\r\nb") == 1)
    }

    @Test(arguments: ["<https://a.b> ", "[a](https://a.b) ", "![a](b) ", "`a` ", "**a** "])
    func denseRunsLoseTheirStylingButKeepTheirText(unit: String) async {
        let count = 190_000 / unit.utf8.count
        let texts = Self.texts(await Self.parse(String(repeating: unit, count: count)))
        #expect(texts.allSatisfy { InlineLimits.weight(of: $0) <= MarkdownRow.maximumWeight })
        let total = texts.reduce(0) { $0 + InlineLimits.weight(of: $1) }
        #expect(total <= InlineLimits.maximumDocumentWeight + texts.count)
        #expect(!texts.isEmpty && texts.allSatisfy { !String($0.characters).isEmpty })
    }

    @Test func fewLinksKeepTheirStyling() async {
        let rows = await Self.parse("see [a](https://a.b) and **b**")
        #expect(InlineLimits.weight(of: Self.texts(rows)[0]) == 8)
    }

    @Test func linksInAQuoteShareTheDocumentBudget() async {
        let quote = String(repeating: "> " + String(repeating: "[a](https://a.b) ", count: 50) + "\n>\n", count: 30)
        let rows = await Self.parse(quote)
        #expect(rows.allSatisfy { $0.decoration.quoteDepth == 1 })
        let weight = Self.texts(rows).reduce(0) { $0 + InlineLimits.weight(of: $1) }
        #expect(weight <= InlineLimits.maximumDocumentWeight + rows.count)
    }

    @Test func aLongParagraphIsCutIntoRowsAtWhitespace() {
        let text = AttributedString(String(repeating: "word ", count: 20_000))
        let pieces = MarkdownRowBuilder().pieces(of: text)
        #expect(pieces.map { String($0.characters) }.joined() == String(text.characters))
        #expect(pieces.allSatisfy { $0.characters.count <= MarkdownRow.maximumCharacters })
        #expect(pieces.dropLast().allSatisfy { String($0.characters).hasSuffix(" ") })
    }

    @Test func aHugeListItemContinuesInLaterRowsWithoutRenumbering() async {
        let rows = await Self.parse("1. " + String(repeating: "word ", count: 20_000) + "\n2. b\n")
        let markers = rows.map(\.decoration.marker)
        #expect(markers.first == .number(1) && markers.last == .number(2))
        #expect(markers.dropFirst().dropLast().allSatisfy { $0 == .none })
        #expect(rows.allSatisfy { $0.decoration.markerColumns == 2 })
    }

    @Test func aDenseTableIsCutIntoSmallPieces() async {
        let row = "|" + String(repeating: "w w w w w|", count: 16) + "\n"
        let text =
            "|" + String(repeating: "h|", count: 16) + "\n|" + String(repeating: "-|", count: 16) + "\n"
            + String(repeating: row, count: 36)
        let rows = await Self.parse(text)
        for row in rows {
            if case .table(let header, let body, _) = row.kind {
                #expect(header.count + body.count * 16 <= MarkdownRow.maximumCells)
            }
        }
        #expect(rows.count >= 8)
    }

    @Test func aTableWithAHugeCellStaysAsText() async {
        let text = "a|b\n-|-\n" + String(repeating: "x", count: MarkdownRow.maximumCellCharacters + 1) + "|b\n"
        let rows = await Self.parse(text)
        #expect(!rows.contains { if case .table = $0.kind { true } else { false } })
        #expect(
            rows.map(\.text).joined().contains(String(repeating: "x", count: MarkdownRow.maximumCellCharacters + 1)))
    }
}
