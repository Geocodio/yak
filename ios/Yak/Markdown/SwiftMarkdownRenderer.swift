import Foundation
import Markdown
import SwiftUI
import YakKit

/// Parses Markdown with Apple's swift-markdown and draws it with the rows and views it shares with `PlainMarkdownRenderer`.
/// The package's tree stays inside `parse`; only the app's own finished `MarkdownRow` values leave it.
struct SwiftMarkdownRenderer: MarkdownRendering {
    typealias Document = [MarkdownRow]

    /// Longest text that goes through the parser, in UTF-8 bytes. Longer text shows whole as plain text.
    nonisolated static let maximumInputBytes = 200_000

    nonisolated func parse(_ markdown: String) async -> [MarkdownRow] {
        let markdown = MarkdownBlocks.normalizingLineBreaks(markdown)
        if markdown.utf8.count > Self.maximumInputBytes {
            let builder = MarkdownRowBuilder()
            builder.addUnformatted(markdown)
            return builder.rows
        }
        if MarkdownNestingBound.exceedsLimits(markdown) { return PlainMarkdownRenderer().parse(markdown) }
        return await Self.parseOnLargeStack(markdown)
    }

    /// swift-markdown converts its tree recursively, one stack frame set per nesting level. Pool threads have a
    /// small stack, so parsing runs on a thread with room for the deepest input `MarkdownNestingBound` lets
    /// through. The thread resumes the continuation, so no pool thread waits for it.
    private nonisolated static func parseOnLargeStack(_ markdown: String) async -> [MarkdownRow] {
        await withCheckedContinuation { continuation in
            let thread = Thread {
                let document = Markdown.Document(parsing: markdown, options: [.disableSmartOpts])
                let budget = Budget(markdown: markdown)
                walk(document, depth: 0, MarkdownRow.Decoration(), budget)
                if let line = budget.cutLine { budget.builder.addUnformatted(text(from: line, in: markdown)) }
                continuation.resume(returning: budget.builder.rows)
            }
            thread.stackSize = 64 * 1024 * 1024
            thread.qualityOfService = .userInitiated
            thread.start()
        }
    }

    @MainActor func view(_ document: [MarkdownRow]) -> AnyView {
        AnyView(MarkdownRowsView(rows: document))
    }

    /// Nesting beyond this depth shows as plain text, so hostile input cannot exhaust the stack.
    private nonisolated static let maximumDepth = 24

    /// What the walk has spent of `MarkdownBlocks.maximumWeight`, the document budget. Once it is spent, the walk
    /// stops at the first element it refuses and remembers where that element starts, so the source from there on
    /// stays as text.
    private final class Budget {
        var remaining = MarkdownBlocks.maximumWeight
        var cutLine: Int?
        let builder = MarkdownRowBuilder()
        let markdown: String

        init(markdown: String) { self.markdown = markdown }

        /// Spends `weight` and returns true, or refuses `markup` when nothing is left.
        func take(_ weight: Int, for markup: any Markup) -> Bool {
            guard remaining > 0, cutLine == nil else {
                if cutLine == nil { cutLine = markup.range?.lowerBound.line ?? 1 }
                return false
            }
            remaining -= weight
            return true
        }
    }

    /// The source from the start of the 1-based `line` to the end of the 1-based `lastLine`, or to the end of the
    /// text without one. Lines end at LF, CR or CRLF, as in the parser.
    private nonisolated static func text(from line: Int, through lastLine: Int? = nil, in markdown: String) -> String {
        let bytes = Array(markdown.utf8)
        var seen = 1
        var start = bytes.count
        var end = bytes.count
        var previousWasCarriageReturn = false
        for (offset, byte) in bytes.enumerated() {
            let continuesCarriageReturn = byte == 0x0A && previousWasCarriageReturn
            if !continuesCarriageReturn {
                if seen == line, start == bytes.count { start = offset }
                if let lastLine, seen > lastLine {
                    end = offset
                    break
                }
                if byte == 0x0D || byte == 0x0A { seen += 1 }
            }
            previousWasCarriageReturn = byte == 0x0D
        }
        return String(decoding: bytes[min(start, end)..<end], as: UTF8.self)
    }

