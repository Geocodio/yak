import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let threadID = "d0e515ef"

private func makeCache() -> (Cache, InMemoryFileStore, UUID) {
    let files = InMemoryFileStore()
    let id = UUID()
    return (Cache(instance: id, files: files, clock: ManualClock()), files, id)
}

private final class DraftDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private let attachment = OutboxAttachment(
    reference: "Image #1", fileName: "photo.jpg", contentType: "image/jpeg",
    path: "instances/x/outbox/files/y/photo.jpg")

struct ComposerDraftTests {
    @Test func composerDraftsRoundTripAndAreErased() async throws {
        let (cache, files, id) = makeCache()
        let draft = ComposerDraft(text: "Half a thought", model: "sonnet", sendKey: UUID(), attachments: [attachment])
        let newThread = ComposerDraft(
            text: "Fix it", newThread: NewThreadDraft(repo: "example/api", mode: "fix"))
        await cache.saveComposerDraft(draft, for: .thread(threadID))
        await cache.saveComposerDraft(newThread, for: .newThread)
        #expect(await cache.composerDraft(.thread(threadID)) == draft)
        #expect(await cache.composerDraft(.newThread) == newThread)
        #expect(files.protection(of: "instances/\(id)/cache/threads/\(threadID)/composer.json") == .complete)
        #expect(files.protection(of: "instances/\(id)/cache/new-thread.json") == .complete)
        await cache.saveComposerDraft(ComposerDraft(text: "  "), for: .thread(threadID))
        #expect(await cache.composerDraft(.thread(threadID)) == nil)
        await cache.saveComposerDraft(draft, for: .thread(threadID))
        await cache.saveComposerDraft(nil, for: .thread(threadID))
        #expect(await cache.composerDraft(.thread(threadID)) == nil)
        await cache.saveComposerDraft(draft, for: .thread(threadID))
        files.isLocked = true
        #expect(await cache.composerDraft(.thread(threadID)) == nil)
        files.isLocked = false
        await cache.eraseAll()
        #expect(await cache.composerDraft(.thread(threadID)) == nil)
        #expect(await cache.composerDraft(.newThread) == nil)
    }

    @Test func aDraftLeavesWithItsThread() async throws {
        let (cache, files, id) = makeCache()
        await cache.saveComposerDraft(ComposerDraft(text: "Later"), for: .thread(threadID))
        await cache.removeThread(threadID)
        #expect(try files.read("instances/\(id)/cache/threads/\(threadID)/composer.json") == nil)
    }

    @Test func emptyMeansNothingWorthKeeping() {
        #expect(ComposerDraft().isEmpty)
        #expect(ComposerDraft(text: " \n").isEmpty)
        #expect(!ComposerDraft(model: "sonnet").isEmpty)
        #expect(!ComposerDraft(attachments: [attachment]).isEmpty)
    }

    @Test func anOutboxItemTakesTheDraftsSendKey() async throws {
        let outbox = Outbox(
            instance: UUID(),
            client: APIClient(
                baseURL: URL(string: "https://example.test")!, token: "test-token", transport: ScriptedTransport(),
                appVersion: "1.0", systemDescription: "Test phone"),
            files: InMemoryFileStore(), clock: ManualClock(), makeID: IDSequence().next)
        let key = UUID()
        let first = try await outbox.enqueue(OutboxDraft(target: .thread(threadID), text: "Hi", sendKey: key))
        #expect(first.id == key)
        let second = try await outbox.enqueue(OutboxDraft(target: .thread(threadID), text: "Hi", sendKey: key))
        #expect(second.id == key)
        #expect(await outbox.items().count == 1)
    }

