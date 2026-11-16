import Foundation
import Testing
import YakKit

@testable import Yak

private func json(_ object: [String: Any]) throws -> Data { try JSONSerialization.data(withJSONObject: object) }

private final class Flags: @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Bool] = [:]
    func set(_ key: String, _ value: Bool = true) { lock.withLock { values[key] = value } }
    func get(_ key: String) -> Bool { lock.withLock { values[key] ?? false } }
}

/// The thread `d0e515ef` with changes, its diff and files, and the Open PR route, as the scenario data has them.
private func routes(_ flags: Flags, post: (@Sendable () throws -> (status: Int, body: Data)?)? = nil)
    -> ScriptedTransport.Answer
{
    { route, _, _ in
        switch route {
        case "GET threads/d0e515ef":
            var show = ScenarioFile.object("scenario-thread-with-changes")
            if flags.get("opened") {
                let opened = ScenarioFile.object("scenario-thread-with-pull-request")
                for key in ["canOpenPullRequest", "pullRequest"] { show[key] = opened[key] }
            }
            if flags.get("private") { show["publishWarning"] = true }
            return (200, try json(show))
        case "GET threads/d0e515ef/events":
            return (200, Data(#"{"events":[],"latestSequence":14,"source":"database"}"#.utf8))
        case "GET threads/d0e515ef/diff":
            return (200, ScenarioFile.data("scenario-thread-diff"))
        case "POST threads/d0e515ef/pull-request":
            if let post { return try post() }
            flags.set("opened")
            return (202, Data(#"{"status":"opening"}"#.utf8))
        default:
            if route.hasPrefix("GET threads/d0e515ef/diff/files/") {
                return (200, ScenarioFile.data("scenario-diff-file"))
            }
            return nil
        }
    }
}

@MainActor private func model(_ fixture: TasksFixture, restoring key: DiffFileKey? = nil) async -> (
    ThreadModel, FilesModel
) {
    let thread = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await thread.start(initialTitle: nil)
    return (thread, FilesModel(thread: thread, restoring: key))
}

@MainActor @Test func fileCountLabelPluralizes() {
    #expect(FilesModel.countLabel(0) == "0 files")
    #expect(FilesModel.countLabel(1) == "1 file")
    #expect(FilesModel.countLabel(4) == "4 files")
}

@MainActor @Test func startOpensOnceAndStopCloses() async throws {
    let fixture = try await TasksFixture(answer: routes(Flags()))
    let (thread, files) = await model(fixture)
    await files.start()
    await files.start()
    #expect(await fixture.eventually { files.snapshot?.load == .ready && files.files.count == 4 })
    #expect(fixture.transport.count { $0 == "GET threads/d0e515ef/diff" } == 1)
    await files.stop()
    await thread.stop()
    await fixture.session.stop()
}

@MainActor @Test func theRestoredFileIsShown() async throws {
    let fixture = try await TasksFixture(answer: routes(Flags()))
    let key = DiffFileKey(repo: "example/web", path: "src/uploads.ts")
    let (thread, files) = await model(fixture, restoring: key)
    await files.start()
    #expect(await fixture.eventually { files.currentKey == key })
    await files.stop()
    await thread.stop()
    await fixture.session.stop()
}

@MainActor @Test func openPullRequestFollowsTheShow() async throws {
    let flags = Flags()
    let fixture = try await TasksFixture(answer: routes(flags))
    let (thread, files) = await model(fixture)
    #expect(await fixture.eventually { files.openPullRequest == .available(needsConfirmation: false) })
    await files.start()
    await files.tapOpenPullRequest()
    #expect(fixture.transport.count { $0 == "POST threads/d0e515ef/pull-request" } == 1)
    #expect(await fixture.eventually { if case .open(41, _) = files.openPullRequest { true } else { false } })
    await files.stop()
    await thread.stop()
    await fixture.session.stop()
}

@MainActor @Test func aPrivateThreadConfirmsFirst() async throws {
    let flags = Flags()
    flags.set("private")
    let fixture = try await TasksFixture(answer: routes(flags))
    let (thread, files) = await model(fixture)
    #expect(await fixture.eventually { files.openPullRequest == .available(needsConfirmation: true) })
    await files.start()
    await files.tapOpenPullRequest()
    #expect(files.isConfirmingPrivatePublish)
    #expect(fixture.transport.count { $0.hasSuffix("/pull-request") } == 0)
    await files.confirmPrivatePublish()
    #expect(!files.isConfirmingPrivatePublish)
    #expect(fixture.transport.count { $0 == "POST threads/d0e515ef/pull-request" } == 1)
    await files.stop()
    await thread.stop()
    await fixture.session.stop()
}

@MainActor @Test func aTimeoutRefreshesAndDoesNotResend() async throws {
    let fixture = try await TasksFixture(answer: routes(Flags(), post: { throw URLError(.timedOut) }))
    let (thread, files) = await model(fixture)
    #expect(await fixture.eventually { files.openPullRequest == .available(needsConfirmation: false) })
    await files.start()
    let reads = fixture.transport.count { $0 == "GET threads/d0e515ef" }
    await files.tapOpenPullRequest()
    #expect(fixture.transport.count { $0 == "POST threads/d0e515ef/pull-request" } == 1)
    #expect(await fixture.eventually { fixture.transport.count { $0 == "GET threads/d0e515ef" } > reads })
    #expect(files.openPullRequest != .sending)
    #expect(files.openNotice != nil)
    await files.stop()
    await thread.stop()
    await fixture.session.stop()
}

@MainActor @Test func aRefusalShowsItsMessage() async throws {
    let refusal = errorBody("nothing_to_publish", "This thread has no changes to publish yet.")
    let fixture = try await TasksFixture(answer: routes(Flags(), post: { (409, refusal) }))
    let (thread, files) = await model(fixture)
    #expect(await fixture.eventually { files.openPullRequest == .available(needsConfirmation: false) })
    await files.start()
    await files.tapOpenPullRequest()
    #expect(files.openNotice == "This thread has no changes to publish yet.")
    await files.stop()
    await thread.stop()
    await fixture.session.stop()
}
