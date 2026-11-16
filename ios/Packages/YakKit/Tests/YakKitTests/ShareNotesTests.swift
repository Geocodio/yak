import Foundation
import Testing

@testable import YakKit

@Test func notesWriteTheContext() async throws {
    let clock = ManualClock()
    let files = InMemoryFileStore()
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: NotesDefaults(), tokens: tokens, now: { clock.now() })
    let instance = try await instances.add(
        url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test person", token: "test-token")
    let transport = ScriptedTransport()
    await transport.on("GET me", try .scenario(200, "scenario-me"))
    let privateAnswer = try FakeTransport.Result.scenario(200, "scenario-broadcasting-auth-private")
    await transport.on(prefix: "POST broadcasting/auth") { _ in privateAnswer }
    let ids = IDSequence()
    let environment = SessionEnvironment(
        http: transport, webSockets: FakeWebSocketServer(), files: files, clock: clock, makeID: { ids.next() },
        appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
    let session = InstanceSession(instance: instance, instances: instances, tokens: tokens, environment: environment)
    await session.start()
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await session.noteOpenedThread(show)
    let options = try decoded(NewThreadOptions.self, "scenario-thread-new")
    await session.noteNewThreadOptions(options, lastRepo: "example/api")
    let context = try #require(ShareContextStore.read(instance: instance.id, files: files))
    #expect(context.lastThread?.id == show.thread.id && context.options == options && context.lastRepo == "example/api")
    #expect(context.limits == ShareLimits(maxFiles: 8, maxFileBytes: 10_485_760))
    #expect(files.protection(of: InstanceFolders.shareContext(instance.id)) == .untilFirstUnlock)
}

private final class NotesDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}
