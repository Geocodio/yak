import Testing

@testable import YakKit

private func slices(_ text: String, _ tokens: [SyntaxToken]) -> [(SyntaxKind, String)] {
    let units = Array(text.utf16)
    return tokens.map { ($0.kind, String(decoding: units[$0.range], as: UTF16.self)) }
}

@Suite struct SyntaxTokensTests {
    @Test func phpTokens() throws {
        let file = try decoded(FileContent.self, "scenario-file-text")
        let text = try #require(file.content)
        let found = slices(text, SyntaxTokens.tokenize(text, language: file.language))
        let keywords = found.filter { $0.0 == .keyword }.map(\.1)
        #expect(keywords.contains("class"))
        #expect(keywords.contains("function"))
        #expect(keywords.contains("return"))
        #expect(!keywords.contains("Importer"))

        let sample = "// note\n$a = 'x' . \"y\"; /* b */ $n = 42;"
        let sampleTokens = slices(sample, SyntaxTokens.tokenize(sample, language: FileLanguage(rawValue: "php")))
        #expect(sampleTokens.map(\.1) == ["// note", "'x'", "\"y\"", "/* b */", "42"])
        #expect(sampleTokens.map(\.0) == [.comment, .string, .string, .comment, .number])
        let first = SyntaxTokens.tokenize(sample, language: FileLanguage(rawValue: "php"))[0]
        #expect(first.range == 0..<7)
    }

    @Test func hashCommentsInShell() {
        let text = "echo \"hi\" # greet\nif true; then exit 0; fi"
        let found = slices(text, SyntaxTokens.tokenize(text, language: FileLanguage(rawValue: "shell")))
        #expect(found.contains { $0 == (.string, "\"hi\"") })
        #expect(found.contains { $0 == (.comment, "# greet") })
        #expect(found.contains { $0 == (.keyword, "if") })
        #expect(found.contains { $0 == (.number, "0") })
    }

    @Test func sqlKeywordsIgnoreCase() {
        let text = "select id FROM users -- all\nWhere 'a' = name"
        let found = slices(text, SyntaxTokens.tokenize(text, language: FileLanguage(rawValue: "sql")))
        #expect(found.filter { $0.0 == .keyword }.map(\.1) == ["select", "FROM", "Where"])
        #expect(found.contains { $0 == (.comment, "-- all") })
    }

    @Test func anUnclosedStringStopsAtTheLineEnd() {
        let text = "x = 'open\ny = 1"
        let found = slices(text, SyntaxTokens.tokenize(text, language: FileLanguage(rawValue: "javascript")))
        #expect(found.contains { $0 == (.string, "'open") })
        #expect(found.contains { $0 == (.number, "1") })
    }

    @Test func anUnclosedBlockCommentRunsToTheLimit() {
        let text = "a /* never closed\nstill comment"
        let tokens = SyntaxTokens.tokenize(text, language: FileLanguage(rawValue: "c"))
        #expect(tokens.last?.kind == .comment)
        #expect(tokens.last?.range.upperBound == text.utf16.count)
    }

    @Test func theLimitIsRespected() {
        let text = String(repeating: "let a = 'x' // c\n", count: 20_000)
        #expect(text.utf16.count > 300_000)
        let tokens = SyntaxTokens.tokenize(text, language: FileLanguage(rawValue: "swift"))
        #expect(!tokens.isEmpty)
        #expect(tokens.allSatisfy { $0.range.upperBound <= 200_000 })
    }

    @Test func unknownLanguagesGiveNothing() {
        for name in ["markdown", "diff", "text", "brainfuck"] {
            #expect(SyntaxTokens.tokenize("if 'a' # 1", language: FileLanguage(rawValue: name)).isEmpty)
        }
    }

    @Test func emojiOffsetsAreUTF16() {
        let text = "x = \"é😀\"; y = \"z\""
        let tokens = SyntaxTokens.tokenize(text, language: FileLanguage(rawValue: "javascript"))
        let strings = tokens.filter { $0.kind == .string }
        #expect(strings.map(\.range) == [4..<9, 15..<18])
    }

    @Test func jsonHasStringsAndNumbersOnly() {
        let text = "{\"a\": [1, 2.5, true]}"
        let found = slices(text, SyntaxTokens.tokenize(text, language: FileLanguage(rawValue: "json")))
        #expect(found.map(\.1) == ["\"a\"", "1", "2.5"])
    }

    @Test func htmlComments() {
        let text = "<!-- hi --><a href=\"x\">"
        let found = slices(text, SyntaxTokens.tokenize(text, language: FileLanguage(rawValue: "html")))
        #expect(found.map(\.1) == ["<!-- hi -->", "\"x\""])
    }

    @Test func aLargeFileFinishes() {
        let text = String(repeating: "\"unclosed /* ' #\n", count: 70_000)
        for name in ["javascript", "python", "sql", "html", "json", "lua"] {
            _ = SyntaxTokens.tokenize(text, language: FileLanguage(rawValue: name), limit: .max)
        }
    }
}
