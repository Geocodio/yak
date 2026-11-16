import Foundation
import Testing
import YakKit

@testable import Yak

/// The row invariant over every document the other suites use, and over random documents built from every block
/// and inline kind, nested at random.
@Suite struct MarkdownRowTests {
    /// The row limits hold for every row, and the document has no more rows than its size allows.
    static func expectWithinLimits(_ rows: [MarkdownRow], bytes: Int, _ label: String) {
        #expect(rows.count <= MarkdownRow.maximumRowCount(forBytes: bytes), "\(label): \(rows.count) rows")
        for row in rows where !MarkdownRow.fits(row.kind) {
            Issue.record("\(label): a row is over its limits")
            return
        }
        #expect(rows.allSatisfy { $0.decoration.indent <= MarkdownRow.maximumDrawnLevel })
        #expect(rows.allSatisfy { $0.decoration.quoteDepth <= MarkdownRow.maximumDrawnLevel })
    }

    @Test func theInitializerRefusesRowsOverTheLimits() {
        let decoration = MarkdownRow.Decoration()
        let tooLong = AttributedString(String(repeating: "a", count: MarkdownRow.maximumCharacters + 1))
        let tooManyLines = AttributedString(String(repeating: "a\n", count: MarkdownRow.maximumLines + 1))
        let tooHeavy = (0..<MarkdownRow.maximumWeight + 1).reduce(into: AttributedString()) { text, index in
            var run = AttributedString("a")
            run.inlinePresentationIntent = index.isMultiple(of: 2) ? .emphasized : .stronglyEmphasized
            text += run
        }
        let wideLine = String(repeating: "x", count: MarkdownRow.maximumCodeLineCharacters + 1)
        let manyCodeLines = String(repeating: "x\n", count: MarkdownRow.maximumCodeLines)
        let bigCell = AttributedString(String(repeating: "a", count: MarkdownRow.maximumCellCharacters + 1))
        let manyCells = Array(repeating: AttributedString("a"), count: MarkdownRow.maximumCells + 1)
        for kind in [
            MarkdownRow.Kind.text(tooLong), .text(tooManyLines), .text(tooHeavy), .code(wideLine), .code(manyCodeLines),
            .table(header: [bigCell], rows: [], columnWidths: [1]),
            .table(header: manyCells, rows: [], columnWidths: [Int](repeating: 1, count: manyCells.count)),
        ] {
            #expect(MarkdownRow(kind: kind, decoration: decoration) == nil)
        }
        #expect(MarkdownRow(kind: .text(AttributedString("a")), decoration: decoration) != nil)
    }

    @Test func wideGlyphsCountForTheirArea() {
        let decoration = MarkdownRow.Decoration()
        let emoji = AttributedString(
            String(repeating: "\u{1F600}", count: MarkdownRow.maximumCharacters / MarkdownRow.emojiCost + 1))
        #expect(MarkdownRow(kind: .text(emoji), decoration: decoration) == nil)
        let wideLine = String(
            repeating: "\u{1F600}", count: MarkdownRow.maximumCodeLineCharacters / MarkdownRow.emojiCost + 2)
        #expect(MarkdownRow(kind: .code(wideLine), decoration: decoration) == nil)
    }

    @Test func emojiMadeWithSelectorsAndKeycapsCountAsEmoji() {
        for text in ["1\u{FE0F}\u{20E3}", "\u{2764}\u{FE0F}", "\u{00A9}\u{FE0F}", "\u{1F600}"] {
            #expect(MarkdownRow.cost(of: text) == MarkdownRow.emojiCost, "\(text.unicodeScalars.count) scalars")
        }
    }

