import Foundation
import Testing

@testable import YakKit

struct MarkdownBlocksTests {
    @Test func tablesOverTheCellLimitStayParagraphs() {
        let header = "|" + String(repeating: "a|", count: 200) + "\n|" + String(repeating: "-|", count: 200) + "\n"
        let text = header + String(repeating: "|\n", count: 100_000)
        let clock = ContinuousClock()
        let start = clock.now
        let blocks = MarkdownBlocks.parse(text)
        #expect(clock.now - start < .seconds(10))
        #expect(!blocks.contains { if case .table = $0 { true } else { false } })
        #expect(
            MarkdownBlocks.parse("| a | b |\n|---|---|\n| 1 | 2 |").contains {
                if case .table = $0 { true } else { false }
            })
    }

    @Test func theTableScanSplitsEachRowAboutOnce() {
        for rowCount in [1_000, 200_000] {
            let header = "|" + String(repeating: "a|", count: 200) + "\n|" + String(repeating: "-|", count: 200) + "\n"
            let text =
                header + String(repeating: "|\n", count: rowCount) + "\npara\n"
                + String(repeating: "|\n", count: rowCount)
            let result = MarkdownBlocks.parseCountingCellSplits(text)
            #expect(result.cellSplits <= 3 * (2 * rowCount + 10), "\(result.cellSplits) splits for \(rowCount) rows")
        }
    }

    @Test func aTableAtTheCellLimitKeepsItsRows() {
        let rowCount = MarkdownBlocks.maximumTableCells / 2 - 1
        let text = "a|b\n-|-\n" + String(repeating: "1|2\n", count: rowCount)
        // The whole weight budget is the table, so the last row is cut only when the block itself costs one.
        guard case .table(_, let rows)? = MarkdownBlocks.parse(text).first else {
            Issue.record("expected a table")
            return
        }
        #expect(rows.count >= rowCount - 2)
    }

    @Test func textPastTheWeightBudgetIsKeptWhole() {
        let text = String(repeating: "a\n\n", count: MarkdownBlocks.maximumWeight + 500)
        let blocks = MarkdownBlocks.parse(text)
        guard case .unformatted(let rest)? = blocks.last else {
            Issue.record("expected plain text at the end")
            return
        }
        #expect(blocks.count - 1 + rest.split(separator: "\n").count == MarkdownBlocks.maximumWeight + 500)
    }

    @Test func splitsCommonBlocks() {
        let text =
            "# Plan\n\nThe limit is in `RateLimit`.\nSecond line.\n\n- one\n- two\n\n"
            + "```php\n$a = 1;\n```\n\n> Note\n\n---"
        #expect(
            MarkdownBlocks.parse(text) == [
                .heading(level: 1, text: "Plan"), .paragraph("The limit is in `RateLimit`.\nSecond line."),
                .list(ordered: false, start: 1, items: ["one", "two"]), .code(language: "php", text: "$a = 1;"),
                .quote([.paragraph("Note")]), .rule,
            ])
    }

    @Test func parsesATable() {
        #expect(
            MarkdownBlocks.parse("| Header | Value |\n|---|:-:|\n| a \\| b | 2 |")
                == [.table(header: ["Header", "Value"], rows: [["a | b", "2"]])])
    }

    @Test func anUnclosedFenceIsCodeToTheEnd() {
        #expect(MarkdownBlocks.parse("```\nlet a = 1") == [.code(language: nil, text: "let a = 1")])
    }

    @Test func aLongerFenceIsNotClosedByAShorterOne() {
        #expect(MarkdownBlocks.parse("~~~~\n~~~\nx\n~~~~") == [.code(language: nil, text: "~~~\nx")])
    }

    @Test func orderedListsKeepTheirStart() {
        #expect(MarkdownBlocks.parse("3. c\n4. d") == [.list(ordered: true, start: 3, items: ["c", "d"])])
    }

    @Test func deeperLinesStayInTheirItem() {
        #expect(
            MarkdownBlocks.parse("- a\n  - nested\n- b")
                == [.list(ordered: false, start: 1, items: ["a\n- nested", "b"])])
    }

    @Test func quotesParseRecursively() {
        #expect(
            MarkdownBlocks.parse("> # Title\n> - x")
                == [.quote([.heading(level: 1, text: "Title"), .list(ordered: false, start: 1, items: ["x"])])])
    }

    @Test(arguments: ["#hashtag", "|not a table", "  indented", "a\n2. not interrupting"])
    func nothingIsDropped(_ text: String) {
        #expect(MarkdownBlocks.parse(text) == [.paragraph(text)])
    }

    @Test func parsesScenarioTextWithoutLoss() throws {
        let names = ["simple-turn", "steer", "question", "failed-turn", "stream-reset", "pointer"]
        for name in names {
            let list = try JSONSerialization.jsonObject(with: Scenarios.data("events/\(name)"))
            var texts: [String] = []
            func collect(_ value: Any) {
                if let dictionary = value as? [String: Any] {
                    for (key, child) in dictionary {
                        if key == "text", let text = child as? String { texts.append(text) } else { collect(child) }
                    }
                } else if let array = value as? [Any] {
                    array.forEach(collect)
                }
            }
            collect(list)
            #expect(!texts.isEmpty)
            for text in texts {
                let joined = MarkdownBlocks.parse(text).map(flatten).joined(separator: " ")
                let words = text.split(whereSeparator: \.isWhitespace)
                for word in words {
                    let core = word.trimmingCharacters(in: .punctuationCharacters.union(.symbols))
                    if core.isEmpty { continue }
                    #expect(joined.contains(core), "\(name) lost \(core)")
                }
            }
        }
    }

    @Test func deeplyNestedQuotesDoNotOverflowTheStack() async {
        let blocks = await Task.detached {
            MarkdownBlocks.parse(String(repeating: ">", count: 10_000) + " x")
        }.value
        var current = blocks
        var text = ""
        while let first = current.first {
            if case .quote(let inner) = first {
                current = inner
            } else {
                if case .paragraph(let value) = first { text = value }
                break
            }
        }
        #expect(text.contains("x"))
    }

    private func flatten(_ block: MarkdownBlock) -> String {
        switch block {
        case .paragraph(let text), .code(_, let text), .heading(_, let text): text
        case .list(_, _, let items): items.joined(separator: " ")
        case .quote(let blocks): blocks.map(flatten).joined(separator: " ")
        case .table(let header, let rows): (header + rows.flatMap { $0 }).joined(separator: " ")
        case .rule: ""
        case .unformatted(let text): text
        }
    }

    @Test func aQuotePastTheBudgetKeepsItsRestAsPlainTextInsideTheQuote() {
        let text = String(repeating: "> a\n>\n", count: 4_000)
        let blocks = MarkdownBlocks.parse(text)
        guard blocks.count == 1, case .quote(let inner) = blocks[0], case .unformatted(let rest)? = inner.last else {
            Issue.record("expected one quote ending in plain text, got \(blocks.count) blocks")
            return
        }
        #expect(inner.count - 1 + rest.split(separator: "\n").count == 4_000)
    }

    @Test func aLongCodeBlockInAQuoteIsOneBlock() {
        let text = "> ```\n" + String(repeating: "> let a = 1\n", count: 16_000) + "> ```"
        guard case .quote(let inner)? = MarkdownBlocks.parse(text).first, inner.count == 1, case .code = inner[0] else {
            Issue.record("expected a quote with one code block")
            return
        }
    }

    @Test func tablesWithTooManyColumnsStayParagraphs() {
        let columns = MarkdownBlocks.maximumTableColumns + 1
        let text =
            "|" + String(repeating: "h|", count: columns) + "\n|" + String(repeating: "-|", count: columns)
            + "\n|" + String(repeating: "c|", count: columns) + "\n"
        #expect(!MarkdownBlocks.parse(text).contains { if case .table = $0 { true } else { false } })
    }
}
