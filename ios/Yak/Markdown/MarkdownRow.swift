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

    /// Cost is memory in units of about 0.9 KB, which is what one character of a 4 000-character text row takes
    /// in the layout of a lazy stack. Two costs are all the model has for characters: a scalar with the emoji
    /// property costs `emojiCost` whatever its default presentation (a colour emoji draws into a bitmap of its
    /// own area, about six times a letter; ASCII digits, `#` and `*` carry the property but draw as text), and
    /// every other scalar costs 1. The combining keycap (U+20E3) counts as an emoji: it makes its digit draw as
    /// one.
    nonisolated static let emojiCost = 10
    /// Cost units every row takes of its page besides its content: a row is a view with its own layout (about
    /// 0.35 MB in measurements, however short its text).
    nonisolated static let rowCost = 400
    /// Cost units every cell of a table takes besides its text, which costs twice as much as in a paragraph: a
    /// cell wraps in a narrow column, so it draws a taller box.
    nonisolated static let tableCellCost = 50
    /// Cost units every unit of styled-run weight (`InlineLimits.weight`) takes; a link weighs five and costs
    /// about 100 KB.
    nonisolated static let weightCost = 20
    /// Cost units one page of a document holds. The page is the only memory control: a reader sees one page at a
    /// time, and the lazy stack keeps what it built of that page. A page of 380 000 units grows the footprint by
    /// about 300 MB at the most (measured with `scripts/markdown-isolated.sh`, a log of 300 000 units takes 286 MB);
    /// an answer of 300 paragraphs, or
    /// of 150 list items and a 600-line code block, costs about 250 000.
    nonisolated static let maximumPageCost = 380_000
    /// Code is one `Text` whose layer is as big as its widest line times its lines, so it costs by that area
    /// (one unit a cell, `emojiAreaFactor` units on a line with an emoji) and every emoji scalar adds 60. A 600-line, 100-column log with an emoji on
    /// every line costs about 300 000, one page holds one such log, and a code row never costs more than a page.
    nonisolated static let maximumCodeRowCost = maximumPageCost
    nonisolated static let emojiAreaFactor = 5

    /// A grapheme cluster of more scalars than this (a chain of emoji joined by zero-width joiners, a letter
    /// under thousands of combining marks) is cut by `withBoundedClusters`. Real emoji sequences have fewer
    /// than twenty scalars.
    nonisolated static let maximumClusterScalars = 2_000
    nonisolated static let clusterSeparator: Character = "\u{200B}"

    nonisolated static func cost(of scalar: Unicode.Scalar) -> Int {
        (scalar.properties.isEmoji && !scalar.isASCII) || scalar.value == 0x20E3 ? emojiCost : 1
    }

    nonisolated static func cost(of character: Character) -> Int {
        if character.isASCII { return 1 }
        return character.unicodeScalars.reduce(0) { $0 + cost(of: $1) }
    }

    nonisolated static func cost(of text: some StringProtocol) -> Int {
        text.reduce(0) { $0 + cost(of: $1) }
    }

    /// `text` with a zero-width space after every `limit` scalars of a cluster that has more. Any other text is
    /// returned as it is.
    nonisolated static func withBoundedClusters(_ text: String, limit: Int = maximumClusterScalars) -> String {
        guard text.contains(where: { $0.unicodeScalars.count > limit }) else { return text }
        var result = String.UnicodeScalarView()
        for character in text {
            var count = 0
            for scalar in character.unicodeScalars {
                if count == limit {
                    result.append(contentsOf: clusterSeparator.unicodeScalars)
                    count = 0
                }
                result.append(scalar)
                count += 1
            }
        }
        return String(result)
    }

    /// Styling of a text with an oversized cluster is dropped; such text is not prose.
    nonisolated static func withBoundedClusters(_ text: AttributedString) -> AttributedString {
        guard text.characters.contains(where: { $0.unicodeScalars.count > maximumClusterScalars }) else { return text }
        return AttributedString(withBoundedClusters(String(text.characters)))
    }

    /// Cost units, line breaks and styled-run weight (`InlineLimits.weight`) one text row holds at most. A row
    /// may run past its cost by one cluster, which cannot be cut. A paragraph shows no break where two rows meet
    /// unless it is longer than this.
    static let maximumCharacters = 4_000
    static let maximumLines = 300
    static let maximumWeight = 400
    /// A code row holds at most `maximumCodeRowCost` and this many lines, and no line is longer than
    /// `maximumCodeLineScalars` plus one cluster.
    static let maximumCodeLines = 2_000
    static let maximumCodeLineScalars = 2_000
    /// A table row holds at most this many cells and characters per cell and in all, and styled-run weight per
    /// cell and in all (every cell has at least one run).
    static let maximumCells = 500
    static let maximumCellCharacters = 3_000
    static let maximumTableCharacters = 8_000
    static let maximumCellWeight = 40
    static let maximumTableWeight = 1_200
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
        case .rule, .notice, .pageBreak:
            return 1
        case .text(let text):
            let size = Size(text)
            return Self.rowCost + size.cost + Self.weightCost * size.weight
        case .code(let text):
            return Self.rowCost + CodeSize(text).cost
        case .table(let header, let rows, _):
            return Self.rowCost
                + (header + rows.flatMap { $0 }).reduce(0) {
                    let size = Size($1)
                    return $0 + Self.tableCellCost + 2 * size.cost + Self.weightCost * size.weight
                }
        }
    }

    nonisolated static func fits(_ kind: Kind) -> Bool {
        switch kind {
        case .rule, .notice, .pageBreak:
            return true
        case .text(let text):
            let size = Size(text)
            return (size.cost <= maximumCharacters || size.clusters == 1) && size.lines <= maximumLines
                && size.weight <= maximumWeight
        case .code(let text):
            var lineScalars = 0
            for character in text {
                if character.isNewline {
                    lineScalars = 0
                } else {
                    // A line may run past its limit by one cluster.
                    if lineScalars > maximumCodeLineScalars { return false }
                    lineScalars += character.unicodeScalars.count
                }
            }
            let size = CodeSize(text)
            return size.lines <= maximumCodeLines && size.cost <= maximumCodeRowCost
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
                if size.cost > maximumCellCharacters && size.clusters > 1 || size.weight > maximumCellWeight {
                    return false
                }
            }
            return cells <= maximumCells && characters <= maximumTableCharacters && weight <= maximumTableWeight
        }
    }

    /// What a piece of code costs to lay out: its lines, its widest line in cells (an emoji or East Asian wide
    /// scalar takes two) and the emoji scalars.
    struct CodeSize {
        var lines = 1
        var widest = 0
        var emojiScalars = 0
        var emojiLines = 0

        nonisolated init(_ text: some StringProtocol) {
            var width = 0
            var lineHasEmoji = false
            func endLine() {
                widest = max(widest, width)
                width = 0
                if lineHasEmoji { emojiLines += 1 }
                lineHasEmoji = false
            }
            for character in text {
                if character.isNewline {
                    endLine()
                    lines += 1
                } else if character.isASCII {
                    width += 1
                } else {
                    // A chain of emoji joined by zero-width joiners draws every emoji, so scalars count.
                    for scalar in character.unicodeScalars {
                        if MarkdownRow.cost(of: scalar) >= MarkdownRow.emojiCost {
                            width += 2
                            emojiScalars += 1
                            lineHasEmoji = true
                        } else {
                            width += Self.isEastAsianWide(scalar) ? 2 : 1
                        }
                    }
                }
            }
            endLine()
        }

        /// Scalars with East Asian width Wide or Fullwidth (Hangul jamo, CJK, kana, Hangul syllables, fullwidth
        /// forms, the supplementary ideographs), which draw two cells.
        nonisolated static func isEastAsianWide(_ scalar: Unicode.Scalar) -> Bool {
            switch scalar.value {
            case 0x1100...0x115F, 0x2E80...0x303E, 0x3041...0x33FF, 0x3400...0x4DBF, 0x4E00...0x9FFF,
                0xA000...0xA4CF, 0xA960...0xA97F, 0xAC00...0xD7A3, 0xF900...0xFAFF, 0xFE30...0xFE6F,
                0xFF01...0xFF60, 0xFFE0...0xFFE6, 0x1B000...0x1B2FF, 0x20000...0x3FFFD:
                true
            default:
                false
            }
        }

        /// A line with an emoji takes about five times the memory of a plain one (a 600-line, 100-column log with
        /// an emoji on every line takes 286 MB), so it is priced `emojiAreaFactor` times: the widest line's width
        /// for each such line is added `emojiAreaFactor - 1` times. Every emoji scalar adds 60 (layout time follows
        /// the number of emoji).
        var cost: Int { widest * (lines + (MarkdownRow.emojiAreaFactor - 1) * emojiLines) + 60 * emojiScalars }
    }

    /// What a text costs to lay out.
    struct Size {
        var cost = 0
        var clusters = 0
        var lines = 0
        var weight = 0

        nonisolated init(_ text: AttributedString) {
            for character in text.characters {
                cost += MarkdownRow.cost(of: character)
                clusters += 1
                if character.isNewline { lines += 1 }
            }
            weight = InlineLimits.weight(of: text)
        }
    }

    /// The most rows a document of `bytes` UTF-8 bytes may become: the document budget (twice, as the plain
    /// path also budgets the lines of list items), the notice, the pieces of text (cut by cost, by line and by
    /// styled-run weight), the pieces of code (a piece takes at least 400 bytes unless it is the last), and the
    /// page breaks. A byte costs at most 4 units of content, `weightCost` of styling and `tableCellCost / 2` of
    /// table cell, every row at most `rowCost`, and a piece of code at most `maximumCodeRowCost`; a page holds at
    /// least half of `maximumPageCost`.
    nonisolated static func maximumRowCount(forBytes bytes: Int) -> Int {
        let codeRows = bytes / 400 + 1
        let rows =
            2 * MarkdownBlocks.maximumWeight + 2 + 4 * bytes / maximumCharacters * 2 + bytes / maximumLines
            + 2 * bytes / maximumWeight + codeRows
        return rows + 2
            * (rows * rowCost + (4 + weightCost + tableCellCost / 2) * bytes + codeRows * maximumCodeRowCost)
            / maximumPageCost + 1
    }
}

