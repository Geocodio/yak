import Foundation
import Testing

@testable import YakKit

private let instance = UUID()

private func id(_ number: Int) -> String { String(format: "t%07d", number) }

private func archive(_ bytes: Int) -> Data { Data(repeating: 7, count: bytes) }

private func makeStore(
    files: any FileStore = InMemoryFileStore(), limits: OfflineCopyLimits = .standard,
    clock: ManualClock = ManualClock()
) -> OfflineCopyStore {
    OfflineCopyStore(instance: instance, files: files, clock: clock, limits: limits)
}

@Test func theStandardLimitsAreTheDesignedOnes() {
    #expect(OfflineCopyLimits.standard.maximumArchiveBytes == 8_000_000)
    #expect(OfflineCopyLimits.standard.maximumThreads == 30)
    #expect(OfflineCopyLimits.standard.maximumTotalBytes == 60_000_000)
}

@Test func aSavedArchiveIsReadBackWithItsTime() async {
    let clock = ManualClock()
    let store = makeStore(clock: clock)
    #expect(await store.save(archive(10), thread: id(1)) == .saved)
    let copy = await store.copy(thread: id(1))
    #expect(copy?.archive == archive(10) && copy?.savedAt == clock.now())
    #expect(await store.copy(thread: id(2)) == nil)
}

@Test func anArchiveOverTheLimitIsSkippedAndTheOldCopyKept() async {
    let limits = OfflineCopyLimits(maximumArchiveBytes: 100, maximumThreads: 5, maximumTotalBytes: 1000)
    let store = makeStore(limits: limits)
    #expect(await store.save(archive(100), thread: id(1)) == .saved)
    #expect(await store.save(archive(101), thread: id(1)) == .tooLarge)
    #expect(await store.copy(thread: id(1))?.archive == archive(100))
}

