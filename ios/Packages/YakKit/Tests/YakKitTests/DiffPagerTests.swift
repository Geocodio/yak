import Testing

@testable import YakKit

private func scenarioFiles() throws -> [DiffFileSummary] {
    try #require(try decoded(ThreadDiffResponse.self, "scenario-thread-diff").diff).files
}

struct DiffPagerTests {
    @Test func aRecaptureKeepsTheFileByKey() throws {
        let files = try scenarioFiles()
        var pager = DiffPager(files: files, restoring: nil)
        pager.next()
        pager.next()
        let recaptured = files.map { $0.withID($0.id + 100) }
        #expect(pager.replace(files: recaptured) == .kept)
        #expect(pager.current?.path == "src/uploads.ts")
        #expect(pager.position?.index == 3)
        #expect(pager.files.map(\.id) == [601, 602, 603, 604])
    }

    @Test func aGoneFileMovesToItsNeighborOnce() throws {
        let files = try scenarioFiles()
        var pager = DiffPager(files: files, restoring: DiffFileKey(repo: "example/web", path: "src/uploads.ts"))
        let without = files.filter { $0.path != "src/uploads.ts" }
        #expect(
            pager.replace(files: without)
                == .movedBecauseGone(previous: DiffFileKey(repo: "example/web", path: "src/uploads.ts")))
        #expect(pager.current?.path == "database/seed.sql")
        #expect(pager.replace(files: without) == .kept)
    }

    @Test func aGoneLastFileClampsAndAnEmptyListEmpties() throws {
        let files = try scenarioFiles()
        var pager = DiffPager(files: files, restoring: files[3].key)
        _ = pager.replace(files: Array(files.prefix(3)))
        #expect(pager.current == files[2].key)
        #expect(pager.replace(files: []) == .emptied)
        #expect(pager.current == nil && pager.position == nil)
        #expect(pager.replace(files: files) == .started)
        #expect(pager.current == files[0].key)
    }

    @Test func restoringAnUnknownKeyStartsAtTheFirstFile() throws {
        let files = try scenarioFiles()
        #expect(DiffPager(files: files, restoring: DiffFileKey(repo: "example/api", path: "x")).current == files[0].key)
        #expect(DiffPager(files: [], restoring: nil).current == nil)
    }

    @Test func nextAndPreviousStopAtTheEnds() throws {
        let files = try scenarioFiles()
        var pager = DiffPager(files: files, restoring: nil)
        #expect(!pager.canGoBack && pager.canGoForward)
        pager.previous()
        #expect(pager.position?.index == 1 && pager.position?.count == 4)
        for _ in 0..<5 { pager.next() }
        #expect(pager.position?.index == 4 && !pager.canGoForward)
        pager.show(DiffFileKey(repo: "nope", path: "nope"))
        #expect(pager.position?.index == 4)
        pager.show(files[1].key)
        #expect(pager.position?.index == 2)
    }

    @Test func neighborsArePrefetchable() throws {
        let files = try scenarioFiles()
        var pager = DiffPager(files: files, restoring: files[1].key)
        #expect(pager.neighbors().map(\.id) == [501, 503])
        pager.previous()
        #expect(pager.neighbors().map(\.id) == [502])
    }
}
