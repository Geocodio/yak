import Foundation
import Testing
import YakKit

@testable import Yak

@Suite struct MarkdownRendererTests {
    @Test(arguments: ["https://example.com/a", "http://example.com", "mailto:someone@example.com"])
    func opensWebAndMailLinks(_ link: String) {
        #expect(PlainMarkdownRenderer.opensLink(URL(string: link)!))
    }

    @Test(arguments: ["yak://pair?code=abc", "tel:123", "javascript:alert(1)", "file:///etc/hosts", "sms:123"])
    func discardsEveryOtherScheme(_ link: String) {
        #expect(!PlainMarkdownRenderer.opensLink(URL(string: link)!))
    }

    @Test func nestedListLinesBecomeSubItemRows() {
        let rows = PlainMarkdownRenderer().parse("- parent\n  - child one\n  - child two\n    more\n- next")
        #expect(rows.map(\.text) == ["parent", "child one", "child two\nmore", "next"])
        #expect(rows.map(\.decoration.indent) == [0, 1, 1, 0])
        #expect(rows.allSatisfy { $0.decoration.marker == .bullet })
    }

    @Test func listsAlignOrderedMarkersToTheWidestNumber() {
        let rows = PlainMarkdownRenderer().parse((1...10).map { "\($0). item" }.joined(separator: "\n"))
        #expect(rows.count == 10)
        #expect(rows.allSatisfy { $0.decoration.markerColumns == 3 })
        #expect(rows.map(\.decoration.marker) == (1...10).map { .number($0) })
    }

    @Test func parsesInlineSyntaxIntoStyledText() {
        let rows = PlainMarkdownRenderer().parse("Some **bold** text")
        #expect(rows.map(\.text) == ["Some bold text"])
    }

    @Test func quotesAndListsFlattenIntoDecoratedRows() async {
        let rows = await AppMarkdownRenderer().parse("> quote\n>\n> - item\n\n- outer\n  - inner\n\n  > in item")
        #expect(rows.map(\.text) == ["quote", "item", "outer", "inner", "in item"])
        #expect(rows.map(\.decoration.quoteDepth) == [1, 1, 0, 0, 1])
        #expect(rows.map(\.decoration.indent) == [0, 0, 0, 1, 1])
    }
}

extension MarkdownRow {
    /// The text a row shows, for tests.
    var text: String {
        switch kind {
        case .text(let text): String(text.characters)
        case .code(let text): text
        case .table(let header, let rows, _):
            (header + rows.flatMap { $0 }).map { String($0.characters) }.joined(separator: "|")
        case .rule, .notice, .pageBreak: ""
        }
    }
}
