import SwiftUI
import YakKit

/// One row of the flat list a document is drawn from. A row never holds other rows: quotes, lists and nested lists
/// are rows with a decoration. The only initializer refuses content over the limits below, so no row can hand
/// SwiftUI a large `Text`; longer content continues in further rows with the same decoration.
///
/// A SwiftUI `Text` costs about 1.3 KB of memory per character in layout, so a text row of 2 000 characters
/// stays near 3 MB however many rows the document has.
struct MarkdownRow: Sendable {
    enum Kind: Sendable {
        case text(AttributedString)
        case code(String)
        /// `header` is empty on every piece but the first of a table; `columnWidths` (in characters) belong to
        /// the whole table, so its pieces line up.
        case table(header: [AttributedString], rows: [[AttributedString]], columnWidths: [Int])
        case rule
        case notice
        /// The document spent a page of its display budget. One page is shown at a time and the reader moves
        /// between pages, so nothing is dropped and the memory follows one page.
        case pageBreak
    }

    enum Marker: Sendable, Equatable {
        case none
        case bullet
        case number(Int)
        case task(isChecked: Bool)
    }

    /// The gap below a row: between pieces of one block, between list items, or after a block.
    enum Spacing: Sendable { case piece, item, block }

    struct Decoration: Sendable, Equatable {
        var indent = 0
        var quoteDepth = 0
        var marker = Marker.none
        /// Width of the marker column in characters; zero outside list items. A row with a column and no marker
        /// continues the item above it.
        var markerColumns = 0
        /// 1...6 for a heading, 0 otherwise.
        var headingLevel = 0
        /// Text shown as it was written, below the notice that the formatting stopped.
        var isUnformatted = false
        var spacing = Spacing.block
    }

    /// Cost units one character takes on screen, relative to an ASCII character: memory follows the area a
    /// glyph draws, and emoji take far more than letters.
    nonisolated static let emojiCost = 168
    nonisolated static let wideCost = 4
    /// Cost units every text, code and table row takes of the page besides its content: a row is a view with its
    /// own layout and selection (about 200 KB in measurements, however short its text).
    nonisolated static let rowCost = 1500
    /// Cost units one page of a document holds. A 400 KB ASCII document is one page.
    nonisolated static let maximumDocumentCost = 400_000

    /// The most a single grapheme cluster may cost. Longer clusters (a chain of emoji joined by zero-width
    /// joiners, a letter under thousands of combining marks) are cut by `withBoundedClusters`, so the cost of a
    /// `Character` bounds the area it draws.
    nonisolated static let maximumClusterCost = 1_000
    nonisolated static let clusterSeparator: Character = "\u{200B}"

    /// Joiners, selectors, skin tone modifiers and tag characters draw nothing of their own.
    private nonisolated static func drawsNothing(_ scalar: Unicode.Scalar) -> Bool {
        switch scalar.value {
        case 0x200D, 0xFE00...0xFE0F, 0x20E3, 0x1F3FB...0x1F3FF, 0xE0020...0xE007F: true
        default: false
        }
    }

    /// A cluster costs the sum of what its scalars draw. A cluster with a variation selector or keycap mark
    /// draws its base as a colour emoji.
    private nonisolated static func cost(of scalar: Unicode.Scalar, isColour: Bool) -> Int {
        if drawsNothing(scalar) { return 0 }
        if isColour || scalar.value >= 0x1F000 || scalar.properties.isEmojiPresentation { return emojiCost }
        return scalar.isASCII ? 1 : wideCost
    }

    private nonisolated static func isColour(_ character: Character) -> Bool {
        character.unicodeScalars.contains { $0.value == 0xFE0F || $0.value == 0x20E3 }
    }

    nonisolated static func cost(of character: Character) -> Int {
        if character.isASCII { return 1 }
        let isColour = isColour(character)
        return max(1, character.unicodeScalars.reduce(0) { $0 + cost(of: $1, isColour: isColour) })
    }

