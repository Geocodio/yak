import Foundation
import Testing

@testable import YakKit

private struct NotesRig {
    let session: InstanceSession
    let instance: Instance
    let files: InMemoryFileStore
    let transport: ScriptedTransport
}

private func notesRig(answerMe: Bool = true) async throws -> NotesRig {
    let clock = ManualClock()
    let files = InMemoryFileStore()
    let tokens = InMemoryTokenStore()
    let instances = InstanceStore(defaults: InMemoryKeyValueStore(), tokens: tokens, now: { clock.now() })
    let instance = try await instances.add(
        url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test person", token: "test-token")
    let transport = ScriptedTransport()
    if answerMe { await transport.on("GET me", try .scenario(200, "scenario-me")) }
    let privateAnswer = try FakeTransport.Result.scenario(200, "scenario-broadcasting-auth-private")
    await transport.on(prefix: "POST broadcasting/auth") { _ in privateAnswer }
    let ids = IDSequence()
    let environment = SessionEnvironment(
        http: transport, webSockets: FakeWebSocketServer(), files: files, clock: clock, makeID: { ids.next() },
        appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
    let session = InstanceSession(instance: instance, instances: instances, tokens: tokens, environment: environment)
    if answerMe { await session.start() }
    return NotesRig(session: session, instance: instance, files: files, transport: transport)
}

@Test func notesWriteTheContext() async throws {
    let rig = try await notesRig()
    let (session, instance, files) = (rig.session, rig.instance, rig.files)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await session.noteOpenedThread(show)
    let options = try decoded(NewThreadOptions.self, "scenario-thread-new")
    await session.noteNewThreadOptions(options, lastRepo: "example/api")
    let context = try #require(ShareContextStore.read(instance: instance.id, files: files))
    #expect(context.lastThread?.id == show.thread.id && context.options == options && context.lastRepo == "example/api")
    #expect(context.limits == ShareLimits(maxFiles: 8, maxFileBytes: 10_485_760))
    #expect(files.protection(of: InstanceFolders.shareContext(instance.id)) == .untilFirstUnlock)
}

@Test func unpairingErasesTheContext() async throws {
    let rig = try await notesRig()
    await rig.session.noteOpenedThread(try decoded(ThreadShow.self, "scenario-thread-show"))
    #expect(ShareContextStore.read(instance: rig.instance.id, files: rig.files) != nil)
    await rig.session.unpair()
    #expect(ShareContextStore.read(instance: rig.instance.id, files: rig.files) == nil)
}

@Test func aNoteAfterUnpairingWritesNothing() async throws {
    let rig = try await notesRig()
    await rig.session.unpair()
    await rig.session.noteOpenedThread(try decoded(ThreadShow.self, "scenario-thread-show"))
    await rig.session.noteNewThreadOptions(
        try decoded(NewThreadOptions.self, "scenario-thread-new"), lastRepo: "example/api")
    #expect(ShareContextStore.read(instance: rig.instance.id, files: rig.files) == nil)
}

@Test func aNoteAfterRevocationWritesNothing() async throws {
    let rig = try await notesRig()
    await rig.session.handle(.unauthorized(code: "device_revoked"))
    await rig.session.noteOpenedThread(try decoded(ThreadShow.self, "scenario-thread-show"))
    #expect(ShareContextStore.read(instance: rig.instance.id, files: rig.files) == nil)
}

@Test func aNoteBeforeTheFirstMeWritesNothing() async throws {
    let rig = try await notesRig(answerMe: false)
    await rig.session.noteOpenedThread(try decoded(ThreadShow.self, "scenario-thread-show"))
    #expect(ShareContextStore.read(instance: rig.instance.id, files: rig.files) == nil)
}

@Test func aNoteAfterTheSessionEndsWritesNothing() async throws {
    let rig = try await notesRig()
    await rig.session.handle(.serverTooOld)
    await rig.session.noteOpenedThread(try decoded(ThreadShow.self, "scenario-thread-show"))
    #expect(ShareContextStore.read(instance: rig.instance.id, files: rig.files) == nil)
}

@Test func aNoteWhileUnpairingIsSuspendedWritesNothing() async throws {
    let rig = try await notesRig()
    await rig.transport.hold("DELETE device")
    let unpairing = Task { await rig.session.unpair() }
    await rig.transport.waitForRequests(1, on: "DELETE device")
    await rig.session.noteOpenedThread(try decoded(ThreadShow.self, "scenario-thread-show"))
    #expect(ShareContextStore.read(instance: rig.instance.id, files: rig.files) == nil)
    await rig.transport.release("DELETE device")
    await unpairing.value
}

@Test func aRevokeErasesWhatWasNoted() async throws {
    let rig = try await notesRig()
    await rig.session.noteOpenedThread(try decoded(ThreadShow.self, "scenario-thread-show"))
    await rig.session.noteNewThreadOptions(
        try decoded(NewThreadOptions.self, "scenario-thread-new"), lastRepo: "example/api")
    #expect(ShareContextStore.read(instance: rig.instance.id, files: rig.files) != nil)
    await rig.session.handle(.unauthorized(code: "device_revoked"))
    #expect(ShareContextStore.read(instance: rig.instance.id, files: rig.files) == nil)
    #expect(try rig.files.list(InstanceFolders.cache(rig.instance.id)).isEmpty)
}

@Test func unpairingLeavesNothingInTheInstanceFolder() async throws {
    let rig = try await notesRig()
    await rig.session.noteOpenedThread(try decoded(ThreadShow.self, "scenario-thread-show"))
    await rig.session.unpair()
    #expect(try rig.files.list("instances/\(rig.instance.id.uuidString)").isEmpty)
}