/// What one `Text` may carry of styled runs. Every run, and above all every link, costs layout time and memory
/// (about 0.15 ms and 100 KB per link in measurements).
enum InlineLimits {
    /// A run that is a link weighs this much, a plain run one.
    nonisolated static let linkWeight = 5
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
    private var costRemaining = MarkdownRow.maximumPageCost

    /// Styled text, in as many rows as its size needs.
    func addText(_ text: AttributedString, _ decoration: MarkdownRow.Decoration) {
        add(pieces(of: MarkdownRow.withBoundedClusters(text)), decoration)
    }

    /// Text without styling.
    func addPlain(_ text: String, _ decoration: MarkdownRow.Decoration) {
        if !text.isEmpty { add(pieces(of: AttributedString(MarkdownRow.withBoundedClusters(text))), decoration) }
    }

    func addRule(_ decoration: MarkdownRow.Decoration) {
        append(.rule, decoration)
    }

    /// Code in rows of whole lines. A line is kept whole unless it is longer than `maximumCodeLineScalars`.
    func addCode(_ text: String, _ decoration: MarkdownRow.Decoration) {
        var lines: [Substring] = []
        for line in MarkdownRow.withBoundedClusters(text).split(separator: "\n", omittingEmptySubsequences: false) {
            lines.append(contentsOf: wrapped(line))
        }
        var first = 0
        while first < lines.count {
            var last = first
            var widest = 0
            var emojiScalars = 0
            var emojiLines = 0
            while last < lines.count, last - first < MarkdownRow.maximumCodeLines {
                let line = MarkdownRow.CodeSize(lines[last])
                var size = MarkdownRow.CodeSize("")
                size.lines = last - first + 1
                size.widest = max(widest, line.widest)
                size.emojiScalars = emojiScalars + line.emojiScalars
                size.emojiLines = emojiLines + line.emojiLines
                if last > first, size.cost > MarkdownRow.maximumCodeRowCost { break }
                widest = size.widest
                emojiScalars = size.emojiScalars
                emojiLines = size.emojiLines
                last += 1
            }
            var piece = decoration
            if last < lines.count { piece.spacing = .piece }
            append(.code(lines[first..<last].joined(separator: "\n")), piece)
            first = last
        }
    }

