import Foundation

/// One block of chat Markdown. Inline syntax stays in the strings; the view renders it.
public enum MarkdownBlock: Equatable, Sendable {
    /// Inline Markdown, lines joined with "\n".
    case paragraph(String)
    /// `level` is 1...6.
    case heading(level: Int, text: String)
    /// One level; deeper lines stay in their item's text.
    case list(ordered: Bool, start: Int, items: [String])
    case quote([MarkdownBlock])
    case code(language: String?, text: String)
    case table(header: [String], rows: [[String]])
    case rule
    /// Source text past the document's weight budget, kept whole so nothing is lost.
    case unformatted(String)
}

/// Splits Markdown into blocks in one pass over the lines. Text that matches nothing is a
/// paragraph, so nothing is dropped, and a fence that is still streaming runs to the end.
public enum MarkdownBlocks {
    public static func parse(_ markdown: String) -> [MarkdownBlock] {
        parse(lines: lines(of: markdown), depth: 0, state: ScanState())
    }

    /// Like `parse`, and also the number of table rows that were split into cells, which is the work the table
    /// scan does. It stays within a small multiple of the line count however the table rows are laid out.
    static func parseCountingCellSplits(_ markdown: String) -> (blocks: [MarkdownBlock], cellSplits: Int) {
        let state = ScanState()
        let blocks = parse(lines: lines(of: markdown), depth: 0, state: state)
        return (blocks, state.cellSplits)
    }

    private static func lines(of markdown: String) -> [String] {
        normalizingLineBreaks(markdown).split(separator: "\n", omittingEmptySubsequences: false).map(String.init)
    }

    /// `text` with every line break written as LF. Besides LF, CR and CRLF, text drawing breaks lines at vertical
    /// tab, form feed, next line, line separator and paragraph separator, so those count as line ends everywhere
    /// lines are counted.
    public static func normalizingLineBreaks(_ text: String) -> String {
        let scalars = text.unicodeScalars
        guard scalars.contains(where: { isLineBreak($0) && $0 != "\n" }) else { return text }
        var result = String.UnicodeScalarView()
        var previousWasCarriageReturn = false
        for scalar in scalars {
            let isLineFeedAfterCarriageReturn = scalar == "\n" && previousWasCarriageReturn
            previousWasCarriageReturn = scalar == "\r"
            if isLineFeedAfterCarriageReturn { continue }
            result.append(isLineBreak(scalar) ? "\n" : scalar)
        }
        return String(result)
    }

    private static func isLineBreak(_ scalar: Unicode.Scalar) -> Bool {
        switch scalar.value {
        case 0x0A...0x0D, 0x85, 0x2028, 0x2029: true
        default: false
        }
    }

    /// The number of line breaks in `text`, counting every kind text drawing honours (CRLF counts once).
    public static func lineBreakCount(in text: String) -> Int {
        text.reduce(0) { $0 + ($1.isNewline ? 1 : 0) }
    }

    /// Blocks, list items and table cells a document may turn into rows: the document budget. What is left over
    /// is returned as `unformatted` text, so the rows and the main actor pay for a bounded document.
    public static let maximumWeight = 3_000

    /// The weight still free in the document. Quotes share it with the document around them.
    private final class ScanState {
        var remaining = MarkdownBlocks.maximumWeight
        var cellSplits = 0
    }

    /// A table with a line longer than this, in UTF-8 bytes, is not a table; its lines stay paragraphs, which the
    /// views split. A cell that wraps over thousands of characters costs far more than the cell count says.
    public static let maximumTableLineBytes = 10_000

    /// A table with more columns than this is not a table; its lines stay paragraphs.
    public static let maximumTableColumns = 64

    /// A table with more cells than this (header included) is not a table; its lines stay paragraphs. Rows are
    /// padded to the header width, so the cell count is what the parse and the view pay for.
    public static let maximumTableCells = maximumWeight

    /// Quotes nest at most this deep, so hostile input cannot exhaust a small thread stack.
    private static let maximumQuoteDepth = 8

    private static func parse(lines: [String], depth: Int, state: ScanState) -> [MarkdownBlock] {
        var blocks: [MarkdownBlock] = []
        var index = 0
        var counted = 0
        while index < lines.count {
            state.remaining -= blocks.count - counted
            counted = blocks.count
            let line = lines[index]
            if state.remaining <= 0, !isBlank(line) {
                blocks.append(.unformatted(lines[index...].joined(separator: "\n")))
                break
            }
            if isBlank(line) {
                index += 1
            } else if let fence = fenceOpening(line) {
                index += 1
                var body: [String] = []
                while index < lines.count, !closesFence(lines[index], fence) {
                    body.append(lines[index])
                    index += 1
                }
                index += 1
                blocks.append(.code(language: fence.language, text: body.joined(separator: "\n")))
            } else if let heading = heading(line) {
                blocks.append(heading)
                index += 1
            } else if isRule(line) {
                blocks.append(.rule)
                index += 1
            } else if quoteContent(line) != nil {
                var inner: [String] = []
                while index < lines.count, let content = quoteContent(lines[index]) {
                    inner.append(content)
                    index += 1
                }
                if depth >= maximumQuoteDepth {
                    blocks.append(.paragraph(inner.joined(separator: "\n")))
                } else {
                    blocks.append(.quote(parse(lines: inner, depth: depth + 1, state: state)))
                }
            } else if let table = table(lines, at: index, state: state) {
                blocks.append(table.block)
                index = table.next
            } else if let skipped = overCapTableEnd(lines, at: index, state: state) {
                blocks.append(.paragraph(lines[index..<skipped].joined(separator: "\n")))
                index = skipped
            } else if let marker = listMarker(line) {
                let list = list(lines, at: index, first: marker, state: state)
                blocks.append(list.block)
                index = list.next
            } else {
                var paragraph = [line]
                index += 1
                while index < lines.count, !isBlank(lines[index]),
                    !startsBlock(lines, at: index, state: state)
                {
                    paragraph.append(lines[index])
                    index += 1
                }
                blocks.append(.paragraph(paragraph.joined(separator: "\n")))
            }
        }
        return blocks
    }

