import Foundation
import Testing

@testable import YakKit

private func temporaryRoot() -> URL {
    FileManager.default.temporaryDirectory.appendingPathComponent("bounded-\(UUID().uuidString)")
}

@Suite struct BoundedReadTests {
    @Test func aFileAtTheBoundIsRead() throws {
        let store = DiskFileStore(root: temporaryRoot())
        try store.write(Data(repeating: 1, count: 10), to: "a", protection: .untilFirstUnlock)
        #expect(try store.read("a", maximumBytes: 10)?.count == 10)
    }

    @Test func noLimitAtIntMaxReadsTheFile() throws {
        let store = DiskFileStore(root: temporaryRoot())
        try store.write(Data(repeating: 1, count: 10), to: "a", protection: .untilFirstUnlock)
        #expect(try store.read("a", maximumBytes: Int.max)?.count == 10)
    }

    @Test func aFileOneByteOverIsRefused() throws {
        let store = DiskFileStore(root: temporaryRoot())
        try store.write(Data(repeating: 1, count: 11), to: "a", protection: .untilFirstUnlock)
        #expect(throws: FileStoreError.tooLarge) { try store.read("a", maximumBytes: 10) }
    }

    @Test func aFileThatGrowsAfterTheSizeCheckIsRefused() throws {
        let root = temporaryRoot()
        let plain = DiskFileStore(root: root)
        try plain.write(Data(repeating: 1, count: 10), to: "a", protection: .untilFirstUnlock)
        let store = DiskFileStore(root: root) {
            try? plain.append(Data(repeating: 2, count: 5000), to: "a", protection: .untilFirstUnlock)
        }
        #expect(throws: FileStoreError.tooLarge) { try store.read("a", maximumBytes: 10) }
    }

    @Test func aMissingFileIsMissing() throws {
        let store = DiskFileStore(root: temporaryRoot())
        #expect(try store.read("nothing", maximumBytes: 10) == nil)
        #expect(throws: FileStoreError.invalidPath) { try store.read("../x", maximumBytes: 10) }
    }

    @Test func negativeAndZeroBounds() throws {
        let store = DiskFileStore(root: temporaryRoot())
        try store.write(Data(), to: "empty", protection: .untilFirstUnlock)
        try store.write(Data([1]), to: "one", protection: .untilFirstUnlock)
        #expect(try store.read("empty", maximumBytes: 0) == Data())
        #expect(throws: FileStoreError.tooLarge) { try store.read("one", maximumBytes: 0) }
        #expect(throws: FileStoreError.tooLarge) { try store.read("empty", maximumBytes: -1) }
        #expect(throws: FileStoreError.tooLarge) { try store.read("one", maximumBytes: -1) }
    }

    @Test func theInMemoryStoreAgrees() throws {
        let store = InMemoryFileStore()
        try store.write(Data(repeating: 1, count: 10), to: "a", protection: .complete)
        #expect(try store.read("a", maximumBytes: 10)?.count == 10)
        #expect(throws: FileStoreError.tooLarge) { try store.read("a", maximumBytes: 9) }
        #expect(throws: FileStoreError.tooLarge) { try store.read("a", maximumBytes: -1) }
        #expect(try store.read("missing", maximumBytes: 1) == nil)
        store.isLocked = true
        #expect(throws: FileStoreError.unreadable) { try store.read("a", maximumBytes: 10) }
    }
}