@Test func theThirtyFirstThreadEvictsTheLeastRecentlyUsed() async {
    let store = makeStore()
    for number in 1...30 { #expect(await store.save(archive(1), thread: id(number)) == .saved) }
    #expect(await store.entries().count == 30)
    #expect(await store.save(archive(1), thread: id(31)) == .saved)
    let kept = await store.entries().map(\.threadID)
    #expect(kept.count == 30 && !kept.contains(id(1)) && kept.contains(id(31)))
    #expect(await store.copy(thread: id(1)) == nil)
}

@Test func readingACopyProtectsItFromEviction() async {
    let limits = OfflineCopyLimits(maximumArchiveBytes: 100, maximumThreads: 3, maximumTotalBytes: 1000)
    let store = makeStore(limits: limits)
    for number in 1...3 { _ = await store.save(archive(1), thread: id(number)) }
    _ = await store.copy(thread: id(1))
    _ = await store.save(archive(1), thread: id(4))
    let kept = Set(await store.entries().map(\.threadID))
    #expect(kept == [id(1), id(3), id(4)])
}

@Test func theTotalSizeEvictsInUseOrderUntilItFits() async {
    let limits = OfflineCopyLimits(maximumArchiveBytes: 50, maximumThreads: 30, maximumTotalBytes: 100)
    let store = makeStore(limits: limits)
    _ = await store.save(archive(40), thread: id(1))
    _ = await store.save(archive(40), thread: id(2))
    _ = await store.save(archive(20), thread: id(3))
    #expect(Set(await store.entries().map(\.threadID)) == [id(1), id(2), id(3)])
    _ = await store.save(archive(50), thread: id(4))
    // 150 bytes: the oldest (40) goes, still 110, the next (40) goes too.
    #expect(Set(await store.entries().map(\.threadID)) == [id(3), id(4)])
}

@Test func theNewestArchiveIsNeverTheOneEvicted() async {
    let limits = OfflineCopyLimits(maximumArchiveBytes: 100, maximumThreads: 1, maximumTotalBytes: 100)
    let store = makeStore(limits: limits)
    _ = await store.save(archive(100), thread: id(1))
    #expect(await store.save(archive(100), thread: id(2)) == .saved)
    #expect(await store.entries().map(\.threadID) == [id(2)])
}

@Test func anEvictedArchiveIsDeletedFromDisk() async throws {
    let files = InMemoryFileStore()
    let limits = OfflineCopyLimits(maximumArchiveBytes: 100, maximumThreads: 1, maximumTotalBytes: 100)
    let store = makeStore(files: files, limits: limits)
    _ = await store.save(archive(1), thread: id(1))
    let path = "\(InstanceFolders.offlineCopies(instance))/\(id(1)).webarchive"
    #expect(try files.exists(path))
    _ = await store.save(archive(1), thread: id(2))
    #expect(try !files.exists(path))
}

@Test func savingAThreadAgainReplacesItsArchiveAndSize() async {
    let store = makeStore()
    _ = await store.save(archive(10), thread: id(1))
    _ = await store.save(archive(20), thread: id(1))
    let entries = await store.entries()
    #expect(entries.count == 1 && entries[0].bytes == 20)
}

@Test func aHiddenThreadLosesItsCopy() async throws {
    let files = InMemoryFileStore()
    let store = makeStore(files: files)
    _ = await store.save(archive(1), thread: id(1))
    _ = await store.save(archive(1), thread: id(2))
    await store.remove(thread: id(1))
    #expect(await store.copy(thread: id(1)) == nil)
    #expect(await store.copy(thread: id(2)) != nil)
    #expect(try !files.exists("\(InstanceFolders.offlineCopies(instance))/\(id(1)).webarchive"))
    await store.remove(thread: id(9))
}

@Test func clearAllRemovesEveryCopyAndTheIndex() async throws {
    let files = InMemoryFileStore()
    let store = makeStore(files: files)
    for number in 1...3 { _ = await store.save(archive(1), thread: id(number)) }
    await store.removeAll()
    #expect(await store.entries().isEmpty)
    #expect(try files.list(InstanceFolders.offlineCopies(instance)).isEmpty)
    #expect(await store.save(archive(1), thread: id(4)) == .saved)
}

@Test func theIndexSurvivesANewStoreOnTheSameFiles() async {
    let files = InMemoryFileStore()
    let first = makeStore(files: files)
    _ = await first.save(archive(5), thread: id(1))
    _ = await first.save(archive(5), thread: id(2))
    let second = makeStore(files: files)
    #expect(await second.entries().map(\.threadID) == [id(2), id(1)])
    #expect(await second.copy(thread: id(1))?.archive == archive(5))
}

@Test func aBrokenIndexStartsOverAndRemovesTheArchives() async throws {
    let files = InMemoryFileStore()
    let folder = InstanceFolders.offlineCopies(instance)
    try files.write(archive(5), to: "\(folder)/\(id(1)).webarchive", protection: .complete)
    try files.write(Data("not json".utf8), to: "\(folder)/index.json", protection: .complete)
    let store = makeStore(files: files)
    #expect(await store.entries().isEmpty)
    #expect(try !files.exists("\(folder)/\(id(1)).webarchive"))
}

@Test func aLockedDeviceKeepsTheIndexAndSavesNothing() async {
    let files = InMemoryFileStore()
    let first = makeStore(files: files)
    _ = await first.save(archive(5), thread: id(1))
    let second = makeStore(files: files)
    files.isLocked = true
    #expect(await second.save(archive(5), thread: id(2)) == .notSaved)
    #expect(await second.copy(thread: id(1)) == nil)
    files.isLocked = false
    #expect(await second.copy(thread: id(1)) != nil)
}

@Test func aMissingArchiveFileDropsItsEntry() async throws {
    let files = InMemoryFileStore()
    let store = makeStore(files: files)
    _ = await store.save(archive(5), thread: id(1))
    try files.remove("\(InstanceFolders.offlineCopies(instance))/\(id(1)).webarchive")
    #expect(await store.copy(thread: id(1)) == nil)
    #expect(await store.entries().isEmpty)
}

@Test(arguments: ["", "../escape", "short", "toolongthreadid", "a/b/c/d/e"])
func aThreadIDThatIsNotAnIDIsNeverUsedAsAPath(thread: String) async {
    let store = makeStore()
    #expect(await store.save(archive(1), thread: thread) == .notSaved)
    #expect(await store.copy(thread: thread) == nil)
}

@Test func anInvalidatedStoreWritesNothingAndListsNothing() async throws {
    let files = InMemoryFileStore()
    let store = makeStore(files: files)
    _ = await store.save(archive(3), thread: id(1))
    await store.invalidate()
    #expect(await store.entries().isEmpty)
    #expect(await store.save(archive(3), thread: id(2)) == .notSaved)
    #expect(await store.copy(thread: id(1)) == nil)
    #expect(try files.list(InstanceFolders.offlineCopies(instance)).isEmpty)
}

@Test func aStoreNoticesThatTheFolderWasErasedUnderIt() async throws {
    let files = InMemoryFileStore()
    let store = makeStore(files: files)
    _ = await store.save(archive(3), thread: id(1))
    try files.remove(InstanceFolders.cache(instance))
    #expect(await store.entries().isEmpty)
    #expect(await store.save(archive(3), thread: id(2)) == .notSaved)
    #expect(try files.list(InstanceFolders.cache(instance)).isEmpty)
    #expect(try !files.exists("\(InstanceFolders.offlineCopies(instance))/index.json"))
}

@Test func theEntryAndCopyInitializersArePublic() {
    let now = Date()
    #expect(OfflineCopyEntry(threadID: "t", bytes: 1, savedAt: now, useOrder: 2).useOrder == 2)
    #expect(OfflineCopy(archive: Data([1]), savedAt: now).archive == Data([1]))
}

@Test func theCopiesAreKeptOutOfBackupsAndProtectedWhileLocked() async {
    let files = InMemoryFileStore()
    let store = makeStore(files: files)
    #expect(await store.save(archive(10), thread: id(1)) == .saved)
    #expect(files.isExcludedFromBackup(InstanceFolders.offlineCopies(instance)))
    #expect(files.protection(of: "\(InstanceFolders.offlineCopies(instance))/\(id(1)).webarchive") == .complete)
}

@Test func aFolderErasedAfterAStoreStartedOnAnEmptyFolderStopsTheStore() async throws {
    let files = InMemoryFileStore()
    let store = makeStore(files: files)
    #expect(await store.entries().isEmpty)
    try files.remove(InstanceFolders.offlineCopies(instance))
    #expect(await store.save(archive(10), thread: id(1)) == .notSaved)
    #expect(try files.list(InstanceFolders.offlineCopies(instance)).isEmpty)
}

// MARK: Clearing every instance from Settings

/// Refuses to remove the folders of the listed instances, as a disk that cannot be written would.
private final class RefusingRemovalStore: FileStore, @unchecked Sendable {
    let inner = InMemoryFileStore()
    var refused: Set<String> = []
    func read(_ path: String) throws(FileStoreError) -> Data? { try inner.read(path) }
    func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        try inner.write(data, to: path, protection: protection)
    }
    func remove(_ path: String) throws(FileStoreError) {
        if refused.contains(path) { throw .unreadable }
        try inner.remove(path)
    }
    func list(_ folder: String) throws(FileStoreError) -> [String] { try inner.list(folder) }
}