    // MARK: Line classification

    private static func isBlank(_ line: String) -> Bool {
        line.allSatisfy { $0 == " " || $0 == "\t" }
    }

    /// The line without up to three leading spaces, or nil when it is indented further.
    private static func unindented(_ line: String) -> Substring? {
        let rest = line.drop { $0 == " " }
        return line.count - rest.count <= 3 ? rest : nil
    }

    private struct Fence {
        let character: Character
        let length: Int
        let language: String?
    }

    private static func fenceOpening(_ line: String) -> Fence? {
        guard let rest = unindented(line), let character = rest.first, character == "`" || character == "~" else {
            return nil
        }
        let length = rest.prefix { $0 == character }.count
        guard length >= 3 else { return nil }
        let info = rest.dropFirst(length).trimmingCharacters(in: .whitespaces)
        if character == "`", info.contains("`") { return nil }
        let language = info.split(separator: " ").first.map(String.init)
        return Fence(character: character, length: length, language: language)
    }

    private static func closesFence(_ line: String, _ fence: Fence) -> Bool {
        guard let rest = unindented(line) else { return false }
        let run = rest.prefix { $0 == fence.character }
        return run.count >= fence.length && isBlank(String(rest.dropFirst(run.count)))
    }

    private static func heading(_ line: String) -> MarkdownBlock? {
        guard let rest = unindented(line) else { return nil }
        let level = rest.prefix { $0 == "#" }.count
        guard (1...6).contains(level) else { return nil }
        let after = rest.dropFirst(level)
        guard after.isEmpty || after.first == " " || after.first == "\t" else { return nil }
        var text = after.trimmingCharacters(in: .whitespaces)
        if let hashes = text.range(of: " #+$", options: .regularExpression) {
            text.removeSubrange(hashes)
        } else if text.allSatisfy({ $0 == "#" }) {
            text = ""
        }
        return .heading(level: level, text: text)
    }

    private static func isRule(_ line: String) -> Bool {
        guard let rest = unindented(line) else { return false }
        let marks = rest.filter { $0 != " " && $0 != "\t" }
        guard marks.count >= 3, let first = marks.first, "-*_".contains(first) else { return false }
        return marks.allSatisfy { $0 == first }
    }

    private static func quoteContent(_ line: String) -> String? {
        guard let rest = unindented(line), rest.first == ">" else { return nil }
        let content = rest.dropFirst()
        return String(content.first == " " ? content.dropFirst() : content)
    }

    private struct ListMarker {
        let ordered: Bool
        let number: Int
        let content: String
        /// Columns the marker takes with its indentation and one space; a line of the item's own text is indented
        /// by up to this many.
        let contentColumn: Int
    }

    private static func listMarker(_ line: String) -> ListMarker? {
        guard let rest = unindented(line), let first = rest.first else { return nil }
        if "-*+".contains(first) {
            let content = rest.dropFirst()
            guard content.isEmpty || content.first == " " else { return nil }
            return ListMarker(
                ordered: false, number: 1, content: content.trimmingCharacters(in: .whitespaces),
                contentColumn: line.count - rest.count + 2)
        }
        let digits = rest.prefix { $0.isASCII && $0.isNumber }
        guard (1...9).contains(digits.count), let number = Int(digits) else { return nil }
        let afterDigits = rest.dropFirst(digits.count)
        guard let delimiter = afterDigits.first, delimiter == "." || delimiter == ")" else { return nil }
        let content = afterDigits.dropFirst()
        guard content.isEmpty || content.first == " " else { return nil }
        return ListMarker(
            ordered: true, number: number, content: content.trimmingCharacters(in: .whitespaces),
            contentColumn: line.count - rest.count + digits.count + 2)
    }

    /// Whether the line at `index` ends a paragraph.
    private static func startsBlock(_ lines: [String], at index: Int, state: ScanState) -> Bool {
        let line = lines[index]
        if fenceOpening(line) != nil || heading(line) != nil || isRule(line) || quoteContent(line) != nil {
            return true
        }
        if let marker = listMarker(line), !marker.ordered || marker.number == 1 {
            return !marker.content.isEmpty
        }
        return tableHeader(lines, at: index, state: state) != nil
    }

