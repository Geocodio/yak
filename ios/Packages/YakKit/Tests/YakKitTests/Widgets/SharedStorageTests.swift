import Foundation
import Testing

@testable import YakKit

private struct PlainStore: KeyValueStore {
    let box = Box()
    final class Box: @unchecked Sendable { var values: [String: Data] = [:] }
    func data(forKey key: String) -> Data? { box.values[key] }
    func set(_ value: Data?, forKey key: String) { box.values[key] = value }
}

private func makeCache(files: InMemoryFileStore = InMemoryFileStore()) -> (Cache, InMemoryFileStore, UUID) {
    let id = UUID()
    return (Cache(instance: id, files: files, clock: ManualClock()), files, id)
}

private func someThread() throws -> CachedThread {
    let list = try EventList.load("events/simple-turn")
    return CachedThread(
        show: try decoded(ThreadShow.self, "scenario-thread-show"), events: list.events,
        lastSequence: list.catchUp.latestSequence, firstLoadedAfter: 0)
}

struct SharedStorageTests {
    private func instances() -> Data {
        let list = [
            Instance(
                url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
                lastUsedAt: Date(timeIntervalSince1970: 1)),
            Instance(
                url: URL(string: "https://other.example.com")!, name: "Other Yak", userName: "Test person",
                lastUsedAt: Date(timeIntervalSince1970: 2)),
        ]
        return try! JSONEncoder().encode(list)
    }

    @Test func movesTheListOnceAndClearsTheOldStore() {
        let old = PlainStore()
        let new = PlainStore()
        let list = instances()
        old.set(list, forKey: "instances.v1")
        old.set(Data(UUID().uuidString.utf8), forKey: "instances.active")
        let active = old.data(forKey: "instances.active")
        #expect(InstanceStore.moveStoredList(from: old, to: new))
        #expect(new.data(forKey: "instances.v1") == list)
        #expect(new.data(forKey: "instances.active") == active)
        #expect(old.data(forKey: "instances.v1") == nil)
        #expect(old.data(forKey: "instances.active") == nil)
        #expect(!InstanceStore.moveStoredList(from: old, to: new))
        #expect(new.data(forKey: "instances.v1") == list)
    }

    @Test func neverOverwritesAListThatIsAlreadyShared() {
        let old = PlainStore()
        let new = PlainStore()
        let shared = Data("[]".utf8)
        new.set(shared, forKey: "instances.v1")
        let list = instances()
        old.set(list, forKey: "instances.v1")
        #expect(!InstanceStore.moveStoredList(from: old, to: new))
        #expect(new.data(forKey: "instances.v1") == shared)
        #expect(old.data(forKey: "instances.v1") == list)
    }

    @Test func anUnreadableOldListIsNotMoved() {
        let old = PlainStore()
        let new = PlainStore()
        old.set(Data("garbage".utf8), forKey: "instances.v1")
        #expect(!InstanceStore.moveStoredList(from: old, to: new))
        #expect(new.data(forKey: "instances.v1") == nil)
    }

    @Test func theLastOpenedThreadIsTheOneOpened() async throws {
        let (cache, files, id) = makeCache()
        #expect(await cache.lastOpenedThreadID() == nil)
        await cache.markOpened("d0e515ef")
        await cache.saveThread("a1b2c3d4", try someThread())
        #expect(await cache.lastOpenedThreadID() == "d0e515ef")
        await cache.markOpened("a1b2c3d4")
        #expect(await cache.lastOpenedThreadID() == "a1b2c3d4")
        #expect(files.protection(of: InstanceFolders.lastOpened(id)) == .untilFirstUnlock)
        files.isLocked = true
        #expect(await cache.lastOpenedThreadID() == "a1b2c3d4")
        files.isLocked = false
        await cache.removeThread("a1b2c3d4")
        #expect(await cache.lastOpenedThreadID() == nil)
    }

    @Test func removingAnotherThreadKeepsTheLastOpened() async throws {
        let (cache, _, _) = makeCache()
        await cache.markOpened("d0e515ef")
        await cache.removeThread("a1b2c3d4")
        #expect(await cache.lastOpenedThreadID() == "d0e515ef")
    }

    @Test func aHostileLastOpenedFileReadsAsNothing() async throws {
        let (cache, files, id) = makeCache()
        let path = InstanceFolders.lastOpened(id)
        try files.write(Data(#"{"id": "../x"}"#.utf8), to: path, protection: .untilFirstUnlock)
        #expect(await cache.lastOpenedThreadID() == nil)
        try files.write(
            Data(#"{"id": "\#(String(repeating: "a", count: 2048))"}"#.utf8), to: path, protection: .untilFirstUnlock)
        #expect(await cache.lastOpenedThreadID() == nil)
        #expect(try files.read(path) == nil)
        try files.write(Data("not json".utf8), to: path, protection: .untilFirstUnlock)
        #expect(await cache.lastOpenedThreadID() == nil)
        #expect(try files.read(path) == nil)
    }

    @Test func widgetPathsLiveInTheCacheOrTheRoot() async throws {
        let (cache, files, id) = makeCache()
        #expect(InstanceFolders.widgetPushRecord(id).hasPrefix(InstanceFolders.cache(id)))
        #expect(InstanceFolders.lastOpened(id).hasPrefix(InstanceFolders.cache(id)))
        #expect(!InstanceFolders.widgetPushToken.contains("/"))
        try files.write(Data("{}".utf8), to: InstanceFolders.widgetPushRecord(id), protection: .untilFirstUnlock)
        await cache.eraseAll()
        #expect(try files.read(InstanceFolders.widgetPushRecord(id)) == nil)
    }
}