    @Test func aClusterCostsWhatItsScalarsDraw() {
        let chain = String(repeating: "\u{1F44D}\u{200D}", count: 28_000)
        #expect(MarkdownRow.cost(of: chain) >= 28_000 * MarkdownRow.emojiCost)
        #expect(
            MarkdownRow.cost(of: "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}") == 4
                * MarkdownRow.emojiCost)
    }

    @Test func oversizedClustersAreCutSoNoCharacterCostsMoreThanTheLimit() {
        let inputs = [
            String(repeating: "\u{1F44D}\u{200D}", count: 28_000), "a" + String(repeating: "\u{0301}", count: 99_000),
            String(repeating: "\u{1100}", count: 66_000),
        ]
        for text in inputs {
            let bounded = MarkdownRow.withBoundedClusters(text)
            #expect(bounded.allSatisfy { MarkdownRow.cost(of: $0) <= MarkdownRow.maximumClusterCost })
            #expect(bounded.unicodeScalars.filter { $0 != "\u{200B}" }.count == text.unicodeScalars.count)
        }
        #expect(
            MarkdownRow.withBoundedClusters("e\u{0301} \u{1F468}\u{200D}\u{1F469}")
                == "e\u{0301} \u{1F468}\u{200D}\u{1F469}")
    }

    @Test func longClustersBecomeBoundedRowsOnBothPaths() async {
        let chain = String(repeating: "\u{1F44D}\u{200D}", count: 28_000)
        for text in [chain, "```\n" + chain + "\n```", "> " + chain, "a|b\n-|-\n" + chain + "|" + chain] {
            let size = text.utf8.count
            Self.expectWithinLimits(
                await AppMarkdownRenderer().parse(text), bytes: size, "chain through swift-markdown")
            Self.expectWithinLimits(PlainMarkdownRenderer().parse(text), bytes: size, "chain through the fallback")
        }
    }

    @Test func aDocumentPastItsPageIsPagedAndLosesNothing() async {
        let text = String(repeating: "\u{1F600}", count: 49_000)
        for rows in [await AppMarkdownRenderer().parse(text), PlainMarkdownRenderer().parse(text)] {
            let breaks = rows.indices.filter { if case .pageBreak = rows[$0].kind { true } else { false } }
            #expect(breaks.count > 1)
            var start = 0
            for end in breaks + [rows.count] {
                let cost = rows[start..<end].reduce(0) { $0 + $1.displayCost }
                #expect(cost <= MarkdownRow.maximumDocumentCost + 1)
                start = end
            }
            #expect(rows.map(\.text).joined().count == 49_000)
        }
    }

    @MainActor @Test(arguments: MarkdownLayoutTests.Input.allCases) func everyTestDocumentKeepsTheInvariant(
        input: MarkdownLayoutTests.Input
    ) async {
        let text = input.text
        let rows = await Task.detached { await AppMarkdownRenderer().parse(text) }.value
        Self.expectWithinLimits(rows, bytes: text.utf8.count, "\(input) through swift-markdown")
        let plainRows = await Task.detached { PlainMarkdownRenderer().parse(text) }.value
        Self.expectWithinLimits(plainRows, bytes: text.utf8.count, "\(input) through the fallback")
    }

    @Test func noTextIsLostWhenALongDocumentIsCut() async {
        for text in [
            String(repeating: "word ", count: 39_000), "> " + String(repeating: "http ", count: 19_000),
            "- " + String(repeating: "word ", count: 19_000),
        ] {
            for rows in [await AppMarkdownRenderer().parse(text), PlainMarkdownRenderer().parse(text)] {
                let words = rows.map(\.text).joined(separator: " ").split(whereSeparator: \.isWhitespace)
                #expect(words.count == text.split(separator: " ").filter { $0 != ">" && $0 != "-" }.count)
            }
        }
    }

    // MARK: Random documents

    private static let inlineAtoms = [
        "word ", "**bold** ", "*italic* ", "`code` ", "[link](https://a.b) ", "<https://a.b> ", "![alt](u) ", "~~s~~ ",
        "&#8232;", "\u{2028}", "\u{0C}", "http ", "a|b ", "\\| ", "**", "[", "_ ",
        String(repeating: "\u{1F44D}\u{200D}", count: 120), "e" + String(repeating: "\u{0301}", count: 400),
    ]

    private static func inline(_ random: inout SeededRandom, words: Int) -> String {
        (0..<words).map { _ in inlineAtoms.randomElement(using: &random)! }.joined()
    }

    /// Lines of one random block, without prefixes.
    private static func block(_ random: inout SeededRandom) -> [String] {
        let words = [1, 5, 40, 400, 4_000].randomElement(using: &random)!
        switch Int.random(in: 0..<13, using: &random) {
        case 0: return [inline(&random, words: words)]
        case 1:
            return [
                String(repeating: "#", count: Int.random(in: 1...6, using: &random)) + " " + inline(&random, words: 3)
            ]
        case 2: return (0..<Int.random(in: 1...30, using: &random)).map { _ in "- " + inline(&random, words: 4) }
        case 3: return (1...Int.random(in: 1...30, using: &random)).map { "\($0). " + inline(&random, words: 4) }
        case 4: return ["- [x] " + inline(&random, words: 3), "- [ ] " + inline(&random, words: 3)]
        case 5:
            return ["```"]
                + (0..<Int.random(in: 0...300, using: &random)).map { _ in
                    String(repeating: "x", count: Int.random(in: 0...1_500, using: &random))
                } + ["```"]
        case 6:
            let columns = Int.random(in: 1...70, using: &random)
            let cells = { (text: String) in "|" + String(repeating: text + "|", count: columns) }
            return [cells("h"), cells("-")]
                + (0..<Int.random(in: 0...200, using: &random)).map { _ in
                    cells(String(inline(&random, words: 2).prefix(Int.random(in: 0...700, using: &random))))
                }
        case 7: return ["---"]
        case 8: return ["<div>"] + (0..<Int.random(in: 0...50, using: &random)).map { _ in inline(&random, words: 3) }
        case 9: return [inline(&random, words: 3), "==="]
        case 10:
            return ["- a"]
                + (0..<Int.random(in: 0...50, using: &random)).map { _ in "  - " + inline(&random, words: 3) }
        case 11: return (0..<Int.random(in: 1...20, using: &random)).map { _ in inline(&random, words: 10) }
        default: return [inline(&random, words: 2)] + (0..<words / 2).map { _ in "word" }
        }
    }

    /// A document of about `bytes` bytes: blocks prefixed with random chains of quote and list markers.
    static func document(_ random: inout SeededRandom, bytes: Int) -> String {
        let prefixes = ["> ", "- ", "  ", "1. ", ">", "\t", "> - ", "    "]
        var lines: [String] = []
        var size = 0
        while size < bytes {
            let prefix = (0..<Int.random(in: 0...4, using: &random)).map { _ in prefixes.randomElement(using: &random)!
            }.joined()
            for line in block(&random) {
                lines.append(prefix + line)
                size += prefix.utf8.count + line.utf8.count + 1
            }
            lines.append(Bool.random(using: &random) ? "" : prefix)
        }
        return lines.joined(separator: ["\n", "\r\n", "\r"].randomElement(using: &random)!)
    }

    @Test func randomDocumentsKeepTheInvariantOnBothPaths() async {
        var random = SeededRandom(seed: 0x59414B_0003)
        for caseNumber in 0..<40 {
            let bytes = [200, 5_000, 60_000, 190_000].randomElement(using: &random)!
            let text = String(Self.document(&random, bytes: bytes).prefix(SwiftMarkdownRenderer.maximumInputBytes))
            let size = text.utf8.count
            let rows = await Task.detached { await AppMarkdownRenderer().parse(text) }.value
            Self.expectWithinLimits(rows, bytes: size, "case \(caseNumber) through swift-markdown")
            let plainRows = await Task.detached { PlainMarkdownRenderer().parse(text) }.value
            Self.expectWithinLimits(plainRows, bytes: size, "case \(caseNumber) through the fallback")
        }
    }
}

/// A seeded generator so a failing random case can be replayed.
struct SeededRandom: RandomNumberGenerator {
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
