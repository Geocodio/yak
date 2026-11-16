import SwiftUI
import YakKit

/// Draws the blocks `MarkdownBlocks` finds, as rows.
struct PlainMarkdownRenderer: MarkdownRendering {
    typealias Document = [MarkdownRow]

    nonisolated func parse(_ markdown: String) -> [MarkdownRow] {
        let builder = MarkdownRowBuilder()
        var subitemsRemaining = MarkdownBlocks.maximumWeight
        Self.add(MarkdownBlocks.parse(markdown), MarkdownRow.Decoration(), builder, &subitemsRemaining)
        return builder.rows
    }

    @MainActor func view(_ document: [MarkdownRow]) -> AnyView {
        AnyView(MarkdownRowsView(rows: document))
    }

    /// Model-written links open only on the web or in Mail; the app's own scheme and the rest are discarded.
    nonisolated static func opensLink(_ url: URL) -> Bool {
        guard let scheme = url.scheme?.lowercased() else { return false }
        return ["http", "https", "mailto"].contains(scheme)
    }

    /// `subitemsRemaining` is what is left of the document budget for the list lines of items; lines past it stay
    /// in the text of their item.
    private nonisolated static func add(
        _ blocks: [MarkdownBlock], _ decoration: MarkdownRow.Decoration, _ builder: MarkdownRowBuilder,
        _ subitemsRemaining: inout Int
    ) {
        for block in blocks {
            switch block {
            case .paragraph(let text): builder.addText(inline(text), decoration)
            case .heading(let level, let text):
                var heading = decoration
                heading.headingLevel = level
                builder.addText(inline(text), heading)
            case .list(let ordered, let start, let items):
                addList(ordered: ordered, start: start, items: items, decoration, builder, &subitemsRemaining)
            case .quote(let inner):
                var quoted = decoration
                quoted.quoteDepth += 1
                add(inner, quoted, builder, &subitemsRemaining)
            case .code(_, let text): builder.addCode(text, decoration)
            case .table(let header, let rows):
                let cell = { inline($0) }
                if !builder.addTable(header: header.map(cell), rows: rows.map { $0.map(cell) }, decoration) {
                    builder.addPlain(
                        ([header] + rows).map { $0.joined(separator: " | ") }.joined(separator: "\n"), decoration)
                }
            case .rule: builder.addRule(decoration)
            case .unformatted(let text): builder.addUnformatted(text)
            }
        }
    }

    private nonisolated static func addList(
        ordered: Bool, start: Int, items: [String], _ decoration: MarkdownRow.Decoration,
        _ builder: MarkdownRowBuilder, _ subitemsRemaining: inout Int
    ) {
        var listed = decoration
        listed.markerColumns = ordered ? "\(start + max(items.count - 1, 0)).".count : 1
        for (index, item) in items.enumerated() {
            let (head, subitems) = split(item, &subitemsRemaining)
            let spacing = index + 1 < items.count ? MarkdownRow.Spacing.item : decoration.spacing
            var row = listed
            row.marker = ordered ? .number(start + index) : .bullet
            row.spacing = subitems.isEmpty ? spacing : .piece
            builder.addText(inline(head), row)
            var subitemRow = decoration
            subitemRow.indent += 1
            subitemRow.markerColumns = 1
            subitemRow.marker = .bullet
            for (subindex, subitem) in subitems.enumerated() {
                subitemRow.spacing = subindex + 1 < subitems.count ? .piece : spacing
                builder.addText(inline(subitem), subitemRow)
            }
        }
    }

    /// The text of a list item and its lines that are list items themselves.
    private nonisolated static func split(_ text: String, _ subitemsRemaining: inout Int) -> (String, [String]) {
        let allLines = text.split(separator: "\n", omittingEmptySubsequences: false).map(String.init)
        var headEnd = 1
        while headEnd < allLines.count, bulletContent(allLines[headEnd]) == nil { headEnd += 1 }
        var head = Array(allLines[..<headEnd])
        var subitems: [String] = []
        for line in allLines[headEnd...] {
            if subitemsRemaining > 0, let content = bulletContent(line) {
                subitemsRemaining -= 1
                subitems.append(content)
            } else if subitems.isEmpty {
                head.append(line)
            } else {
                subitems[subitems.count - 1] += "\n" + line
            }
        }
        return (head.joined(separator: "\n"), subitems)
    }

    /// The text after a leading list marker, or nil when the line is not a list item.
    private nonisolated static func bulletContent(_ line: String) -> String? {
        // The regex is slow per line; most lines cannot start a marker.
        guard let first = line.first, "-*+".contains(first) || first.isASCII && first.isNumber,
            let match = line.firstMatch(of: /^(?:[-*+]|\d{1,9}[.)])(?: +(.*))?$/)
        else { return nil }
        return match.1.map(String.init) ?? ""
    }

    private nonisolated static func inline(_ markdown: String) -> AttributedString {
        let options = AttributedString.MarkdownParsingOptions(interpretedSyntax: .inlineOnlyPreservingWhitespace)
        return (try? AttributedString(markdown: markdown, options: options)) ?? AttributedString(markdown)
    }
}
