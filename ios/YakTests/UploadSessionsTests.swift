import Foundation
import Testing
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
        let instances = InstanceStore(
            defaults: UserDefaults(suiteName: UUID().uuidString)!, tokens: InMemoryTokenStore(), now: { Date() },
            build: nil)
        let sessions = UploadSessions(
            appBundleID: "com.example.yak", appGroup: nil, files: makeFiles(), clock: SystemClock(),
            instances: instances, tokens: InMemoryTokenStore(), appVersion: "1", systemDescription: "test")
        let link = sessions.link(for: UUID())
        #expect(link.session.identifier.hasPrefix("com.example.yak.upload."))
        #expect(sessions.link(for: UUID()).session.identifier != link.session.identifier)
        // An identifier that does not parse is completed at once.
        let done = Flag()
        sessions.handleEvents(identifier: "com.example.yak.share.upload.nope") { done.fire() }
        for _ in 0..<200 where !done.hasFired { try await Task.sleep(for: .milliseconds(25)) }
        #expect(done.hasFired)
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

private final class Flag: @unchecked Sendable {
    private let lock = NSLock()
    private var fired = false
    func fire() { lock.withLock { fired = true } }
    var hasFired: Bool { lock.withLock { fired } }
}
