import Foundation
import Testing
import UIKit
import YakKit

@testable import Yak

private final class Routed: @unchecked Sendable {
    private let lock = NSLock()
    private var seen: [UploadTaskDescription] = []
    func note(_ description: UploadTaskDescription) { lock.withLock { seen.append(description) } }
    var descriptions: [UploadTaskDescription] { lock.withLock { seen } }
}

private func makeFiles() -> DiskFileStore {
    DiskFileStore(root: FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString))
}

@MainActor
struct UploadSessionsTests {
    @Test func theConfigurationKeepsUploadsAlive() {
        let configuration = BackgroundUploadSession.configuration(
            identifier: "com.example.yak.upload.x", appGroup: "group.com.example.yak")
        #expect(configuration.identifier == "com.example.yak.upload.x")
        #expect(configuration.sharedContainerIdentifier == "group.com.example.yak")
        #expect(configuration.sessionSendsLaunchEvents)
        #expect(!configuration.isDiscretionary)
        #expect(configuration.timeoutIntervalForResource == 86_400)
        #expect(configuration.timeoutIntervalForRequest == 60)
        #expect(configuration.httpCookieStorage == nil)
        #expect(configuration.urlCache == nil)
    }

    @Test func eventsReachTheRouteInOrderAndGarbageReachesNothing() async throws {
        let routed = Routed()
        let session = BackgroundUploadSession(
            identifier: "com.example.yak.upload.\(UUID().uuidString)", appGroup: nil, files: makeFiles()
        ) { description in
            routed.note(description)
            return nil
        }
        let plain = URLSession(configuration: .ephemeral)
        let good = UploadTaskDescription(item: UUID(), attempt: 1)
        let request = URLRequest(url: URL(string: "https://example.com/x")!)
        let task = plain.dataTask(with: request)
        task.taskDescription = good.text
        session.urlSession(plain, task: task, didSendBodyData: 1, totalBytesSent: 1, totalBytesExpectedToSend: 2)
        session.urlSession(plain, task: task, didCompleteWithError: URLError(.notConnectedToInternet))
        let garbage = plain.dataTask(with: request)
        garbage.taskDescription = "garbage"
        session.urlSession(plain, task: garbage, didCompleteWithError: nil)
        let none = plain.dataTask(with: request)
        session.urlSession(plain, task: none, didCompleteWithError: nil)
        let done = Flag()
        session.finishedEvents = { done.fire() }
        session.urlSessionDidFinishEvents(forBackgroundURLSession: plain)
        for _ in 0..<200 where !done.hasFired { try await Task.sleep(for: .milliseconds(25)) }
        #expect(done.hasFired)
        #expect(routed.descriptions == [good, good])
        session.cancelAll()
    }

    @Test func theShareIdentifierIsHandledByTheApp() async throws {
        let rig = try await Rig()
        let identifier = UploadSessionID.share(invocation: UUID()).identifier(appBundleID: "com.example.yak")
        let done = Counter()
        rig.sessions.handleEvents(identifier: identifier) { done.add() }
        let session = try #require(rig.sessions.shareSession(identifier))
        session.urlSessionDidFinishEvents(forBackgroundURLSession: URLSession(configuration: .ephemeral))
        for _ in 0..<200 where done.count == 0 { try await Task.sleep(for: .milliseconds(25)) }
        #expect(done.count == 1)
        for _ in 0..<200 where rig.sessions.shareSessionCount > 0 { try await Task.sleep(for: .milliseconds(25)) }
        #expect(rig.sessions.shareSession(identifier) == nil)
        // An identifier that does not parse is completed at once.
        let garbage = Counter()
        rig.sessions.handleEvents(identifier: "com.example.yak.share.upload.nope") { garbage.add() }
        for _ in 0..<200 where garbage.count == 0 { try await Task.sleep(for: .milliseconds(25)) }
        #expect(garbage.count == 1)
    }

    @Test func aBackgroundRelaunchWithNoSceneReachesTheSessionsAndCompletesOnce() async throws {
        let rig = try await Rig()
        PushAppDelegate.register(launchUploads: rig.sessions)
        defer { PushAppDelegate.register(launchUploads: nil) }
        let delegate = PushAppDelegate()
        let identifier = UploadSessionID.app(instance: rig.first.id).identifier(appBundleID: "com.example.yak")
        let done = Counter()
        delegate.application(UIApplication.shared, handleEventsForBackgroundURLSession: identifier) { done.add() }
        let session = try #require(rig.sessions.link(for: rig.first.id).session as? BackgroundUploadSession)
        let plain = URLSession(configuration: .ephemeral)
        session.urlSessionDidFinishEvents(forBackgroundURLSession: plain)
        session.urlSessionDidFinishEvents(forBackgroundURLSession: plain)
        for _ in 0..<200 where done.count == 0 { try await Task.sleep(for: .milliseconds(25)) }
        try await Task.sleep(for: .milliseconds(200))
        #expect(done.count == 1)
    }

