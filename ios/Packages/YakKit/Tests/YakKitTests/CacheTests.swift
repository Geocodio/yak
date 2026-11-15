import Foundation
import Testing

@testable import YakKit

private func showScenario() throws -> ThreadShow { try decoded(ThreadShow.self, "scenario-thread-show") }

private func cachedSimpleTurn() throws -> CachedThread {
    let list = try EventList.load("events/simple-turn")
    return CachedThread(
        show: try showScenario(), events: list.events, lastSequence: list.catchUp.latestSequence,
        firstLoadedAfter: 0)
}

private func plainEvents(_ range: ClosedRange<Int>) -> [ThreadEvent] {
    range.map { ThreadEvent(sequence: $0, type: "diff.updated", createdAt: "2026-01-01T00:00:00Z", data: .null) }
}

private func makeCache(
    _ id: UUID = UUID(), files: InMemoryFileStore = InMemoryFileStore(), clock: ManualClock = ManualClock()
) -> (Cache, InMemoryFileStore, UUID) {
    (Cache(instance: id, files: files, clock: clock), files, id)
}

struct CacheTests {
    @Test func usesTheRightProtectionForEachFile() async throws {
        let (cache, files, id) = makeCache()
        await cache.saveThreadList(try decoded(ThreadList.self, "scenario-thread-list"), filter: .mine)
        await cache.saveThread("d0e515ef", try cachedSimpleTurn())
        await cache.saveQuestionDraft(QuestionDraft(roundKey: "r1"), thread: "d0e515ef")
        #expect(files.protection(of: "instances/\(id)/cache/lists/mine.json") == .untilFirstUnlock)
        #expect(files.protection(of: "instances/\(id)/cache/index.json") == .untilFirstUnlock)
        #expect(files.protection(of: "instances/\(id)/cache/threads/d0e515ef/show.json") == .complete)
        #expect(files.protection(of: "instances/\(id)/cache/threads/d0e515ef/events.json") == .complete)
        #expect(files.protection(of: "instances/\(id)/cache/threads/d0e515ef/question.json") == .complete)
        await cache.saveWidgetSnapshot(
            WidgetSnapshot(instanceName: "Example Yak", needsYouCount: 0, needsYou: [], running: [], updatedAt: Date()))
        #expect(files.protection(of: InstanceFolders.widgetSnapshot(id)) == .untilFirstUnlock)
    }

    @Test func aLockedDeviceReadsNoThreadButStillReadsTheList() async throws {
        let (cache, files, _) = makeCache()
        await cache.saveThreadList(try decoded(ThreadList.self, "scenario-thread-list"), filter: .mine)
        await cache.saveThread("d0e515ef", try cachedSimpleTurn())
        files.isLocked = true
        #expect(await cache.thread("d0e515ef") == nil)
        #expect(await cache.threadList(.mine) != nil)
        files.isLocked = false
        #expect(await cache.thread("d0e515ef") != nil)
    }

    @Test func roundTripsAThread() async throws {
        let (cache, _, _) = makeCache()
        let thread = try cachedSimpleTurn()
        await cache.saveThread("d0e515ef", thread)
        let read = try #require(await cache.thread("d0e515ef"))
        #expect(read.show == thread.show)
        #expect(read.events == thread.events.filter(\.isStored))
        #expect(read.lastSequence == thread.lastSequence)
    }

    @Test func aMissingThreadAndAFreshInstanceReadAsNothing() async {
        let (cache, _, _) = makeCache()
        #expect(await cache.thread("nope") == nil)
        #expect(await cache.threadList(.everyone) == nil)
        #expect(await cache.questionDraft(thread: "nope") == nil)
        #expect(await cache.widgetSnapshot() == nil)
    }

    @Test func keepsTheNewest2000Events() async throws {
        let (cache, _, _) = makeCache()
        var thread = try cachedSimpleTurn()
        thread.events = plainEvents(1...2300)
        thread.lastSequence = 2300
        await cache.saveThread("d0e515ef", thread)
        let read = try #require(await cache.thread("d0e515ef"))
        #expect(read.events.count == 2000)
        #expect(read.events.first?.sequence == 301)
        #expect(read.events.last?.sequence == 2300)
        #expect(read.firstLoadedAfter == 300)
    }

    @Test func neverStoresDeltasOrResets() async throws {
        let (cache, _, _) = makeCache()
        let thread = try cachedSimpleTurn()
        #expect(thread.events.contains { !$0.isStored })
        await cache.saveThread("d0e515ef", thread)
        let read = try #require(await cache.thread("d0e515ef"))
        #expect(read.events.count == thread.events.filter(\.isStored).count)
        #expect(read.events.allSatisfy { $0.type != "delta" && $0.type != "stream.reset" })
    }

