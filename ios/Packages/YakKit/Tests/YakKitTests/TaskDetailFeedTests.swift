import Foundation
import Testing

@testable import YakKit

private let taskID = "k3m9p2qa"
private let showRoute = "GET tasks/\(taskID)"

private final class CountingFileStore: FileStore, @unchecked Sendable {
    private let inner = InMemoryFileStore()
    private let lock = NSLock()
    private var writes = 0
    var writeCount: Int { lock.withLock { writes } }
    func read(_ path: String) throws(FileStoreError) -> Data? { try inner.read(path) }
    func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        lock.withLock { writes += 1 }
        try inner.write(data, to: path, protection: protection)
    }
    func remove(_ path: String) throws(FileStoreError) { try inner.remove(path) }
    func list(_ folder: String) throws(FileStoreError) -> [String] { try inner.list(folder) }
}

private struct Rig {
    let feed: TaskDetailFeed
    let transport: ScriptedTransport
    let cache: Cache
    let clock: ManualClock
}

private func makeRig(
    askedID: String = taskID, cache existing: Cache? = nil, clock: ManualClock = ManualClock()
) async throws -> Rig {
    let transport = ScriptedTransport()
    await transport.on("GET tasks/\(askedID)", try .scenario(200, "scenario-task"))
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let cache = existing ?? Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    return Rig(
        feed: TaskDetailFeed(taskID: askedID, client: client, cache: cache, clock: clock),
        transport: transport, cache: cache, clock: clock)
}

@Suite struct TaskDetailFeedTests {
    @Test func aSavedCopyShowsFirstThenTheAnswer() async throws {
        let first = try await makeRig()
        await first.feed.open()
        #expect(await first.cache.task(taskID)?.task.id == taskID)

        let second = try await makeRig(cache: first.cache)
        await second.transport.hold(showRoute)
        let snapshots = StreamRecorder(await second.feed.snapshots())
        let opening = Task { await second.feed.open() }
        let cached = try await snapshots.next { $0.task != nil }
        #expect(cached.load == .ready)
        await second.transport.release(showRoute)
        await opening.value
    }