    @Test func storedAttachmentsLeaveWithTheDelivery() async throws {
        let clock = ManualClock()
        let files = InMemoryFileStore()
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: DraftDefaults(), tokens: tokens, now: { clock.now() })
        let instance = try await instances.add(
            url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test person", token: "test-token"
        )
        let transport = ScriptedTransport()
        await transport.on("GET me", try .scenario(200, "scenario-me"))
        await transport.on("POST threads/\(threadID)/messages", try .scenario(200, "scenario-message-sent"))
        let ids = IDSequence()
        let session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens,
            environment: SessionEnvironment(
                http: transport, webSockets: FakeWebSocketServer(), files: files, clock: clock,
                makeID: { ids.next() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false))
        await session.start()
        let key = UUID()
        let stored = try await session.storeAttachment(
            Data("bytes".utf8), fileName: "photo.jpg", contentType: "image/jpeg", reference: "Image #1", sendKey: key)
        let folder = "\(InstanceFolders.outbox(instance.id))/files/\(key.uuidString)"
        #expect(try files.list(folder) == ["photo.jpg"])
        #expect(files.protection(of: stored.path) == .untilFirstUnlock)
        let outbox = try #require(await session.outbox())
        let deliveries = StreamRecorder(await outbox.deliveries())
        _ = try await session.send(
            OutboxDraft(target: .thread(threadID), text: "See photo", attachments: [stored], sendKey: key))
        _ = try await deliveries.next { _ in true }
        for _ in 0..<100 { await Task.yield() }
        #expect(try files.list(folder) == [])
        await session.stop()
    }

    @Test func discardingAnAttachmentRemovesItsFile() async throws {
        let files = InMemoryFileStore()
        let clock = ManualClock()
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: DraftDefaults(), tokens: tokens, now: { clock.now() })
        let instance = try await instances.add(
            url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test person", token: "test-token"
        )
        let ids = IDSequence()
        let session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens,
            environment: SessionEnvironment(
                http: ScriptedTransport(), webSockets: FakeWebSocketServer(), files: files, clock: clock,
                makeID: { ids.next() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false))
        let stored = try await session.storeAttachment(
            Data("bytes".utf8), fileName: "a b.pdf", contentType: "application/pdf", reference: "File #1",
            sendKey: UUID())
        #expect(stored.fileName == "a b.pdf" && stored.path.hasSuffix("/a-b.pdf"))
        await session.discardAttachment(stored)
        #expect(try files.read(stored.path) == nil)
        await session.saveComposerDraft(ComposerDraft(text: "Offline words"), for: .newThread)
        #expect(await session.composerDraft(.newThread)?.text == "Offline words")
    }

    @Test func twoAttachmentsWithOneNameKeepTheirOwnFiles() async throws {
        let files = InMemoryFileStore()
        let clock = ManualClock()
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: DraftDefaults(), tokens: tokens, now: { clock.now() })
        let instance = try await instances.add(
            url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test person", token: "test-token"
        )
        let ids = IDSequence()
        let session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens,
            environment: SessionEnvironment(
                http: ScriptedTransport(), webSockets: FakeWebSocketServer(), files: files, clock: clock,
                makeID: { ids.next() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false))
        let key = UUID()
        let first = try await session.storeAttachment(
            Data("one".utf8), fileName: "photo.jpg", contentType: "image/jpeg", reference: "Image #1", sendKey: key)
        let second = try await session.storeAttachment(
            Data("two".utf8), fileName: "photo.jpg", contentType: "image/jpeg", reference: "Image #2", sendKey: key)
        #expect(first.path != second.path)
        #expect(second.path.hasSuffix("/photo-2.jpg") && second.fileName == "photo.jpg")
        #expect(try files.read(first.path) == Data("one".utf8))
        #expect(try files.read(second.path) == Data("two".utf8))
        await session.discardAttachment(first)
        #expect(try files.read(first.path) == nil)
        #expect(try files.read(second.path) == Data("two".utf8))
    }

    @Test func buildsTheRepositoryAccessRequest() async throws {
        let transport = ScriptedTransport()
        await transport.on("GET threads/new/repository-access", try .scenario(200, "scenario-thread-repository-access"))
        let client = APIClient(
            baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
            appVersion: "1.0", systemDescription: "Test phone")
        let access = try await client.send(.repositoryAccess(repo: "example/web"))
        #expect(access == RepositoryAccess(allowed: true, reason: nil))
        let request = try #require(await transport.requests(on: "GET threads/new/repository-access").first)
        #expect(request.url?.absoluteString.hasSuffix("threads/new/repository-access?repo=example%2Fweb") == true)
    }
}
