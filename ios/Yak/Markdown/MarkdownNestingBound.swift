import Foundation

/// An upper bound on how deep Markdown can nest, computed without imitating the parser. A text whose bound
/// exceeds a limit is not shown through swift-markdown, whose conversion recurses once per level.
///
/// Container depth: every container (quote, list item) is opened by at least one character of the leading run
/// of whitespace and marker characters of a line, so the run length bounds the depth. Tabs count as four.
/// Inline depth: every inline span is opened by at least one of `*`, `_`, `~`, `[`, `<` or a backtick, and no
/// span crosses a blank line, so the delimiters since the last blank line bound the depth.
///
/// The bound may be far too high, never too low. Every line break the parser knows ends a line for the leading
/// run. Blank lines reset the delimiter count only when they are blank for the parser (spaces and tabs only,
/// ended by `\n`, `\r` or `\r\n`); the other Unicode line breaks split the run count but never the paragraph.
///
/// Measured on a 64 MB thread (macOS, Debug build, swift-markdown 0.9.0, `Document(parsing:)` plus a full
/// recursive walk): see `MarkdownHardeningTests.measuredOverflowDepths` for the recorded depths. The limits are
/// far below the smallest one.
enum MarkdownNestingBound {
    /// Leading run length (markers, digits, indentation) of one line.
    nonisolated static let maximumLineNesting = 400
    /// Inline delimiters in one paragraph.
    nonisolated static let maximumDelimitersPerParagraph = 4000
    /// Pipes on one line, counted from the last LF, CR or CRLF, which are the only places cmark ends a line. A
    /// table row with more cells is not shown as a table anyway, and cmark slows down on rows
    /// with hundreds of thousands of pipes (4.5 s for 199 000 on the simulator).
    nonisolated static let maximumPipesPerLine = 2000

    nonisolated static func exceedsLimits(_ markdown: String) -> Bool {
        var runLength = 0
        var isInRun = true
        var delimiters = 0
        var pipes = 0
        var hasContentOnLine = false
        var previousWasCarriageReturn = false
        for scalar in markdown.unicodeScalars {
            if scalar == "\n" && previousWasCarriageReturn {
                previousWasCarriageReturn = false
                continue
            }
            previousWasCarriageReturn = scalar == "\r"
            if isLineBreak(scalar) {
                runLength = 0
                isInRun = true
                if scalar == "\n" || scalar == "\r" {
                    pipes = 0
                    if !hasContentOnLine { delimiters = 0 }
                    hasContentOnLine = false
                } else {
                    hasContentOnLine = true
                }
                continue
            }
            if scalar != " " && scalar != "\t" { hasContentOnLine = true }
            if isInRun {
                if scalar == "\t" {
                    runLength += 4
                } else if isRunScalar(scalar) {
                    runLength += 1
                } else {
                    isInRun = false
                }
                if runLength > maximumLineNesting { return true }
            }
            if scalar == "|" {
                pipes += 1
                if pipes > maximumPipesPerLine { return true }
            }
            if isDelimiter(scalar) {
                delimiters += 1
                if delimiters > maximumDelimitersPerParagraph { return true }
            }
        }
        return false
    }

    private nonisolated static func isLineBreak(_ scalar: Unicode.Scalar) -> Bool {
        switch scalar.value {
        case 0x0A...0x0D, 0x85, 0x2028, 0x2029: true
        default: false
        }
    }

    private nonisolated static func isRunScalar(_ scalar: Unicode.Scalar) -> Bool {
        switch scalar {
        // U+FEFF: cmark skips a byte order mark at the start of the document, so it can precede the first marker.
        case ">", "-", "*", "+", ".", ")", "0"..."9", "\u{FEFF}": true
        default: scalar.properties.isWhitespace
        }
    }

    private nonisolated static func isDelimiter(_ scalar: Unicode.Scalar) -> Bool {
        switch scalar {
        case "*", "_", "~", "[", "<", "`": true
        default: false
        }
    }
}