    /// Rows for the children of `container`; stops when the budget is spent.
    private nonisolated static func walk(
        _ container: any Markup, depth: Int, _ decoration: MarkdownRow.Decoration, _ budget: Budget
    ) {
        for child in container.children {
            guard budget.take(1, for: child) else { break }
            add(child, depth: depth, decoration, budget)
        }
    }

    private nonisolated static func add(
        _ markup: any Markup, depth: Int, _ decoration: MarkdownRow.Decoration, _ budget: Budget
    ) {
        if depth > maximumDepth { return addPlain(of: markup, decoration, budget) }
        let builder = budget.builder
        switch markup {
        case let paragraph as Paragraph:
            builder.addText(inline(paragraph.children), decoration)
        case let heading as Heading:
            var styled = decoration
            styled.headingLevel = min(max(heading.level, 1), 6)
            builder.addText(inline(heading.children), styled)
        case let list as UnorderedList:
            addList(list, ordered: false, start: 1, depth: depth, decoration, budget)
        case let list as OrderedList:
            addList(list, ordered: true, start: Int(clamping: list.startIndex), depth: depth, decoration, budget)
        case let quote as BlockQuote:
            var quoted = decoration
            quoted.quoteDepth += 1
            walk(quote, depth: depth + 1, quoted, budget)
        case let code as CodeBlock:
            builder.addCode(code.code.hasSuffix("\n") ? String(code.code.dropLast()) : code.code, decoration)
        case let table as Markdown.Table:
            addTable(table, decoration, budget)
        case is ThematicBreak:
            builder.addRule(decoration)
        default:
            addPlain(of: markup, decoration, budget)
        }
    }

    private nonisolated static func addTable(
        _ table: Markdown.Table, _ decoration: MarkdownRow.Decoration, _ budget: Budget
    ) {
        let columns = table.head.childCount
        let rowCount = table.body.childCount
        if columns > MarkdownBlocks.maximumTableColumns || columns * (rowCount + 1) > MarkdownBlocks.maximumTableCells
            || hasLongRow(table)
        {
            return addSource(of: table, decoration, budget)
        }
        let header = Array(table.head.cells.map { inline($0.children) })
        var rows: [[AttributedString]] = []
        for row in table.body.rows {
            guard budget.take(columns, for: row) else { break }
            rows.append(row.cells.map { inline($0.children) })
        }
        budget.remaining -= columns
        if !budget.builder.addTable(header: header, rows: rows, decoration) {
            addSource(of: table, decoration, budget)
        }
    }

    private nonisolated static func hasLongRow(_ table: Markdown.Table) -> Bool {
        let rows: [any Markup] = [table.head] + Array(table.body.rows)
        return rows.contains { plainText(of: $0).utf8.count > MarkdownBlocks.maximumTableLineBytes }
    }

    /// The text of `markup`, as rows.
    private nonisolated static func addPlain(
        of markup: any Markup, _ decoration: MarkdownRow.Decoration, _ budget: Budget
    ) {
        budget.builder.addPlain(plainText(of: markup), decoration)
    }

    /// The source lines of `markup`, written as they were, as rows.
    private nonisolated static func addSource(
        of markup: any Markup, _ decoration: MarkdownRow.Decoration, _ budget: Budget
    ) {
        guard let range = markup.range else { return addPlain(of: markup, decoration, budget) }
        budget.builder.addPlain(
            text(from: range.lowerBound.line, through: range.upperBound.line, in: budget.markdown), decoration)
    }

    /// The text of a subtree gathered with an explicit stack, so the depth of the subtree costs no call stack.
    private nonisolated static func plainText(of root: any Markup) -> String {
        var text = ""
        var pending: [any Markup] = [root]
        while let markup = pending.popLast() {
            switch markup {
            case let leaf as Markdown.Text: text += leaf.string
            case let leaf as InlineCode: text += leaf.code
            case let leaf as CodeBlock: text += "\n" + leaf.code
            case let leaf as InlineHTML: text += leaf.rawHTML
            case let leaf as HTMLBlock: text += "\n" + leaf.rawHTML
            case is SoftBreak, is LineBreak: text += "\n"
            default:
                if markup is any BlockMarkup, !text.isEmpty, !text.hasSuffix("\n") { text += "\n" }
                pending.append(contentsOf: markup.children.reversed())
            }
        }
        return text
    }

