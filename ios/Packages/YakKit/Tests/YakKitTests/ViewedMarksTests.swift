import Foundation
import Testing

@testable import YakKit

private func firstFile() throws -> DiffFileSummary {
    try #require(try decoded(ThreadDiffResponse.self, "scenario-thread-diff").diff).files[0]
}

private func file(_ number: Int) -> DiffFileSummary {
    DiffFileSummary(
        id: number, repo: "example/api", path: "file-\(number).php", status: .modified, additions: 1,
        deletions: 1)
}

struct ViewedMarksTests {
    @Test func aMarkClearsWhenTheCountsMove() throws {
        var marks = ViewedMarks()
        let file = try firstFile()
        marks.set(file, viewed: true)
        #expect(marks.isViewed(file) && marks.isViewed(file.withID(601)))
        let moved = file.withCounts(additions: 10, deletions: 4)
        #expect(!marks.isViewed(moved))
        #expect(marks.viewedCount(in: [file, moved]) == 1)
        marks.prune(keeping: [moved])
        #expect(marks.marks.isEmpty)
    }

    @Test func unsettingAndPruningGoneFiles() throws {
        var marks = ViewedMarks()
        let first = try firstFile()
        marks.set(first, viewed: true)
        marks.set(file(2), viewed: true)
        marks.prune(keeping: [first])
        #expect(marks.marks == [ViewedMark(first)])
        marks.set(first, viewed: false)
        #expect(marks.marks.isEmpty)
    }

    @Test func aBinaryFileCanBeMarked() {
        var marks = ViewedMarks()
        let binary = DiffFileSummary(id: 1, repo: "example/api", path: "logo.png", status: .added, isBinary: true)
        marks.set(binary, viewed: true)
        #expect(marks.isViewed(binary.withID(9)))
    }

    @Test func marksAreCappedOldestFirst() throws {
        var marks = ViewedMarks()
        for number in 0...ViewedMarks.limit { marks.set(file(number), viewed: true) }
        #expect(marks.marks.count == ViewedMarks.limit)
        #expect(!marks.isViewed(file(0)) && marks.isViewed(file(1)) && marks.isViewed(file(ViewedMarks.limit)))
        let decoded = try JSONDecoder().decode(ViewedMarks.self, from: JSONEncoder().encode(marks))
        #expect(decoded == marks)
        var again = decoded
        again.set(file(1000), viewed: true)
        #expect(!again.isViewed(file(1)) && again.isViewed(file(2)))
    }

    @Test func marksAndDiffLiveInTheThreadFolder() async throws {
        let store = InMemoryFileStore()
        let instance = UUID()
        let diff = try #require(try decoded(ThreadDiffResponse.self, "scenario-thread-diff").diff)
        var marks = ViewedMarks()
        marks.set(diff.files[0], viewed: true)
        let cache = Cache(instance: instance, files: store, clock: ManualClock())
        await cache.saveViewedMarks(marks, thread: "d0e515ef")
        await cache.saveDiff(diff, thread: "d0e515ef")
        #expect(store.protection(of: "instances/\(instance)/cache/threads/d0e515ef/diff.json") == .complete)
        #expect(store.protection(of: "instances/\(instance)/cache/threads/d0e515ef/viewed.json") == .complete)

        let relaunched = Cache(instance: instance, files: store, clock: ManualClock())
        #expect(await relaunched.diff(thread: "d0e515ef") == diff)
        #expect(await relaunched.viewedMarks(thread: "d0e515ef") == marks)
        #expect(await relaunched.viewedMarks(thread: "a1b2c3d4") == ViewedMarks())
        #expect(await relaunched.diff(thread: "a1b2c3d4") == nil)

        await relaunched.saveDiff(nil, thread: "d0e515ef")
        #expect(await relaunched.diff(thread: "d0e515ef") == nil)
        await relaunched.saveDiff(diff, thread: "d0e515ef")
        await relaunched.removeThread("d0e515ef")
        #expect(await relaunched.diff(thread: "d0e515ef") == nil)
        #expect(await relaunched.viewedMarks(thread: "d0e515ef") == ViewedMarks())

        await relaunched.saveViewedMarks(marks, thread: "d0e515ef")
        await relaunched.eraseAll()
        #expect(await relaunched.viewedMarks(thread: "d0e515ef") == ViewedMarks())
    }
}
