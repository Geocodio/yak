import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor @Suite struct OfflineCopyEraserTests {
    private let instance = Instance(
        id: UUID(), url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
        lastUsedAt: Date())

    /// No session and no window exist here: the eraser works on the files alone.
    @Test(arguments: [InstanceRemoval.unpaired, InstanceRemoval.revoked])
    func anUnpairedOrRevokedInstanceHasItsCopiesErasedWithoutASession(reason: InstanceRemoval) async throws {
        let files = InMemoryFileStore()
        let store = OfflineCopyStore(instance: instance.id, files: files)
        #expect(await store.save(Data(repeating: 1, count: 10), thread: "d0e515ef") == .saved)
        let other = OfflineCopyStore(instance: UUID(), files: files)
        #expect(await other.save(Data(repeating: 1, count: 10), thread: "d0e515ef") == .saved)
        await OfflineCopyEraser(files: files).instanceRemoved(instance, reason: reason)
        #expect(try files.list(InstanceFolders.offlineCopies(instance.id)).isEmpty)
        #expect(await other.entries().count == 1)
    }

    @Test func aPairedAndRefreshedInstanceKeepsItsCopies() async throws {
        let files = InMemoryFileStore()
        let store = OfflineCopyStore(instance: instance.id, files: files)
        _ = await store.save(Data(repeating: 1, count: 10), thread: "d0e515ef")
        let eraser = OfflineCopyEraser(files: files)
        await eraser.instancePaired(instance)
        await eraser.instanceRefreshed(instance)
        #expect(await store.entries().count == 1)
    }
}