    private nonisolated static func addList(
        _ list: any ListItemContainer, ordered: Bool, start: Int, depth: Int, _ decoration: MarkdownRow.Decoration,
        _ budget: Budget
    ) {
        var listed = decoration
        listed.markerColumns = ordered ? "\(start + max(list.childCount - 1, 0)).".count : 1
        var number = start
        var iterator = list.listItems.makeIterator()
        var next = iterator.next()
        while let listItem = next {
            guard budget.take(1, for: listItem) else { break }
            next = iterator.next()
            var children = Array(listItem.children)
            var text = AttributedString()
            if let first = children.first as? Paragraph {
                text = inline(first.children)
                children.removeFirst()
            }
            var row = listed
            row.marker =
                switch listItem.checkbox {
                case .checked: .task(isChecked: true)
                case .unchecked: .task(isChecked: false)
                case nil: ordered ? .number(number) : .bullet
                }
            number += 1
            let spacingAfterItem = next == nil ? decoration.spacing : MarkdownRow.Spacing.item
            row.spacing = children.isEmpty ? spacingAfterItem : .piece
            budget.builder.addText(text, row)
            var inner = decoration
            inner.indent += 1
            for (index, child) in children.enumerated() {
                guard budget.take(1, for: child) else { break }
                inner.spacing = index + 1 == children.count ? spacingAfterItem : .item
                add(child, depth: depth + 1, inner, budget)
            }
        }
    }

    private nonisolated static func hasMoreInlineNodes(than limit: Int, in roots: [any Markup]) -> Bool {
        var seen = 0
        var pending = roots
        while let markup = pending.popLast() {
            seen += 1
            if seen > limit { return true }
            pending.append(contentsOf: markup.children)
        }
        return false
    }

    private nonisolated static func inline(_ children: some Sequence<any Markup>) -> AttributedString {
        let children = Array(children)
        if hasMoreInlineNodes(than: InlineLimits.maximumInlineNodes, in: children) {
            // So many styled pieces would be dropped by the weight limit anyway; skip building them.
            return AttributedString(children.map { plainText(of: $0) }.joined())
        }
        var result = AttributedString()
        for child in children { result += inline(child, intent: [], link: nil, depth: 0) }
        return result
    }

    private nonisolated static func inline(
        _ markup: any Markup, intent: InlinePresentationIntent, link: URL?, depth: Int
    ) -> AttributedString {
        if depth > maximumDepth {
            var piece = AttributedString(plainText(of: markup))
            if !intent.isEmpty { piece.inlinePresentationIntent = intent }
            if let link { piece.link = link }
            return piece
        }
        func run(_ text: String, _ intent: InlinePresentationIntent) -> AttributedString {
            var piece = AttributedString(text)
            if !intent.isEmpty { piece.inlinePresentationIntent = intent }
            if let link { piece.link = link }
            return piece
        }
        func nested(_ extra: InlinePresentationIntent, link: URL? = link) -> AttributedString {
            var result = AttributedString()
            for child in markup.children {
                result += inline(child, intent: intent.union(extra), link: link, depth: depth + 1)
            }
            return result
        }
        switch markup {
        case let text as Markdown.Text: return run(text.string, intent)
        case is SoftBreak, is LineBreak: return run("\n", intent)
        case let code as InlineCode: return run(code.code, intent.union(.code))
        case is Strong: return nested(.stronglyEmphasized)
        case is Emphasis: return nested(.emphasized)
        case is Strikethrough: return nested(.strikethrough)
        case let linked as Markdown.Link: return nested([], link: linked.destination.flatMap(URL.init(string:)) ?? link)
        case let image as Markdown.Image:
            let alt = plainText(of: image)
            let destination = image.source ?? ""
            return run(alt.isEmpty ? destination : "\(alt) (\(destination))", intent)
        default: return run(plainText(of: markup), intent)
        }
    }
}