    /// `line` in pieces that end at the first cluster boundary at or past `maximumCodeLineScalars` scalars, in one
    /// pass, so a cluster is never cut and a line runs past its limit by one cluster.
    private func wrapped(_ line: Substring) -> [Substring] {
        var pieces: [Substring] = []
        var start = line.startIndex
        var scalars = 0
        var index = line.startIndex
        while index < line.endIndex {
            let clusterScalars = line[index].unicodeScalars.count
            if scalars >= MarkdownRow.maximumCodeLineScalars {
                pieces.append(line[start..<index])
                start = index
                scalars = 0
            }
            scalars += clusterScalars
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
                if size.cost > MarkdownRow.maximumCellCharacters, size.clusters > 1 { return false }
                if column < widths.count { widths[column] = max(widths[column], longestLine(of: cell)) }
            }
            if row.count > MarkdownRow.maximumCells || characters > MarkdownRow.maximumTableCharacters { return false }
        }
        let bounded = all.map { row in row.map { cellWithinWeight($0) } }
        var pieceHeader = bounded[0]
        if pieceHeader.reduce(0, { $0 + MarkdownRow.Size($1).weight }) > MarkdownRow.maximumTableWeight {
            pieceHeader = pieceHeader.map { AttributedString(String($0.characters)) }
        }
        var pieceRows: [[AttributedString]] = []
        // A wide table lays out slower per cell, so its pieces are half as big.
        let cellLimit = header.count <= 16 ? MarkdownRow.maximumCells : MarkdownRow.maximumCells / 2
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
            if size.weight > MarkdownRow.maximumTableWeight {
                row = row.map { AttributedString(String($0.characters)) }
                size.weight = row.count
            }
            if cells + row.count > cellLimit
                || characters + size.cost > MarkdownRow.maximumTableCharacters
                || weight + size.weight > MarkdownRow.maximumTableWeight
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
        // A heading is set in a larger font, so it draws about twice the area.
        let cost = row.displayCost * (decoration.headingLevel > 0 ? 2 : 1)
        if cost > costRemaining, costRemaining < MarkdownRow.maximumPageCost {
            rows.append(MarkdownRow(kind: .pageBreak, decoration: MarkdownRow.Decoration())!)
            costRemaining = MarkdownRow.maximumPageCost
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
