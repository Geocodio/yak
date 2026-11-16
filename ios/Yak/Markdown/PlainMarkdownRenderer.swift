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
            let (head, subitems) = split(item, depth: 0, &subitemsRemaining)
            let spacing = index + 1 < items.count ? MarkdownRow.Spacing.item : decoration.spacing
            var row = listed
            row.marker = ordered ? .number(start + index) : .bullet
            row.spacing = subitems.isEmpty ? spacing : .piece
            builder.addText(inline(head), row)
            addSubitems(subitems, depth: 1, decoration, spacing, builder, &subitemsRemaining)
        }
    }

    /// Sub-items one level in from `decoration`, each followed by its own sub-items. Nesting past
    /// `maximumDrawnLevel` stays in the text of its item, which keeps the recursion shallow.
    private nonisolated static func addSubitems(
        _ subitems: [(marker: MarkdownRow.Marker, text: String)], depth: Int, _ decoration: MarkdownRow.Decoration,
        _ lastSpacing: MarkdownRow.Spacing, _ builder: MarkdownRowBuilder, _ subitemsRemaining: inout Int
    ) {
        var subitemRow = decoration
        subitemRow.indent = decoration.indent + depth
        subitemRow.markerColumns =
            subitems.map { subitem in
                if case .number(let number) = subitem.marker { "\(number).".count } else { 1 }
            }.max() ?? 1
        for (index, subitem) in subitems.enumerated() {
            let (head, nested) = split(subitem.text, depth: depth, &subitemsRemaining)
            var row = subitemRow
            row.marker = subitem.marker
            row.spacing = !nested.isEmpty || index + 1 < subitems.count ? .piece : lastSpacing
            builder.addText(inline(head), row)
            addSubitems(
                nested, depth: depth + 1, decoration, index + 1 < subitems.count ? .piece : lastSpacing, builder,
                &subitemsRemaining)
        }
    }

    /// The text of a list item and its lines that are list items themselves. Below the first level a list line may
    /// be indented, and no line is taken as an item past `maximumDrawnLevel`.
    private nonisolated static func split(
        _ text: String, depth: Int, _ subitemsRemaining: inout Int
    ) -> (String, [(marker: MarkdownRow.Marker, text: String)]) {
        let allLines = text.split(separator: "\n", omittingEmptySubsequences: false).map(String.init)
        let canNest = depth < MarkdownRow.maximumDrawnLevel
        // At depth 0 the first marker sets the column of the sub-items; a marker indented deeper belongs to the
        // sub-item above it.
        var subitemColumn: Int?
        func item(_ line: String) -> (MarkdownRow.Marker, String)? {
            guard canNest else { return nil }
            let stripped = line.drop { $0 == " " }
            let column = line.count - stripped.count
            if depth == 0, let subitemColumn, column > subitemColumn { return nil }
            guard let found = listItem(String(stripped)) else { return nil }
            if depth == 0, subitemColumn == nil { subitemColumn = column }
            return found
        }
        var headEnd = 1
        while headEnd < allLines.count, item(allLines[headEnd]) == nil { headEnd += 1 }
        var head = allLines[..<headEnd].map { String($0.drop { $0 == " " || $0 == "\t" }) }
        var subitems: [(marker: MarkdownRow.Marker, text: String)] = []
        for line in allLines[headEnd...] {
            if subitemsRemaining > 0, let (marker, content) = item(line) {
                subitemsRemaining -= 1
                subitems.append((marker, content))
            } else if subitems.isEmpty {
                head.append(String(line.drop { $0 == " " || $0 == "\t" }))
            } else {
                subitems[subitems.count - 1].text += "\n" + line
            }
        }
        return (head.joined(separator: "\n"), subitems)
    }

    /// The marker and the text after a leading list marker, or nil when the line is not a list item.
    private nonisolated static func listItem(_ line: String) -> (MarkdownRow.Marker, String)? {
        // The regex is slow per line; most lines cannot start a marker.
        guard let first = line.first, "-*+".contains(first) || first.isASCII && first.isNumber,
            let match = line.firstMatch(of: /^([-*+]|\d{1,9}[.)])(?: +(.*))?$/)
        else { return nil }
        let marker = match.1.first?.isNumber == true ? MarkdownRow.Marker.number(Int(match.1.dropLast()) ?? 0) : .bullet
        return (marker, match.2.map(String.init) ?? "")
    }

    private nonisolated static func inline(_ markdown: String) -> AttributedString {
        let options = AttributedString.MarkdownParsingOptions(interpretedSyntax: .inlineOnlyPreservingWhitespace)
        return (try? AttributedString(markdown: markdown, options: options)) ?? AttributedString(markdown)
    }
}