    /// `text` with a zero-width space after every stretch of an oversized cluster that costs the limit, so no
    /// cluster costs more than `limit` (a single scalar may cost more). Ordinary text is returned as it is.
    nonisolated static func withBoundedClusters(_ text: String, limit: Int = maximumClusterCost) -> String {
        guard text.contains(where: { cost(of: $0) > limit }) else { return text }
        var result = String.UnicodeScalarView()
        for character in text {
            if cost(of: character) <= limit {
                result.append(contentsOf: character.unicodeScalars)
                continue
            }
            let isColour = isColour(character)
            var stretchCost = 0
            for scalar in character.unicodeScalars {
                let scalarCost = cost(of: scalar, isColour: isColour)
                if scalarCost > 0, stretchCost > 0, stretchCost + scalarCost > limit {
                    result.append(contentsOf: clusterSeparator.unicodeScalars)
                    stretchCost = 0
                }
                result.append(scalar)
                stretchCost += scalarCost
            }
        }
        return String(result)
    }

    /// Styling of a text with an oversized cluster is dropped; such text is not prose.
    nonisolated static func withBoundedClusters(_ text: AttributedString) -> AttributedString {
        guard text.characters.contains(where: { cost(of: $0) > maximumClusterCost }) else { return text }
        return AttributedString(withBoundedClusters(String(text.characters)))
    }

    nonisolated static func cost(of text: some StringProtocol) -> Int {
        text.reduce(0) { $0 + cost(of: $1) }
    }

    /// Cost units, line breaks and styled-run weight (`InlineLimits.weight`) one text row holds at most.
    static let maximumCharacters = 2_000
    static let maximumLines = 300
    static let maximumWeight = 100
    /// A code row holds at most this many characters and lines, and no line is longer than one piece.
    static let maximumCodeCharacters = 4_000
    static let maximumCodeLines = 200
    static let maximumCodeLineCharacters = 100
    /// A table row holds at most this many cells and characters per cell and in all, and styled-run weight per
    /// cell (the weight of the row as a whole is `maximumWeight`).
    static let maximumCells = 64
    static let maximumCellCharacters = 500
    static let maximumTableCharacters = 4_000
    static let maximumCellWeight = 10
    /// Indentation and quote bars past these levels are not drawn deeper, so text keeps its width.
    static let maximumDrawnLevel = 5

    let kind: Kind
    let decoration: Decoration

    /// Nil when `kind` is over a limit. `MarkdownRowBuilder` cuts content to fit first.
    init?(kind: Kind, decoration: Decoration) {
        guard Self.fits(kind) else { return nil }
        var decoration = decoration
        decoration.indent = min(decoration.indent, Self.maximumDrawnLevel)
        decoration.quoteDepth = min(decoration.quoteDepth, Self.maximumDrawnLevel)
        self.kind = kind
        self.decoration = decoration
    }

    /// Cost units the row takes of the document budget.
    var displayCost: Int {
        switch kind {
        case .rule, .notice, .pageBreak: 1
        case .text(let text): Self.rowCost + Size(text).cost
        case .code(let text): Self.rowCost + Self.cost(of: text)
        case .table(let header, let rows, _):
            Self.rowCost + 8 * (header + rows.flatMap { $0 }).reduce(0) { $0 + Size($1).cost }
        }
    }

