/// What a token is, for coloring.
public enum SyntaxKind: Equatable, Sendable { case keyword, string, comment, number }

/// A colored span of a file's text.
public struct SyntaxToken: Equatable, Sendable {
    /// UTF-16 offsets into the text, for `NSAttributedString`.
    public let range: Range<Int>
    public let kind: SyntaxKind

    public init(range: Range<Int>, kind: SyntaxKind) {
        self.range = range
        self.kind = kind
    }
}

/// A small tokenizer per language family. It colors; it does not parse.
public enum SyntaxTokens {
    /// Tokens for the first `limit` UTF-16 units; the rest stays plain. Never throws, never loops forever.
    public static func tokenize(_ text: String, language: FileLanguage, limit: Int = 200_000) -> [SyntaxToken] {
        guard let rules = rules(for: language.rawValue) else { return [] }
        var units = Array(text.utf16.prefix(max(limit, 0)))
        // A sentinel-free scan: every branch below advances `index` by at least one unit.
        var tokens: [SyntaxToken] = []
        var index = 0
        let count = units.count
        defer { units.removeAll() }

        func matches(_ marker: String, at position: Int) -> Bool {
            let marker = Array(marker.utf16)
            guard position + marker.count <= count else { return false }
            return units[position..<position + marker.count].elementsEqual(marker)
        }

        func lineEnd(from position: Int) -> Int {
            var end = position
            while end < count, units[end] != 10 { end += 1 }
            return end
        }

        while index < count {
            let unit = units[index]
            if let marker = rules.lineComment.first(where: { matches($0, at: index) }) {
                _ = marker
                let end = lineEnd(from: index)
                tokens.append(SyntaxToken(range: index..<end, kind: .comment))
                index = end
            } else if let block = rules.blockComment, matches(block.open, at: index) {
                var end = index + block.open.utf16.count
                while end < count, !matches(block.close, at: end) { end += 1 }
                end = end < count ? end + block.close.utf16.count : count
                tokens.append(SyntaxToken(range: index..<end, kind: .comment))
                index = end
            } else if rules.quotes.contains(unit) {
                var end = index + 1
                while end < count, units[end] != unit, units[end] != 10 || rules.stringsSpanLines {
                    end += units[end] == 92 && end + 1 < count && units[end + 1] != 10 ? 2 : 1
                }
                if end < count, units[end] == unit { end += 1 }
                tokens.append(SyntaxToken(range: index..<min(end, count), kind: .string))
                index = min(end, count)
            } else if isDigit(unit), index == 0 || !isWordUnit(units[index - 1]) {
                var end = index + 1
                while end < count,
                    isDigit(units[end]) || isWordUnit(units[end]) && rules.numbersAllowLetters
                        || units[end] == 46 && end + 1 < count && isDigit(units[end + 1])
                {
                    end += 1
                }
                tokens.append(SyntaxToken(range: index..<end, kind: .number))
                index = end
            } else if isWordStart(unit) {
                var end = index + 1
                while end < count, isWordUnit(units[end]) { end += 1 }
                if !rules.keywords.isEmpty {
                    let word = String(decoding: units[index..<end], as: UTF16.self)
                    if rules.keywords.contains(rules.ignoresCase ? word.lowercased() : word) {
                        tokens.append(SyntaxToken(range: index..<end, kind: .keyword))
                    }
                }
                index = end
            } else {
                index += 1
            }
        }
        return tokens
    }

    private struct Rules {
        var lineComment: [String] = []
        var blockComment: (open: String, close: String)?
        var quotes: Set<UInt16> = [34, 39]
        var keywords: Set<String> = []
        var ignoresCase = false
        var stringsSpanLines = false
        var numbersAllowLetters = false
    }

    private static func isDigit(_ unit: UInt16) -> Bool { unit >= 48 && unit <= 57 }

    private static func isWordStart(_ unit: UInt16) -> Bool {
        (unit >= 65 && unit <= 90) || (unit >= 97 && unit <= 122) || unit == 95 || unit == 36
    }

