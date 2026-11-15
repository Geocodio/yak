import Testing

@testable import YakKit

private func entry(_ path: String, directory: Bool = false) -> FileTreeEntry {
    FileTreeEntry(
        name: String(path.split(separator: "/").last ?? ""), path: path, isDirectory: directory, isWritable: true,
        size: directory ? nil : 10)
}

private func row(_ entry: FileTreeEntry) -> FileTreeRow {
    FileTreeRow(
        id: entry.path, name: entry.name, depth: 0, isDirectory: entry.isDirectory, isExpanded: false,
        folderState: entry.isDirectory ? .notLoaded : nil, isEmptyFolder: false, mark: nil)
}

private let rootEntries = [
    entry("src", directory: true), entry("tests", directory: true), entry("README.md"),
]

private func rootOnly() -> FileTreeSnapshot {
    FileTreeSnapshot(
        root: .loaded(truncated: false), rows: rootEntries.map(row), changedCount: 2,
        changedPaths: ["tests/ImporterTest.php", "src/Importer.php"], addedPaths: ["tests/ImporterTest.php"])
}

@Suite struct FileFindTests {
    @Test func findsLoadedAndChangedFiles() {
        let state = FileFind.local("import", in: rootOnly(), loaded: rootEntries)
        #expect(
            state
                == .results(
                    [
                        FileFindResult(name: "Importer.php", path: "src/Importer.php", mark: .modified),
                        FileFindResult(name: "ImporterTest.php", path: "tests/ImporterTest.php", mark: .added),
                    ], truncated: false, isPartial: true))
    }

    @Test func noMatchNamesTheQuery() {
        #expect(FileFind.local(" zzz ", in: rootOnly(), loaded: rootEntries) == .noMatch(query: "zzz", isPartial: true))
        #expect(FileFind.local("  ", in: rootOnly(), loaded: rootEntries) == .idle)
    }

    @Test func matchesIgnoreCase() {
        let state = FileFind.local("readme", in: rootOnly(), loaded: rootEntries)
        #expect(
            state
                == .results(
                    [FileFindResult(name: "README.md", path: "README.md", mark: nil)], truncated: false, isPartial: true
                ))
    }

    @Test func atMostOneHundred() {
        let files = (0..<150).map { entry("f\($0).txt") }
        let snapshot = FileTreeSnapshot(root: .loaded(truncated: false), rows: files.map(row))
        guard
            case .results(let results, let truncated, let isPartial) = FileFind.local("f", in: snapshot, loaded: files)
        else {
            Issue.record("expected results")
            return
        }
        #expect(results.count == 100)
        #expect(truncated)
        #expect(!isPartial)
        #expect(results.map(\.path) == results.map(\.path).sorted())
    }
}