    @Test func shareEventsGoOnlyToTheLedgerThatOwnsTheItem() async throws {
        let rig = try await Rig()
        let owner = rig.sessions.link(for: rig.first.id).ledger
        let other = rig.sessions.link(for: rig.second.id).ledger
        let item = try rig.preparedItem(instance: rig.first.id)
        _ = try await owner.prepare(item, origin: .share)
        let client = APIClient(
            baseURL: URL(string: "http://127.0.0.1:9")!, token: "test-token", transport: URLSessionTransport(),
            appVersion: "1", systemDescription: "test")
        _ = await owner.start(item.id, session: StubSession(), client: client)
        let identifier = UploadSessionID.share(invocation: UUID()).identifier(appBundleID: "com.example.yak")
        rig.sessions.handleEvents(identifier: identifier) {}
        let session = try #require(rig.sessions.shareSession(identifier))
        let plain = URLSession(configuration: .ephemeral)
        let task = plain.dataTask(with: URLRequest(url: URL(string: "http://127.0.0.1:9/x")!))
        task.taskDescription = UploadTaskDescription(item: item.id, attempt: 1).text
        session.urlSession(plain, task: task, didCompleteWithError: URLError(.networkConnectionLost))
        for _ in 0..<200 {
            if await owner.record(for: item.id)?.phase == .failed(.transport) { break }
            try await Task.sleep(for: .milliseconds(25))
        }
        #expect(await owner.record(for: item.id)?.phase == .failed(.transport))
        #expect(await other.record(for: item.id) == nil)
    }

    @Test func noShareSessionIsMadeOutsideHandleEvents() async throws {
        let rig = try await Rig()
        _ = rig.sessions.link(for: rig.first.id)
        await rig.sessions.retryFailed(budget: .zero)
        #expect(rig.sessions.shareSessionCount == 0)
    }

    @Test func removalCancelsTheSession() async throws {
        let rig = try await Rig()
        let before = rig.sessions.link(for: rig.first.id).session as AnyObject
        await rig.sessions.instanceRemoved(rig.first, reason: .unpaired)
        let after = rig.sessions.link(for: rig.first.id).session as AnyObject
        #expect(before !== after)
    }

    @Test func aSpentBudgetStartsNothing() async throws {
        let rig = try await Rig()
        let ledger = rig.sessions.link(for: rig.first.id).ledger
        let item = try rig.preparedItem(instance: rig.first.id)
        _ = try await ledger.prepare(item, origin: .app)
        let client = APIClient(
            baseURL: URL(string: "http://127.0.0.1:9")!, token: "test-token", transport: URLSessionTransport(),
            appVersion: "1", systemDescription: "test")
        _ = await ledger.start(item.id, session: StubSession(), client: client)
        let record = try #require(await ledger.record(for: item.id))
        await ledger.completed(
            UploadTaskDescription(item: item.id, attempt: record.attempts), session: "stub", task: 1, status: nil,
            headers: [:], failed: true)
        #expect(await ledger.record(for: item.id)?.phase == .failed(.transport))
        await rig.sessions.retryFailed(budget: .zero)
        #expect(await ledger.record(for: item.id)?.phase == .failed(.transport))
    }

    @Test func oneInstanceAlwaysGetsTheSameLink() {
        let instances = InstanceStore(
            defaults: UserDefaults(suiteName: UUID().uuidString)!, tokens: InMemoryTokenStore(), now: { Date() },
            build: nil)
        let sessions = UploadSessions(
            appBundleID: "com.example.yak", appGroup: nil, files: makeFiles(), clock: SystemClock(),
            instances: instances, tokens: InMemoryTokenStore(), appVersion: "1", systemDescription: "test")
        let instance = UUID()
        #expect(sessions.link(for: instance).session.identifier == sessions.link(for: instance).session.identifier)
    }
}

private final class Counter: @unchecked Sendable {
    private let lock = NSLock()
    private var value = 0
    func add() { lock.withLock { value += 1 } }
    var count: Int { lock.withLock { value } }
}

private struct StubSession: UploadSession {
    let identifier = "stub"
    func start(_ request: URLRequest, bodyPath: String, description: UploadTaskDescription)
        async throws(UploadStartProblem) -> Int
    { 1 }
    func liveTasks() async -> Set<UploadTaskDescription> { [] }
}

@MainActor
private struct Rig {
    let files = makeFiles()
    let sessions: UploadSessions
    let first: Instance
    let second: Instance

    init() async throws {
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(
            defaults: UserDefaults(suiteName: UUID().uuidString)!, tokens: tokens, now: { Date() }, build: nil)
        first = try await instances.add(
            url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
            token: "test-token")
        second = try await instances.add(
            url: URL(string: "https://other.example.com")!, name: "Other Yak", userName: "Test person",
            token: "test-token")
        sessions = UploadSessions(
            appBundleID: "com.example.yak", appGroup: nil, files: files, clock: SystemClock(), instances: instances,
            tokens: tokens, appVersion: "1", systemDescription: "test")
    }

    func preparedItem(instance: UUID) throws -> OutboxItem {
        let id = UUID()
        let path = OutboxInbox.attachmentPath(instance: instance, item: id, fileName: "a.jpg")
        try files.write(Data("a".utf8), to: path, protection: .untilFirstUnlock)
        let draft = OutboxDraft(
            target: .thread("d0e515ef"), text: "Look",
            attachments: [OutboxAttachment(reference: "Image #1", fileName: "a.jpg", contentType: "image/jpeg", path: path)])
        return OutboxInbox.item(for: draft, id: id, now: Date())
    }
}

private final class Flag: @unchecked Sendable {
    private let lock = NSLock()
    private var fired = false
    func fire() { lock.withLock { fired = true } }
    var hasFired: Bool { lock.withLock { fired } }
}