    // MARK: Lists

    private static func list(
        _ lines: [String], at start: Int, first: ListMarker, state: ScanState
    ) -> (block: MarkdownBlock, next: Int) {
        var items = [[first.content]]
        var contentColumn = first.contentColumn
        var index = start + 1
        while index < lines.count {
            let line = lines[index]
            if isBlank(line) {
                // A blank line keeps the list going only when the next line is another item of the same kind.
                var peek = index
                while peek < lines.count, isBlank(lines[peek]) { peek += 1 }
                guard peek < lines.count, !isRule(lines[peek]), let marker = listMarker(lines[peek]),
                    marker.ordered == first.ordered, state.remaining > items.count
                else { break }
                items.append([marker.content])
                contentColumn = marker.contentColumn
                index = peek + 1
            } else if !isRule(line), let marker = listMarker(line), line.prefix(while: { $0 == " " }).count < 2 {
                guard marker.ordered == first.ordered, state.remaining > items.count else { break }
                items.append([marker.content])
                contentColumn = marker.contentColumn
                index += 1
            } else if line.hasPrefix("  ") || line.hasPrefix("\t") || !startsBlock(lines, at: index, state: state) {
                // The item's own indentation goes; deeper indentation stays, so nested lists keep their levels.
                var text = Substring(line)
                var removed = 0
                while removed < contentColumn, let first = text.first, first == " " || first == "\t" {
                    text = text.dropFirst()
                    removed += 1
                }
                items[items.count - 1].append(String(text.reversed().drop { $0 == " " || $0 == "\t" }.reversed()))
                index += 1
            } else {
                break
            }
        }
        state.remaining -= items.count
        let joined = items.map { $0.joined(separator: "\n") }
        return (.list(ordered: first.ordered, start: first.number, items: joined), index)
    }

    // MARK: Tables

    /// The header cells when the line and the one after it form a header and delimiter row.
    private static func tableHeader(_ lines: [String], at index: Int, state: ScanState) -> [String]? {
        guard index + 1 < lines.count, lines[index].contains("|") else { return nil }
        state.cellSplits += 2
        let header = cells(lines[index])
        let delimiter = cells(lines[index + 1])
        guard delimiter.count == header.count, !header.isEmpty, delimiter.allSatisfy(isDelimiterCell) else {
            return nil
        }
        return header
    }

    /// A table, cut where the document's weight budget runs out. Nil when there is no table or it is over the
    /// cell cap; `overCapTableEnd` then finds the end of its lines.
    private static func table(
        _ lines: [String], at index: Int, state: ScanState
    ) -> (block: MarkdownBlock, next: Int)? {
        guard let header = tableHeader(lines, at: index, state: state), header.count <= maximumTableColumns,
            lines[index].utf8.count <= maximumTableLineBytes
        else { return nil }
        var rows: [[String]] = []
        var next = index + 2
        var weight = header.count
        while next < lines.count, !isBlank(lines[next]), lines[next].contains("|") {
            guard header.count * (rows.count + 2) <= maximumTableCells,
                lines[next].utf8.count <= maximumTableLineBytes
            else { return nil }
            if weight + header.count > state.remaining {
                weight = state.remaining
                break
            }
            state.cellSplits += 1
            var row = cells(lines[next])
            if row.count > header.count { row.removeLast(row.count - header.count) }
            while row.count < header.count { row.append("") }
            rows.append(row)
            weight += header.count
            next += 1
        }
        state.remaining -= weight
        return (.table(header: header, rows: rows), next)
    }

    /// The line after the rows of a table that is over the cell cap, found without reading the cells again.
    private static func overCapTableEnd(_ lines: [String], at index: Int, state: ScanState) -> Int? {
        guard tableHeader(lines, at: index, state: state) != nil else { return nil }
        var next = index + 2
        while next < lines.count, !isBlank(lines[next]), lines[next].contains("|") { next += 1 }
        return next
    }

    private static func isDelimiterCell(_ cell: String) -> Bool {
        let core = cell.drop { $0 == ":" }.reversed().drop { $0 == ":" }
        return !core.isEmpty && core.allSatisfy { $0 == "-" } && cell.count - core.count <= 2
    }

    /// Splits a table row on unescaped pipes, trimming cells and turning `\|` into `|`.
    private static func cells(_ line: String) -> [String] {
        var cells: [String] = []
        var current = ""
        var characters = Array(line.trimmingCharacters(in: .whitespaces))
        if characters.first == "|" { characters.removeFirst() }
        var index = 0
        while index < characters.count {
            let character = characters[index]
            if character == "\\", index + 1 < characters.count, characters[index + 1] == "|" {
                current.append("|")
                index += 2
                continue
            }
            if character == "|" {
                cells.append(current.trimmingCharacters(in: .whitespaces))
                current = ""
            } else {
                current.append(character)
            }
            index += 1
        }
        if !isBlank(current) || cells.isEmpty { cells.append(current.trimmingCharacters(in: .whitespaces)) }
        return cells
    }
}
