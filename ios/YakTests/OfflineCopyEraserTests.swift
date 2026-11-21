import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor @Suite struct OfflineCopyEraserTests {
    private let instance = Instance(
        id: UUID(), url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
        lastUsedAt: Date())

    @Test(arguments: [InstanceRemoval.unpaired, InstanceRemoval.revoked])
    func anUnpairedOrRevokedInstanceHasItsCopiesErased(reason: InstanceRemoval) async {
        var erased: [UUID] = []
        let eraser = OfflineCopyEraser { erased.append($0.id) }
        await eraser.instanceRemoved(instance, reason: reason)
        #expect(erased == [instance.id])
    }

    @Test func aPairedAndRefreshedInstanceKeepsItsCopies() async {
        var erased: [UUID] = []
        let eraser = OfflineCopyEraser { erased.append($0.id) }
        await eraser.instancePaired(instance)
        await eraser.instanceRefreshed(instance)
        #expect(erased.isEmpty)
    }

    @Test func theErasePathReachesTheSessionsStore() async throws {
        let files = InMemoryFileStore()
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: PushMemoryDefaults(), tokens: tokens, now: { Date() })
        let session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens,
            environment: .app(version: "0.0.0", system: "Unit test", files: files, allowsInsecureRealtime: false))
        let store = await session.offlineCopies()
        #expect(await store.save(Data(repeating: 1, count: 10), thread: "d0e515ef") == .saved)
        let eraser = OfflineCopyEraser { _ in await session.eraseOfflineCopies() }
        await eraser.instanceRemoved(instance, reason: .revoked)
        #expect(await store.entries().isEmpty)
        #expect(try files.list(InstanceFolders.offlineCopies(instance.id)).isEmpty)
    }
}