    nonisolated static func fits(_ kind: Kind) -> Bool {
        switch kind {
        case .rule, .notice, .pageBreak:
            return true
        case .text(let text):
            let size = Size(text)
            return size.cost <= maximumCharacters && size.lines <= maximumLines
                && size.weight <= maximumWeight
        case .code(let text):
            var characters = 0
            var lines = 1
            var lineCharacters = 0
            var lineGlyphs = 0
            for character in text {
                let cost = cost(of: character)
                characters += cost
                if character.isNewline {
                    lines += 1
                    lineCharacters = 0
                    lineGlyphs = 0
                } else {
                    // A separator belongs to the cluster before it and takes no room of its own.
                    if character != clusterSeparator {
                        lineCharacters += cost
                        lineGlyphs += 1
                    }
                }
                // A single cluster wider than a line still has to go somewhere.
                if lineCharacters > maximumCodeLineCharacters, lineGlyphs > 1 { return false }
            }
            return characters <= maximumCodeCharacters && lines <= maximumCodeLines
        case .table(let header, let rows, let columnWidths):
            guard columnWidths.count <= maximumCells else { return false }
            var cells = 0
            var characters = 0
            var weight = 0
            for cell in header + rows.flatMap({ $0 }) {
                let size = Size(cell)
                cells += 1
                characters += size.cost
                weight += size.weight
                if size.cost > maximumCellCharacters || size.weight > maximumCellWeight { return false }
            }
            return cells <= maximumCells && characters <= maximumTableCharacters && weight <= maximumWeight
        }
    }

    /// What a text costs to lay out.
    struct Size {
        var cost = 0
        var lines = 0
        var weight = 0

        nonisolated init(_ text: AttributedString) {
            for character in text.characters {
                cost += MarkdownRow.cost(of: character)
                if character.isNewline { lines += 1 }
            }
            weight = InlineLimits.weight(of: text)
        }
    }

    /// The most rows a document of `bytes` UTF-8 bytes may become: the document budget (twice, as the plain
    /// path also budgets the lines of list items), the notice, the page breaks (a byte costs at most about 350
    /// units), and the pieces of text, which hold at least half their limit unless a line limit cut them first (a byte costs at most 56 units, so the factor 112).
    nonisolated static func maximumRowCount(forBytes bytes: Int) -> Int {
        2 * MarkdownBlocks.maximumWeight + 2 + 700 * bytes / maximumDocumentCost + 112 * bytes
            / maximumCharacters
            + bytes / maximumLines
            + 112 * bytes / maximumCodeCharacters + bytes / maximumCodeLines
    }
}

/// What one `Text` may carry of styled runs. Every run, and above all every link, costs layout time and memory
/// (about 0.15 ms and 100 KB per link in measurements).
enum InlineLimits {
    /// A run that is a link weighs this much, a plain run one.
    nonisolated static let linkWeight = 5
    /// Weight all styled text of a document shares; text past it keeps its characters and loses its styling.
    nonisolated static let maximumDocumentWeight = 1_000
    /// Inline nodes (text, links, emphasis) one text may have before its styling is skipped without being built.
    nonisolated static let maximumInlineNodes = 2_000

    nonisolated static func weight(of text: AttributedString) -> Int {
        text.runs.reduce(0) { $0 + ($1.link == nil ? 1 : linkWeight) }
    }
}

/// Cuts blocks into rows that fit `MarkdownRow`'s limits. Both renderers feed it, so there is one way to put
/// text on screen.
final class MarkdownRowBuilder {
    private(set) var rows: [MarkdownRow] = []
    private var styleRemaining = InlineLimits.maximumDocumentWeight
    private var costRemaining = MarkdownRow.maximumDocumentCost

    /// Styled text, in as many rows as its size needs. Its styling is dropped once the document has spent its
    /// styling weight.
    func addText(_ text: AttributedString, _ decoration: MarkdownRow.Decoration) {
        let text = MarkdownRow.withBoundedClusters(text)
        let weight = InlineLimits.weight(of: text)
        if weight > styleRemaining {
            add(pieces(of: AttributedString(String(text.characters))), decoration)
        } else {
            styleRemaining -= weight
            add(pieces(of: text), decoration)
        }
    }

    /// Text without styling.
    func addPlain(_ text: String, _ decoration: MarkdownRow.Decoration) {
        if !text.isEmpty { add(pieces(of: AttributedString(MarkdownRow.withBoundedClusters(text))), decoration) }
    }

    func addRule(_ decoration: MarkdownRow.Decoration) {
        append(.rule, decoration)
    }

