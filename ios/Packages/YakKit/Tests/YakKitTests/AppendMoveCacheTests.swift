import Foundation
import Testing

@testable import YakKit

private func temporaryRoot() -> URL {
    FileManager.default.temporaryDirectory.appendingPathComponent("append-\(UUID().uuidString)")
}

/// Implements only the original requirements, so the default append and move run.
private final class MinimalStore: FileStore, @unchecked Sendable {
    let inner = InMemoryFileStore()
    func read(_ path: String) throws(FileStoreError) -> Data? { try inner.read(path) }
    func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        try inner.write(data, to: path, protection: protection)
    }
    func remove(_ path: String) throws(FileStoreError) { try inner.remove(path) }
    func list(_ folder: String) throws(FileStoreError) -> [String] { try inner.list(folder) }
}

@Suite struct AppendMoveCacheTests {
    @Test func appendCreatesThenGrows() throws {
        let store = DiskFileStore(root: temporaryRoot())
        try store.append(Data("ab".utf8), to: "x/log", protection: .untilFirstUnlock)
        try store.append(Data("cd".utf8), to: "x/log", protection: .untilFirstUnlock)
        #expect(try store.read("x/log") == Data("abcd".utf8))
    }

    @Test func appendKeepsProtection() throws {
        let store = InMemoryFileStore()
        try store.append(Data("a".utf8), to: "log", protection: .complete)
        try store.append(Data("b".utf8), to: "log", protection: .untilFirstUnlock)
        #expect(store.protection(of: "log") == .complete)
        #expect(try store.read("log") == Data("ab".utf8))
    }

    @Test func moveReplacesTheDestination() throws {
        let store = DiskFileStore(root: temporaryRoot())
        try store.write(Data("new".utf8), to: "a", protection: .untilFirstUnlock)
        try store.write(Data("old".utf8), to: "deep/b", protection: .untilFirstUnlock)
        try store.move("a", to: "deep/b")
        #expect(try store.read("deep/b") == Data("new".utf8))
        #expect(try store.read("a") == nil)
        let memory = InMemoryFileStore()
        try memory.write(Data("new".utf8), to: "a", protection: .complete)
        try memory.move("a", to: "b")
        #expect(try memory.read("b") == Data("new".utf8))
        #expect(memory.protection(of: "b") == .complete)
        #expect(try memory.read("a") == nil)
    }

    @Test func moveOfAMissingFileThrows() {
        #expect(throws: FileStoreError.unreadable) { try DiskFileStore(root: temporaryRoot()).move("a", to: "b") }
        #expect(throws: FileStoreError.unreadable) { try InMemoryFileStore().move("a", to: "b") }
        #expect(throws: FileStoreError.unreadable) { try MinimalStore().move("a", to: "b") }
    }

    @Test func fileURLStaysInsideTheRoot() throws {
        let root = temporaryRoot()
        let store = DiskFileStore(root: root)
        #expect(try store.fileURL("a/b").path == root.appendingPathComponent("a/b").path)
        #expect(throws: FileStoreError.invalidPath) { try store.fileURL("../x") }
        #expect(throws: FileStoreError.invalidPath) { try store.fileURL("/x") }
        #expect(throws: FileStoreError.invalidPath) { try store.append(Data(), to: "../x", protection: .complete) }
        #expect(throws: FileStoreError.invalidPath) { try store.move("a", to: "/x") }
    }

    @Test func defaultImplementationsWork() throws {
        let store = MinimalStore()
        try store.append(Data("a".utf8), to: "f", protection: .untilFirstUnlock)
        try store.append(Data("b".utf8), to: "f", protection: .untilFirstUnlock)
        try store.move("f", to: "g")
        #expect(try store.read("g", maximumBytes: 2) == Data("ab".utf8))
        #expect(try store.read("f") == nil)
        #expect(throws: FileStoreError.tooLarge) { try store.read("g", maximumBytes: 1) }
    }
}