    private static func isWordUnit(_ unit: UInt16) -> Bool { isWordStart(unit) || isDigit(unit) }

    private static let cLikeBlock = (open: "/*", close: "*/")

    private static func words(_ list: String) -> Set<String> { Set(list.split(separator: " ").map(String.init)) }

    private static func rules(for language: String) -> Rules? {
        switch language {
        case "javascript", "typescript":
            return Rules(
                lineComment: ["//"], blockComment: cLikeBlock, quotes: [34, 39, 96],
                keywords: words(
                    "const let var function return if else for while do switch case break continue class extends new import export from default async await try catch finally throw typeof instanceof in of null undefined true false this"
                        + (language == "typescript"
                            ? " interface type enum implements readonly as public private protected" : "")),
                stringsSpanLines: false)
        case "php":
            return Rules(
                lineComment: ["//", "#"], blockComment: cLikeBlock,
                keywords: words(
                    "namespace use class interface trait extends implements function public private protected static final abstract return if else elseif foreach for while do switch case break continue new try catch finally throw echo null true false array match fn const"
                ))
        case "go":
            return Rules(
                lineComment: ["//"], blockComment: cLikeBlock, quotes: [34, 39, 96],
                keywords: words(
                    "package import func return if else for range switch case default break continue go defer select chan map struct interface type var const nil true false"
                ))
        case "rust":
            return Rules(
                lineComment: ["//"], blockComment: cLikeBlock,
                keywords: words(
                    "fn let mut pub struct enum impl trait use mod return if else match for while loop break continue const static self Self as in where async await move true false"
                ))
        case "java", "kotlin", "csharp":
            return Rules(
                lineComment: ["//"], blockComment: cLikeBlock,
                keywords: words(
                    "class interface enum extends implements public private protected static final abstract void return if else for while do switch case break continue new try catch finally throw import package namespace using null true false this fun val var override"
                ))
        case "c", "cpp":
            return Rules(
                lineComment: ["//"], blockComment: cLikeBlock,
                keywords: words(
                    "int char float double long short unsigned signed void struct union enum typedef static const extern return if else for while do switch case break continue sizeof class public private protected namespace template new delete nullptr true false"
                ))
        case "swift":
            return Rules(
                lineComment: ["//"], blockComment: cLikeBlock,
                keywords: words(
                    "let var func class struct enum protocol extension import return if else guard for while switch case default break continue in try catch throw throws async await actor public private internal static self nil true false init"
                ))
        case "css", "scss", "less":
            return Rules(
                lineComment: language == "css" ? [] : ["//"], blockComment: cLikeBlock, numbersAllowLetters: true)
        case "python":
            return Rules(
                lineComment: ["#"],
                keywords: words(
                    "def class return if elif else for while in not and or is import from as try except finally raise with lambda pass break continue None True False yield async await"
                ))
        case "ruby":
            return Rules(
                lineComment: ["#"],
                keywords: words(
                    "def class module end return if elsif else unless for while until do begin rescue ensure raise require yield self nil true false and or not"
                ))
        case "shell":
            return Rules(
                lineComment: ["#"],
                keywords: words("if then else elif fi for while do done case esac function in return exit export local")
            )
        case "yaml", "toml", "config":
            return Rules(lineComment: ["#"])
        case "sql":
            return Rules(
                lineComment: ["--"], blockComment: cLikeBlock, quotes: [39],
                keywords: words(
                    "select from where and or not insert into values update set delete create table alter drop index join left right inner outer on group by order having limit offset as distinct union null is in like between case when then else end primary key"
                ), ignoresCase: true)
        case "json":
            return Rules()
        case "html", "xml":
            return Rules(blockComment: (open: "<!--", close: "-->"))
        case "lua":
            return Rules(
                lineComment: ["--"],
                keywords: words(
                    "function end if then else elseif for while do repeat until return local break nil true false and or not in"
                ))
        default:
            return nil
        }
    }
}