    @Test func evictsTheThreadOpenedLongestAgo() async throws {
        let clock = ManualClock()
        let (cache, _, _) = makeCache(clock: clock)
        let thread = try cachedSimpleTurn()
        for number in 0..<50 {
            await cache.saveThread("thread-\(number)", thread)
            await clock.advance(by: .seconds(1))
        }
        await cache.markOpened("thread-0")
        await clock.advance(by: .seconds(1))
        await cache.saveThread("thread-50", thread)
        #expect(await cache.thread("thread-0") != nil)
        #expect(await cache.thread("thread-1") == nil)
        #expect(await cache.thread("thread-2") != nil)
        #expect(await cache.thread("thread-50") != nil)
    }

    @Test func evictsTheFirstOpenedWhenNoneWasReopened() async throws {
        let clock = ManualClock()
        let (cache, _, _) = makeCache(clock: clock)
        let thread = try cachedSimpleTurn()
        for number in 0...50 {
            await cache.saveThread("thread-\(number)", thread)
            await clock.advance(by: .seconds(1))
        }
        #expect(await cache.thread("thread-0") == nil)
        for number in 1...50 { #expect(await cache.thread("thread-\(number)") != nil) }
    }

    @Test func eraseAllRemovesEverythingOfThatInstanceOnly() async throws {
        let files = InMemoryFileStore()
        let (first, _, _) = makeCache(files: files)
        let (second, _, _) = makeCache(files: files)
        let list: ThreadList = try decoded(ThreadList.self, "scenario-thread-list")
        await first.saveThreadList(list, filter: .mine)
        await first.saveThread("d0e515ef", try cachedSimpleTurn())
        await second.saveThreadList(list, filter: .mine)
        await first.eraseAll()
        #expect(await first.threadList(.mine) == nil)
        #expect(await first.thread("d0e515ef") == nil)
        #expect(await second.threadList(.mine) != nil)
    }

    @Test func removeThreadDropsItsFolderAndIndexEntry() async throws {
        let (cache, files, id) = makeCache()
        await cache.saveThread("d0e515ef", try cachedSimpleTurn())
        await cache.removeThread("d0e515ef")
        #expect(await cache.thread("d0e515ef") == nil)
        #expect(try files.list("instances/\(id)/cache/threads").isEmpty)
    }

    @Test func aCorruptFileReadsAsNothingAndIsRemoved() async throws {
        let (cache, files, id) = makeCache()
        await cache.saveThread("d0e515ef", try cachedSimpleTurn())
        let path = "instances/\(id)/cache/threads/d0e515ef/show.json"
        try files.write(Data("{".utf8), to: path, protection: .complete)
        #expect(await cache.thread("d0e515ef") == nil)
        #expect(try files.read(path) == nil)
    }

    @Test func roundTripsAQuestionDraft() async {
        let (cache, _, _) = makeCache()
        let draft = QuestionDraft(
            roundKey: "round-1", answers: ["q1": QuestionAnswer(choices: ["a"], other: "more")], note: "Test note")
        await cache.saveQuestionDraft(draft, thread: "d0e515ef")
        #expect(await cache.questionDraft(thread: "d0e515ef") == draft)
        await cache.saveQuestionDraft(nil, thread: "d0e515ef")
        #expect(await cache.questionDraft(thread: "d0e515ef") == nil)
    }

    @Test func aThreadIdCannotEscapeItsFolder() async throws {
        let (cache, files, id) = makeCache()
        await cache.saveThread("../../x", try cachedSimpleTurn())
        #expect(try files.list("instances/\(id)/cache/threads").allSatisfy { $0 != ".." })
    }

    @Test func diskStoreRefusesEscapingPaths() throws {
        let store = DiskFileStore(
            root: FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString))
        #expect(throws: FileStoreError.invalidPath) { try store.write(Data(), to: "../x", protection: .complete) }
        #expect(throws: FileStoreError.invalidPath) { try store.write(Data(), to: "/x", protection: .complete) }
        #expect(throws: FileStoreError.invalidPath) { try store.read("a/../../x") }
    }

    @Test func diskStoreWritesAndListsFiles() throws {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        defer { try? FileManager.default.removeItem(at: root) }
        let store = DiskFileStore(root: root)
        #expect(try store.read("a/b.json") == nil)
        #expect(try store.list("a").isEmpty)
        try store.write(Data("one".utf8), to: "a/b.json", protection: .complete)
        try store.write(Data("two".utf8), to: "a/b.json", protection: .untilFirstUnlock)
        #expect(try store.read("a/b.json") == Data("two".utf8))
        #expect(try store.list("a") == ["b.json"])
        try store.remove("a")
        try store.remove("a")
        #expect(try store.list("a").isEmpty)
    }
}
