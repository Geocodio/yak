import Foundation
import Testing

@testable import YakKit

struct MarkdownBlocksTests {
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
                for word in words where word.allSatisfy({ $0.isLetter || $0.isNumber }) {
                    #expect(joined.contains(word), "\(name) lost \(word)")
                }
            }
        }
    }

    private func flatten(_ block: MarkdownBlock) -> String {
        switch block {
        case .paragraph(let text), .code(_, let text), .heading(_, let text): text
        case .list(_, _, let items): items.joined(separator: " ")
        case .quote(let blocks): blocks.map(flatten).joined(separator: " ")
        case .table(let header, let rows): (header + rows.flatMap { $0 }).joined(separator: " ")
        case .rule: ""
        }
    }
}