    /// Clusters in code are cut at the cost of one line, because code shows each scalar of a cluster the same
    /// way it shows a character.
    func addCode(_ text: String, _ decoration: MarkdownRow.Decoration) {
        var lines: [Substring] = []
        for line in MarkdownRow.withBoundedClusters(text, limit: MarkdownRow.maximumCodeLineCharacters)
            .split(separator: "\n", omittingEmptySubsequences: false)
        {
            lines.append(contentsOf: wrapped(line))
        }
        var first = 0
        while first < lines.count {
            var last = first
            var characters = 0
            while last < lines.count, last - first < MarkdownRow.maximumCodeLines,
                last == first || characters + MarkdownRow.cost(of: lines[last]) < MarkdownRow.maximumCodeCharacters
            {
                characters += MarkdownRow.cost(of: lines[last]) + 1
                last += 1
            }
            var piece = decoration
            if last < lines.count { piece.spacing = .piece }
            append(.code(lines[first..<last].joined(separator: "\n")), piece)
            first = last
        }
    }

    /// `line` in pieces of at most one line's cost, in one pass. A piece takes at least one cluster, and keeps the
    /// separators that follow it.
    private func wrapped(_ line: Substring) -> [Substring] {
        var pieces: [Substring] = []
        var start = line.startIndex
        var cost = 0
        var index = line.startIndex
        while index < line.endIndex {
            let character = line[index]
            let isSeparator = character == MarkdownRow.clusterSeparator
            let characterCost = isSeparator ? 0 : MarkdownRow.cost(of: character)
            if cost > 0, cost + characterCost > MarkdownRow.maximumCodeLineCharacters {
                pieces.append(line[start..<index])
                start = index
                cost = 0
            }
            cost += characterCost
            index = line.index(after: index)
        }
        pieces.append(line[start...])
        return pieces
    }

    /// A table in pieces. False, with nothing added, when a cell or a row is too big for a table row; the caller
    /// then shows the table's text instead.
    func addTable(header: [AttributedString], rows body: [[AttributedString]], _ decoration: MarkdownRow.Decoration)
        -> Bool
    {
        let all = ([header] + body).map { row in row.map { MarkdownRow.withBoundedClusters($0) } }
        guard header.count <= MarkdownRow.maximumCells, !header.isEmpty else { return false }
        var widths = Array(repeating: 1, count: header.count)
        for row in all {
            var characters = 0
            for (column, cell) in row.enumerated() {
                let size = MarkdownRow.Size(cell)
                characters += size.cost
                if size.cost > MarkdownRow.maximumCellCharacters { return false }
                if column < widths.count { widths[column] = max(widths[column], longestLine(of: cell)) }
            }
            if row.count > MarkdownRow.maximumCells || characters > MarkdownRow.maximumTableCharacters { return false }
        }
        let bounded = all.map { row in row.map { cellWithinWeight($0) } }
        var pieceHeader = bounded[0]
        if pieceHeader.reduce(0, { $0 + MarkdownRow.Size($1).weight }) > MarkdownRow.maximumWeight {
            pieceHeader = pieceHeader.map { AttributedString(String($0.characters)) }
        }
        var pieceRows: [[AttributedString]] = []
        var cells = pieceHeader.count
        var characters = pieceHeader.reduce(0) { $0 + MarkdownRow.Size($1).cost }
        var weight = pieceHeader.reduce(0) { $0 + MarkdownRow.Size($1).weight }
        func flush(isLast: Bool) {
            var piece = decoration
            if !isLast { piece.spacing = .piece }
            append(.table(header: pieceHeader, rows: pieceRows, columnWidths: widths), piece)
            pieceHeader = []
            pieceRows = []
            cells = 0
            characters = 0
            weight = 0
        }
        for var row in bounded.dropFirst() {
            var size = MarkdownRow.Size(AttributedString())
            for cell in row {
                let cellSize = MarkdownRow.Size(cell)
                size.cost += cellSize.cost
                size.weight += cellSize.weight
            }
            if size.weight > MarkdownRow.maximumWeight {
                row = row.map { AttributedString(String($0.characters)) }
                size.weight = row.count
            }
            if cells + row.count > MarkdownRow.maximumCells
                || characters + size.cost > MarkdownRow.maximumTableCharacters
                || weight + size.weight > MarkdownRow.maximumWeight
            {
                flush(isLast: false)
            }
            pieceRows.append(row)
            cells += row.count
            characters += size.cost
            weight += size.weight
        }
        flush(isLast: true)
        return true
    }

