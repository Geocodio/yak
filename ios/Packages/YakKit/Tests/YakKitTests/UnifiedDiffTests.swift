import Foundation
import Testing

@testable import YakKit

private struct PatchFile: Decodable {
    let patch: String
}

@Suite struct UnifiedDiffTests {
    @Test func numbersFollowTheHunks() throws {
        let parsed = UnifiedDiff.parse(try decoded(PatchFile.self, "scenario-diff-file").patch)
        #expect(parsed.hunkCount == 2)
        #expect(parsed.hasContentChanges)
        #expect(
            parsed.rows.first
                == DiffRow(id: 0, kind: .hunk(header: "class RetryUpload"), oldLine: nil, newLine: nil, text: ""))
        let removed = try #require(parsed.rows.first { $0.kind == .removed })
        #expect(removed.oldLine == 43 && removed.newLine == nil)
        let added = try #require(parsed.rows.first { $0.kind == .added })
        #expect(added.newLine == 43 && added.oldLine == nil && added.text == "        $this->retry(3);")
        let contextAfter = try #require(parsed.rows.first { $0.kind == .context && $0.oldLine == 44 })
        #expect(contextAfter.newLine == 45)
        #expect(parsed.rows.map(\.id) == Array(parsed.rows.indices))
    }

    @Test func noNewlineMarkerIsItsOwnRow() {
        let parsed = UnifiedDiff.parse("@@ -1 +1 @@\n-a\n\\ No newline at end of file\n+a\n")
        #expect(parsed.rows.map(\.kind) == [.hunk(header: ""), .removed, .noNewlineAtEnd, .added])
        #expect(parsed.rows[2].oldLine == nil && parsed.rows[2].newLine == nil)
        #expect(parsed.rows[3].newLine == 1)
    }

    @Test func aRenameWithoutHunksHasNoContentChanges() {
        let parsed = UnifiedDiff.parse("diff --git a/a b/b\nsimilarity index 100%\nrename from a\nrename to b\n")
        #expect(parsed.rows.isEmpty && parsed.hunkCount == 0 && !parsed.hasContentChanges)
    }

    @Test func crlfIsStripped() {
        let parsed = UnifiedDiff.parse("@@ -1 +1 @@\r\n-a\r\n+b\r\n")
        #expect(parsed.rows.map(\.text) == ["", "a", "b"])
    }

    @Test func anEmptyLineInsideAHunkIsContext() {
        let parsed = UnifiedDiff.parse("@@ -1,2 +1,2 @@\n\n-a\n")
        #expect(parsed.rows[1] == DiffRow(id: 1, kind: .context, oldLine: 1, newLine: 1, text: ""))
        #expect(parsed.rows[2].oldLine == 2)
    }

    @Test func aBrokenHeaderDoesNotStopTheParse() {
        let parsed = UnifiedDiff.parse("@@ nonsense @@\n+x\n@@ -3 +3 @@\n+y\n")
        #expect(parsed.rows[0] == DiffRow(id: 0, kind: .context, oldLine: nil, newLine: nil, text: "@@ nonsense @@"))
        #expect(parsed.rows[1].kind == .added && parsed.rows[1].newLine == nil && parsed.rows[1].text == "x")
        #expect(parsed.rows[3].newLine == 3)
        #expect(parsed.hunkCount == 1)
    }

    @Test func aBrokenHeaderOutsideAHunkStartsOne() {
        let parsed = UnifiedDiff.parse("@@ nonsense @@\n+x\n")
        #expect(parsed.rows.map(\.kind) == [.context, .added])
        #expect(parsed.rows[1].newLine == nil && parsed.rows[1].text == "x")
    }

    @Test func aBrokenHeaderInsideAHunkClearsTheNumbers() {
        let parsed = UnifiedDiff.parse("@@ -1 +1 @@\n a\n@@ nonsense @@\n b\n")
        #expect(parsed.rows[3].oldLine == nil && parsed.rows[3].newLine == nil)
    }

    @Test func hugeNumbersDoNotTrap() {
        let parsed = UnifiedDiff.parse("@@ -99999999999999999999 +1 @@\n")
        #expect(parsed.rows.count == 1 && parsed.rows[0].kind == .context)
    }

    @Test func anotherFileHeaderEndsTheHunk() {
        let parsed = UnifiedDiff.parse("@@ -1 +1 @@\n+a\ndiff --git a/b b/b\n--- a/b\n+++ b/b\n@@ -5 +5 @@\n+c\n")
        #expect(parsed.rows.map(\.text) == ["", "a", "", "c"])
    }

    @Test func aFullBudgetPatchParses() {
        var patch = ""
        var expected = 0
        var start = 1
        while patch.utf8.count < 256 * 1024 {
            patch += "@@ -\(start),2 +\(start),3 @@ section\n context line\n-old line\n+new line\n+another line\n"
            expected += 5
            start += 10
        }
        let parsed = UnifiedDiff.parse(patch)
        #expect(parsed.rows.count == expected)
        #expect(parsed.rows.last?.newLine == start - 10 + 2)
    }
}