    @Test func aNotFoundRemovesTheCachedCopy() async throws {
        let rig = try await makeRig()
        let task = try decoded(AppTask.self, "scenario-task")
        await rig.cache.saveTask(task, id: taskID)
        await rig.cache.saveTaskQuestionDraft(QuestionDraft(roundKey: "r1"), id: taskID)
        await rig.transport.on(showRoute, .json(404, #"{"error":"not_found","message":"Gone."}"#))
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        await rig.feed.open()
        let cached = try await snapshots.next { $0.task != nil }
        #expect(cached.load == .ready)
        let gone = try await snapshots.next { $0.load == .failed(.notFound) }
        #expect(gone.task == nil)
        #expect(await rig.cache.task(taskID) == nil)
        #expect(await rig.cache.taskQuestionDraft(taskID) == nil)
    }

    @Test func aForbiddenAnswerHidesTheTaskToo() async throws {
        let rig = try await makeRig()
        await rig.cache.saveTask(try decoded(AppTask.self, "scenario-task"), id: taskID)
        await rig.transport.on(showRoute, .json(403, #"{"error":"forbidden","message":"No."}"#))
        await rig.feed.open()
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let latest = try await snapshots.next { _ in true }
        #expect(latest.load == .failed(.notFound) && latest.task == nil)
        #expect(await rig.cache.task(taskID) == nil)
    }

    @Test func aChildIDShowsItsRoot() async throws {
        let rig = try await makeRig(askedID: "c4h1d2ab")
        await rig.feed.open()
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let latest = try await snapshots.next { _ in true }
        #expect(latest.task?.task.id == taskID)
        #expect(await rig.cache.task(taskID) != nil)
        // The child id reaches the root's copy through the alias.
        #expect(await rig.cache.task("c4h1d2ab") == rig.cache.task(taskID))
    }

    @Test func aChildIDOfflineShowsTheRootsSavedCopy() async throws {
        let first = try await makeRig(askedID: "c4h1d2ab")
        await first.feed.open()
        let second = try await makeRig(askedID: "c4h1d2ab", cache: first.cache)
        await second.transport.on("GET tasks/c4h1d2ab", .failure(.notConnectedToInternet))
        await second.feed.open()
        let latest = try await StreamRecorder(await second.feed.snapshots()).next { _ in true }
        #expect(latest.task?.task.id == taskID && latest.load == .ready && latest.isOffline)
    }

    @Test func aRevokedChildIDRemovesTheRootsCopyAndDraft() async throws {
        let first = try await makeRig(askedID: "c4h1d2ab")
        await first.feed.open()
        await first.cache.saveTaskQuestionDraft(QuestionDraft(roundKey: "r1"), id: taskID)
        let second = try await makeRig(askedID: "c4h1d2ab", cache: first.cache)
        await second.transport.on("GET tasks/c4h1d2ab", .json(403, #"{"error":"forbidden","message":"No."}"#))
        await second.feed.open()
        #expect(await first.cache.task(taskID) == nil)
        #expect(await first.cache.taskQuestionDraft(taskID) == nil)
    }

    @Test func aDraftSavedUnderAChildIDLandsInTheRootFolder() async throws {
        let first = try await makeRig(askedID: "c4h1d2ab")
        await first.feed.open()
        // A feed that has not loaded yet still stores under the root the cache knows.
        let second = try await makeRig(askedID: "c4h1d2ab", cache: first.cache)
        let draft = QuestionDraft(roundKey: "r1")
        await second.feed.saveQuestionDraft(draft)
        #expect(await first.cache.taskQuestionDraft(taskID) == draft)
    }

    @Test func anUnchangedPollWritesNothing() async throws {
        let files = CountingFileStore()
        let clock = ManualClock()
        let rig = try await makeRig(cache: Cache(instance: UUID(), files: files, clock: clock), clock: clock)
        await rig.feed.open()
        let writesAfterOpen = files.writeCount
        #expect(writesAfterOpen > 0)
        await rig.feed.reload()
        await rig.feed.reload()
        #expect(files.writeCount == writesAfterOpen)
    }

    @Test func detailPollsOnlyWhileVisible() async throws {
        let rig = try await makeRig()
        await rig.feed.open()
        #expect(await rig.transport.requests(on: showRoute).count == 1)
        await rig.feed.setVisible(true)
        await rig.clock.waitForSleeper(endingAt: .seconds(5))
        await rig.clock.advance(by: .seconds(5))
        await rig.transport.waitForRequests(2, on: showRoute)
        await rig.clock.waitForSleeper(endingAt: .seconds(10))
        await rig.clock.advance(by: .seconds(5))
        await rig.transport.waitForRequests(3, on: showRoute)
        await rig.feed.setVisible(false)
        await rig.clock.advance(by: .seconds(30))
        #expect(await rig.transport.requests(on: showRoute).count == 3)
    }

    @Test func aFailedPollKeepsTheTaskAndSaysOffline() async throws {
        let rig = try await makeRig()
        await rig.feed.open()
        await rig.transport.on(showRoute, .failure(.notConnectedToInternet))
        await rig.feed.reload()
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        let latest = try await snapshots.next { _ in true }
        #expect(latest.isOffline && latest.task != nil && latest.load == .ready)
    }

    @Test func aFirstFailureIsTheErrorState() async throws {
        let rig = try await makeRig()
        await rig.transport.on(showRoute, .failure(.notConnectedToInternet))
        await rig.feed.open()
        let snapshots = StreamRecorder(await rig.feed.snapshots())
        #expect(try await snapshots.next { _ in true }.load == .failed(.offline))
    }

    @Test func taskAnswersSendTheSavedDraft() async throws {
        let rig = try await makeRig(askedID: "q5w8e2rt")
        await rig.transport.on("GET tasks/q5w8e2rt", try .scenario(200, "scenario-task-question"))
        let answersRoute = "POST tasks/q5w8e2rt/clarification-answers"
        await rig.transport.on(answersRoute, .json(200, #"{"status":"answered"}"#))
        await rig.feed.open()
        let round = try #require(try decoded(AppTask.self, "scenario-task-question").questionRound)
        // A draft for another round is not offered.
        await rig.feed.saveQuestionDraft(QuestionDraft(roundKey: "other"))
        #expect(await rig.feed.questionDraft() == nil)
        let draft = QuestionDraft(
            roundKey: round.roundKey,
            answers: [
                "scope": QuestionAnswer(choices: ["All exports"]), "window": QuestionAnswer(choices: ["One hour"]),
            ])
        await rig.feed.saveQuestionDraft(draft)
        #expect(await rig.feed.questionDraft() == draft)
        #expect(await rig.feed.answerQuestions() == .done("answered"))
        let body = String(
            decoding: try #require(await rig.transport.requests(on: answersRoute).first?.httpBody), as: UTF8.self)
        #expect(body.contains(#""note":null"#) && body.contains("One hour"))
        #expect(await rig.feed.questionDraft() == nil)
    }

    @Test func answeringWithoutADraftSendsNothing() async throws {
        let rig = try await makeRig(askedID: "q5w8e2rt")
        await rig.transport.on("GET tasks/q5w8e2rt", try .scenario(200, "scenario-task-question"))
        await rig.feed.open()
        guard case .refused(let code, _, _, _) = await rig.feed.answerQuestions() else {
            Issue.record("expected a refusal")
            return
        }
        #expect(code == "no_open_question")
        #expect(await rig.transport.requests(on: "POST tasks/q5w8e2rt/clarification-answers").isEmpty)
    }

    @Test func aNewRoundDropsTheOldDraft() async throws {
        let rig = try await makeRig()
        await rig.cache.saveTaskQuestionDraft(QuestionDraft(roundKey: "old-round"), id: taskID)
        await rig.feed.open()
        #expect(await rig.cache.taskQuestionDraft(taskID) == nil)
    }
}