private let otherInstance = UUID()

@Test func theSummaryAddsUpEveryInstanceFromDiskWithoutAStore() async {
    let files = InMemoryFileStore()
    let first = makeStore(files: files)
    let second = OfflineCopyStore(instance: otherInstance, files: files)
    _ = await first.save(archive(10), thread: id(1))
    _ = await first.save(archive(20), thread: id(2))
    _ = await second.save(archive(5), thread: id(3))
    let summary = OfflineCopyStore.summary(of: [instance, otherInstance, UUID()], files: files)
    #expect(summary == OfflineCopySummary(threads: 3, bytes: 35))
    #expect(OfflineCopyStore.summary(of: [], files: files).isEmpty)
}

@Test func clearingEveryInstanceRemovesLiveAndStorelessCopiesAndKeepsSaving() async {
    let files = InMemoryFileStore()
    let live = makeStore(files: files)
    let storeless = OfflineCopyStore(instance: otherInstance, files: files)
    _ = await live.save(archive(10), thread: id(1))
    _ = await storeless.save(archive(10), thread: id(2))
    let failed = await OfflineCopyStore.removeAll(
        of: [instance, otherInstance], files: files, liveStores: [instance: live])
    #expect(failed.isEmpty)
    #expect(OfflineCopyStore.summary(of: [instance, otherInstance], files: files).isEmpty)
    #expect(await live.entries().isEmpty)
    #expect(await live.copy(thread: id(1)) == nil)
    // The store is not turned off: the next visit online saves a copy again.
    #expect(await live.save(archive(7), thread: id(1)) == .saved)
    #expect(OfflineCopyStore.summary(of: [instance], files: files) == OfflineCopySummary(threads: 1, bytes: 7))
}

@Test func anInstanceThatCannotBeClearedIsReportedAndKeepsItsCopies() async {
    let files = RefusingRemovalStore()
    let live = makeStore(files: files)
    let storeless = OfflineCopyStore(instance: otherInstance, files: files)
    _ = await live.save(archive(10), thread: id(1))
    _ = await storeless.save(archive(10), thread: id(2))
    files.refused = [InstanceFolders.offlineCopies(instance)]
    var failed = await OfflineCopyStore.removeAll(
        of: [instance, otherInstance], files: files, liveStores: [instance: live])
    #expect(failed == [instance])
    #expect(await live.entries().map(\.threadID) == [id(1)])
    #expect(OfflineCopyStore.summary(of: [instance, otherInstance], files: files).threads == 1)
    files.refused = [InstanceFolders.offlineCopies(otherInstance)]
    failed = await OfflineCopyStore.removeAll(of: [otherInstance], files: files, liveStores: [:])
    #expect(failed == [otherInstance])
}

@Test func aCaptureThatLandsAfterTheClearShowsInTheNextCount() async {
    let files = InMemoryFileStore()
    let live = makeStore(files: files)
    _ = await live.save(archive(10), thread: id(1))
    _ = await OfflineCopyStore.removeAll(of: [instance], files: files, liveStores: [instance: live])
    #expect(OfflineCopyStore.summary(of: [instance], files: files).isEmpty)
    _ = await live.save(archive(4), thread: id(2))
    #expect(OfflineCopyStore.summary(of: [instance], files: files) == OfflineCopySummary(threads: 1, bytes: 4))
}

@Test func clearingTheCopiesLeavesTheUnsentMessagesAlone() async throws {
    let files = InMemoryFileStore()
    let live = makeStore(files: files)
    _ = await live.save(archive(10), thread: id(1))
    let outboxFile = "\(InstanceFolders.outbox(instance))/queue.json"
    try files.write(Data("unsent".utf8), to: outboxFile, protection: .complete)
    _ = await OfflineCopyStore.removeAll(of: [instance], files: files, liveStores: [instance: live])
    #expect(try files.read(outboxFile) == Data("unsent".utf8))
}