    /// The notice that the formatting stopped, and `text` as written below it.
    func addUnformatted(_ text: String) {
        var notice = MarkdownRow.Decoration()
        notice.spacing = .item
        append(.notice, notice)
        var decoration = MarkdownRow.Decoration()
        decoration.isUnformatted = true
        addPlain(text, decoration)
    }

    // MARK: Cutting

    private func append(_ kind: MarkdownRow.Kind, _ decoration: MarkdownRow.Decoration) {
        guard let row = MarkdownRow(kind: kind, decoration: decoration) else {
            assertionFailure("a row was cut over its limits")
            return
        }
        let cost = row.displayCost * (decoration.headingLevel > 0 ? 2 : 1)
        if cost > costRemaining, costRemaining < MarkdownRow.maximumDocumentCost {
            rows.append(MarkdownRow(kind: .pageBreak, decoration: MarkdownRow.Decoration())!)
            costRemaining = MarkdownRow.maximumDocumentCost
        }
        costRemaining -= cost
        rows.append(row)
    }

    private func add(_ pieces: [AttributedString], _ decoration: MarkdownRow.Decoration) {
        for (index, piece) in pieces.enumerated() {
            var decoration = decoration
            if index > 0 { decoration.marker = .none }
            if index + 1 < pieces.count { decoration.spacing = .piece }
            append(.text(piece), decoration)
        }
    }

    private func cellWithinWeight(_ cell: AttributedString) -> AttributedString {
        InlineLimits.weight(of: cell) > MarkdownRow.maximumCellWeight ? AttributedString(String(cell.characters)) : cell
    }

    private func longestLine(of text: AttributedString) -> Int {
        var longest = 0
        var current = 0
        for character in text.characters {
            if character.isNewline {
                longest = max(longest, current)
                current = 0
            } else {
                current += 1
            }
        }
        return max(longest, current)
    }

    /// `text` in pieces within the text limits. Weight cuts fall between runs; the others fall after a line
    /// break, or after whitespace in the second half of a piece, or inside a word when there is none.
    func pieces(of text: AttributedString) -> [AttributedString] {
        var segments: [Range<AttributedString.Index>] = []
        var start = text.startIndex
        var weight = 0
        for run in text.runs {
            let runWeight = run.link == nil ? 1 : InlineLimits.linkWeight
            if weight > 0, weight + runWeight > MarkdownRow.maximumWeight {
                segments.append(start..<run.range.lowerBound)
                start = run.range.lowerBound
                weight = 0
            }
            weight += runWeight
        }
        segments.append(start..<text.endIndex)
        var result: [AttributedString] = []
        let characters = text.characters
        for segment in segments {
            var first = segment.lowerBound
            repeat {
                var index = first
                var count = 0
                var lines = 0
                while index < segment.upperBound, lines < MarkdownRow.maximumLines {
                    let cost = MarkdownRow.cost(of: characters[index])
                    if count > 0, count + cost > MarkdownRow.maximumCharacters { break }
                    if characters[index].isNewline { lines += 1 }
                    index = characters.index(after: index)
                    count += cost
                }
                var cut = index
                if cut < segment.upperBound, lines < MarkdownRow.maximumLines {
                    var back = cut
                    var steps = 0
                    while back > first, steps < MarkdownRow.maximumCharacters / 2 {
                        let previous = characters.index(before: back)
                        if characters[previous].isWhitespace {
                            cut = back
                            break
                        }
                        back = previous
                        steps += MarkdownRow.cost(of: characters[previous])
                    }
                }
                result.append(AttributedString(text[first..<cut]))
                first = cut
            } while first < segment.upperBound
        }
        return result
    }
}
