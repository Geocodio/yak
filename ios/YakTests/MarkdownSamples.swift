import Foundation

/// Realistic long answers, the content the renderer must show whole: no paging, no break inside a block, text that
/// round-trips exactly. Deterministic, so a failure replays.
enum MarkdownSamples {
    private nonisolated static let words = [
        "the", "importer", "reads", "each", "row", "from", "the", "queue", "and", "checks", "its", "limit", "before",
        "it", "writes", "anything", "to", "the", "database", "so", "a", "slow", "batch", "never", "blocks", "the",
        "others", "while", "retries", "back", "off", "with", "jitter",
    ]

    /// `count` words, starting at word `offset` of the cycle.
    nonisolated static func sentence(words count: Int, offset: Int) -> String {
        (0..<count).map { words[($0 + offset) % words.count] }.joined(separator: " ")
    }

    /// 300 paragraphs of about 350 characters, some with emphasis, code and a link.
    nonisolated static let paragraphs: String = (0..<300).map { index in
        let lead = sentence(words: 30, offset: index)
        let tail = sentence(words: 30, offset: index + 7)
        switch index % 3 {
        case 0: return "\(lead.capitalized). See **\(tail)** for details."
        case 1: return "\(lead.capitalized), `Importer::run()` and [the notes](https://example.com/\(index)). \(tail)."
        default: return "\(lead.capitalized). \(tail)."
        }
    }.joined(separator: "\n\n")

    /// The text the paragraphs show, without their markup.
    nonisolated static var paragraphTexts: [String] {
        (0..<300).map { index in
            let lead = sentence(words: 30, offset: index)
            let tail = sentence(words: 30, offset: index + 7)
            switch index % 3 {
            case 0: return "\(lead.capitalized). See \(tail) for details."
            case 1: return "\(lead.capitalized), Importer::run() and the notes. \(tail)."
            default: return "\(lead.capitalized). \(tail)."
            }
        }
    }

    nonisolated static let listItems: [String] = (0..<150).map { sentence(words: 8 + $0 % 9, offset: $0) }

    /// 150 items; every tenth has a link, every seventh emphasis.
    nonisolated static let list: String = listItems.enumerated().map { index, item in
        let text = index % 10 == 0 ? "\(item) ([docs](https://example.com/\(index)))" : item
        return "- " + (index % 7 == 0 ? "**\(text)**" : text)
    }.joined(separator: "\n")

    nonisolated static let tableHeader = (0..<8).map { "Column \($0)" }
    nonisolated static let tableBody: [[String]] = (0..<60).map { row in
        (0..<8).map { column in sentence(words: 1 + (row + column) % 4, offset: row * 3 + column) }
    }

    /// A table of 8 columns and 60 rows.
    nonisolated static let table: String =
        (["| " + tableHeader.joined(separator: " | ") + " |", "|" + String(repeating: "---|", count: 8)]
        + tableBody.map { "| " + $0.joined(separator: " | ") + " |" }).joined(separator: "\n")

    /// 600 lines of code: indentation, one line of 150 characters, and emoji on three lines (a check mark, a flag
    /// and a family).
    nonisolated static let codeLines: [String] = (0..<600).map { index in
        switch index {
        case 100: return "    print(\"done \u{2705}\")"
        case 200:
            return
                "    let flag = \"\u{1F1E9}\u{1F1F0}\", family = \"\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}\""
        case 300:
            return "    let value = try await withThrowingTaskGroup(of: Row.self) { group in group.waitForAll() } // "
                + sentence(words: 8, offset: 3)
        default:
            switch index % 5 {
            case 0: return "func step\(index)(input: Input, limit: Int) async throws -> Output {"
            case 1: return "    let rows = try await source.read(limit: limit) // \(sentence(words: 4, offset: index))"
            case 2: return ""
            case 3: return "        return Output(rows: rows, count: \(index))"
            default: return "}"
            }
        }
    }

    nonisolated static let codeBlock: String = "```swift\n" + codeLines.joined(separator: "\n") + "\n```"

    /// 120 sentences, each with emoji, in paragraphs of six.
    nonisolated static let emojiParagraphs: [String] = (0..<20).map { block in
        (0..<6).map { index in
            let emoji = [
                "\u{2705}", "\u{1F680}", "\u{2764}\u{FE0F}", "\u{1F468}\u{200D}\u{1F4BB}", "\u{1F1E9}\u{1F1F0}",
                "\u{26A0}\u{FE0F}",
            ][
                index]
            return sentence(words: 14, offset: block * 6 + index).capitalized + ". \(emoji)"
        }.joined(separator: " ")
    }

    nonisolated static let emojiText: String = emojiParagraphs.joined(separator: "\n\n")

    /// A long answer that mixes everything: headings, paragraphs, quotes, a list, a table, code and emoji.
    nonisolated static let mixed: String = {
        var blocks: [String] = []
        let all = paragraphs.components(separatedBy: "\n\n")
        for section in 0..<4 {
            blocks.append("## Part \(section + 1)")
            blocks.append(contentsOf: all[(section * 25)..<(section * 25 + 25)])
            blocks.append("> A quoted remark in part \(section + 1), \(sentence(words: 20, offset: section)).")
        }
        blocks.append(list)
        blocks.append(table)
        blocks.append("```swift\n" + codeLines[0..<300].joined(separator: "\n") + "\n```")
        blocks.append(emojiText)
        return blocks.joined(separator: "\n\n")
    }()

    /// The number of blocks of `mixed`, each one row: 4 headings, 100 paragraphs, 4 quotes, 150 list items, one
    /// table, one code block, 20 emoji paragraphs.
    nonisolated static let mixedRowCount = 4 + 100 + 4 + 150 + 1 + 1 + 20
}
