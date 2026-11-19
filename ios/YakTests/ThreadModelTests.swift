import Foundation
import Testing
import YakKit

@testable import Yak

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct NoSockets: WebSocketConnector {
    struct Refused: Error {}
    func connect(to url: URL) async throws -> any WebSocketConnection { throw Refused() }
}

/// Answers `me` and the thread `d0e515ef`; every other thread is unreachable.
private final class ThreadTransport: HTTPTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var paths: [String] = []
    private var messageAnswer: String?

    /// A send to the thread answers with this body (200) instead of the closed-thread refusal.
    func answerMessages(with body: String) { lock.withLock { messageAnswer = body } }

    static let me = """
        {"user":{"id":7,"name":"Test Person","email":"person@example.test","initials":"TP","isAdmin":false},
        "device":{"id":3,"name":"Test phone","kind":"phone","pairedAt":"2026-11-15T08:00:00Z"},
        "instance":{"name":"Example Yak","url":"https://example.test","version":"2026.11.1"},"needsYouCount":0,
        "realtime":null,"terminal":{"wsUrl":"wss://example.test/terminal"},
        "attachments":{"maxFiles":8,"maxFileBytes":10485760},
        "push":{"apns":false,"bundleId":"com.example.yak","environment":"production"},
        "features":{"liveActivities":false,"widgets":false}}
        """

    static let show = """
        {"thread":{"id":"d0e515ef","name":"retry-failed-rows","title":"Retry failed rows when the import times out",
        "repo":"example/api","kind":"root","status":"open","visibility":"team","branch":null,"sandboxState":"running",
        "runnerState":"idle","blockedReason":null,"parentThreadId":null,"rootThreadId":null,
        "lastActivityAt":"2026-11-15T09:30:00Z","pullRequestCount":0,"isOwner":true,"isStarred":false},
        "canDrive":true,"messages":[],"composer":{"state":"idle","model":"sonnet","accountName":"Test account",
        "nextAttachmentNumber":1,"models":[]},"notice":null,
        "panes":{"chat":true,"preview":false,"terminal":true,"changes":true,"files":true},"question":null,
        "latestSequence":14}
        """

    func requestCount(for path: String) -> Int { lock.withLock { paths.filter { $0 == path }.count } }

    private static func refusal(_ url: URL, status: Int, code: String, message: String) -> (Data, HTTPURLResponse) {
        let response = HTTPURLResponse(
            url: url, statusCode: status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(#"{"error":"\#(code)","message":"\#(message)"}"#.utf8), response)
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url!
        let path = url.path.replacingOccurrences(of: "/api/app/v1/", with: "")
        lock.withLock { paths.append(path) }
        let body: String
        if path.hasSuffix("me") {
            body = Self.me
        } else if path.hasSuffix("threads/d0e515ef") {
            body = Self.show
        } else if path.hasSuffix("events") {
            body = #"{"events":[],"latestSequence":14,"source":"database"}"#
        } else if path.hasSuffix("threads/d0e515ef/continue") {
            return Self.refusal(url, status: 409, code: "turn_running", message: "m")
        } else if path.hasSuffix("threads/d0e515ef/messages") {
            if let answer = lock.withLock({ messageAnswer }) {
                body = answer
            } else {
                return Self.refusal(url, status: 409, code: "thread_closed", message: "This thread is closed.")
            }
        } else if path.hasSuffix("threads/d0e515ef/request-review") {
            return Self.refusal(url, status: 409, code: "review_not_requested", message: "")
        } else if path.hasSuffix("threads/d0e515ef/retry-render") {
            body = String(Self.show.dropLast()) + #","status":"rendering"}"#
        } else if path.hasSuffix("queued-messages/q1") {
            return Self.refusal(url, status: 409, code: "message_delivered", message: "")
        } else {
            // A cancelled request is not retried, so the failure reaches the engine at once as offline.
            throw URLError(.cancelled)
        }
        let response = HTTPURLResponse(
            url: url, statusCode: 200, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(body.utf8), response)
    }
}

@MainActor private struct Fixture {
    let transport = ThreadTransport()
    let session: InstanceSession

    init(clock: any YakClock = SystemClock()) async throws {
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
        let instance = try await instances.add(
            url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person", token: "token")
        session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens,
            environment: SessionEnvironment(
                http: transport, webSockets: NoSockets(), files: InMemoryFileStore(), clock: clock,
                makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false))
        await session.start()
    }

    func eventually(_ condition: () -> Bool) async -> Bool {
        await TestTimeScale.poll(condition)
    }
}

@MainActor @Test func startOpensOneEngineAndStopClosesIt() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: "From the drawer")
    await model.start(initialTitle: "From the drawer")
    #expect(model.engine != nil)
    #expect(model.title == "From the drawer")
    #expect(await fixture.eventually { model.snapshot?.show != nil })
    #expect(model.title == "Retry failed rows when the import times out")
    #expect(fixture.transport.requestCount(for: "threads/d0e515ef") == 1)
    await model.stop()
    #expect(model.engine == nil)
    // The engine is closed, so a new caller gets a fresh one that asks the server again.
    let again = try #require(await fixture.session.openThread("d0e515ef"))
    #expect(await fixture.eventually { fixture.transport.requestCount(for: "threads/d0e515ef") == 2 })
    _ = again
    await fixture.session.closeThread("d0e515ef")
    await fixture.session.stop()
}

@MainActor @Test func retryOpensANewEngine() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    let first = try #require(model.engine)
    #expect(await fixture.eventually { model.snapshot?.show != nil })
    await model.retry()
    let second = try #require(model.engine)
    #expect(first !== second)
    #expect(await fixture.eventually { fixture.transport.requestCount(for: "threads/d0e515ef") == 2 })
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func panesComeFromTheShow() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    #expect(model.panes == [.chat])
    await model.start(initialTitle: nil)
    #expect(await fixture.eventually { model.snapshot?.show != nil })
    #expect(model.snapshot?.show?.panes.preview == false)
    #expect(model.panes == [.chat, .files, .terminal, .overview])
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func connectionLineFollowsTheSnapshot() async throws {
    let fixture = try await Fixture()
    let reachable = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    #expect(reachable.connectionLine == nil)
    await reachable.start(initialTitle: nil)
    #expect(await fixture.eventually { reachable.snapshot?.load == .ready })
    #expect(reachable.connectionLine == nil)
    let unreachable = ThreadModel(threadID: "zzzz0000", session: fixture.session)
    await unreachable.start(initialTitle: nil)
    #expect(await fixture.eventually { unreachable.connectionLine == .offline })
    await reachable.stop()
    await unreachable.stop()
    await fixture.session.stop()
}

// MARK: Chat

private func decodedShow() throws -> ThreadShow {
    try JSONDecoder().decode(ThreadShow.self, from: Data(ThreadTransport.show.utf8))
}

private func textItem(_ key: String, _ text: String, isStreaming: Bool = false) -> ConversationItem {
    var item = ConversationItem(kind: .text, key: key, sequence: 1, text: text)
    item.isStreaming = isStreaming
    item.at = "2026-11-15T09:00:08Z"
    return item
}

private func chatSnapshot(
    _ state: TurnState, items: [ConversationItem] = [], show: ThreadShow? = nil
) throws -> ConversationSnapshot {
    ConversationSnapshot(
        load: .ready, show: try show ?? decodedShow(), entries: items.map { .event($0) },
        turn: TurnChange(turnId: LenientID("41"), state: state), connection: .live)
}

@MainActor @Test func aTurnEndAnnouncesOnce() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    let items = [textItem("m1:0", "Looking."), textItem("m2:0", "Done. All good.")]
    await model.apply(try chatSnapshot(.running, items: items))
    await model.apply(try chatSnapshot(.running, items: items))
    #expect(model.announcementSerial == 0)
    await model.apply(try chatSnapshot(.completed, items: items))
    #expect(model.announcementSerial == 1)
    #expect(model.announcement == "Yak finished. Done. All good.")
    await model.apply(try chatSnapshot(.completed, items: items))
    #expect(model.announcementSerial == 1)
    await fixture.session.stop()
}

@MainActor @Test func aLongLastTextIsCutForTheAnnouncement() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    let items = [textItem("m1:0", String(repeating: "a", count: 500))]
    await model.apply(try chatSnapshot(.running, items: items))
    await model.apply(try chatSnapshot(.completed, items: items))
    #expect(model.announcement == "Yak finished. " + String(repeating: "a", count: 200))
    await fixture.session.stop()
}

@MainActor @Test func aThreadWithoutARepositoryNeverNamesThePlaceholder() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    let cards = ThreadCardModel(thread: model)
    let withoutRepository = ThreadTransport.show.replacingOccurrences(
        of: #""repo":"example/api""#, with: #""repo":"unknown","hasRepository":false"#)
    await model.apply(
        try chatSnapshot(
            .completed, show: JSONDecoder().decode(ThreadShow.self, from: Data(withoutRepository.utf8))))
    #expect(model.snapshot?.show?.thread.shownRepo == nil)
    #expect(cards.repositoryName == String(localized: "thread.card.coOwner.thisRepository"))
    await model.apply(try chatSnapshot(.completed))
    #expect(cards.repositoryName == "example/api")
    await fixture.session.stop()
}

@MainActor @Test func aSendNoticeStaysUntilTheShowChangesOrTheNextSendStarts() async throws {
    let clock = ManualClock()
    let fixture = try await Fixture(clock: clock)
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    let notice = SendNotice(id: UUID(), text: "Trying again with your note.")
    var snapshot = try chatSnapshot(.completed)
    snapshot.lastSendNotice = notice
    await model.apply(snapshot)
    #expect(model.actionLine == notice.text)
    clock.advance(.seconds(30))
    await model.apply(snapshot)
    #expect(model.actionLine == notice.text)
    var changed = snapshot
    changed.show = try JSONDecoder().decode(
        ThreadShow.self, from: Data(ThreadTransport.show.replacingOccurrences(of: "14}", with: "15}").utf8))
    await model.apply(changed)
    #expect(model.actionLine == nil)
    let second = SendNotice(id: UUID(), text: "Two.")
    changed.lastSendNotice = second
    await model.apply(changed)
    #expect(model.actionLine == "Two.")
    model.sendStarted()
    #expect(model.actionLine == nil)
    await fixture.session.stop()
}

@MainActor @Test func menuWorkActionsSayHowTheyEndInTheActionLine() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    #expect(await fixture.eventually { model.snapshot?.load == .ready })
    await model.performWork(.requestReview)
    #expect(model.actionLine == String(localized: "chat.work.reviewNotRequested"))
    #expect(model.isActionLineInformation)
    await model.performWork(.retryRender)
    #expect(model.actionLine == String(localized: "chat.work.renderRetried"))
    await model.continueTurn()
    #expect(model.actionLine == "m" && !model.isActionLineInformation)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func refusedActionShowsAMessage() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    #expect(await fixture.eventually { model.snapshot?.load == .ready })
    await model.continueTurn()
    #expect(model.actionLine == "m")
    #expect(model.isNoticeActionRunning == false)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func anAlreadySentMessageSaysSo() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    #expect(await fixture.eventually { model.snapshot?.load == .ready })
    await model.withdrawQueued("q1")
    #expect(model.actionLine == "This message was already sent.")
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func removeUnsentReturnsTheItem() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    let sent = try await fixture.session.send(OutboxDraft(target: .thread("d0e515ef"), text: "Hello"))
    let outbox = try #require(await fixture.session.outbox())
    let isRefused = await TestTimeScale.poll {
        if case .refused? = await outbox.items().first?.state { true } else { false }
    }
    #expect(isRefused)
    let removed = await model.removeUnsent(sent.id)
    #expect(removed?.text == "Hello")
    #expect(await outbox.items().isEmpty)
    #expect(await model.removeUnsent(sent.id) == nil)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func editKeepsTheUnsentItemAndItsFiles() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    let key = UUID()
    let attachment = try await fixture.session.storeAttachment(
        Data("photo".utf8), fileName: "photo.png", contentType: "image/png", reference: "attachment-1", sendKey: key)
    let sent = try await fixture.session.send(
        OutboxDraft(target: .thread("d0e515ef"), text: "Hello", attachments: [attachment], sendKey: key))
    let outbox = try #require(await fixture.session.outbox())
    var refused: OutboxItem?
    _ = await TestTimeScale.poll {
        if let item = await outbox.items().first, case .refused = item.state { refused = item }
        return refused != nil
    }
    let item = try #require(refused)
    // Edit hands the refused message to the composer: its text, model and files, and it leaves the outbox.
    #expect(await model.edit(item))
    #expect(model.composer.text == item.text)
    #expect(model.composer.model == item.model)
    #expect(model.composer.attachments == item.attachments)
    #expect(await outbox.items().map(\.id).contains(sent.id) == false)
    #expect(await fixture.session.attachmentData(attachment) != nil)
    // A second Edit of the same message finds nothing to take and says so; the composer keeps what it has.
    #expect(await model.edit(item) == false)
    #expect(model.composer.text == item.text)
    #expect(model.composer.problem == String(localized: "composer.problem.notEditable"))
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aClearNamesTheNewThread() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    #expect(model.replacementThreadID == nil)
    await model.apply(ConversationSnapshot(load: .ready, replacementThreadID: "n3w7hr3d"))
    #expect(model.replacementThreadID == "n3w7hr3d")
    await fixture.session.stop()
}

@MainActor @Test func rowsComeFromTheSnapshotWithADayDivider() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    #expect(model.rows.isEmpty)
    await model.apply(try chatSnapshot(.completed, items: [textItem("m1:0", "Hi")]))
    #expect(model.rows.count == 2)
    guard case .divider = model.rows[0], case .entry = model.rows[1] else {
        Issue.record("expected a day divider and one entry")
        return
    }
    await fixture.session.stop()
}

@MainActor @Test func historyStateFollowsTheSnapshot() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    #expect(model.historyState == .hidden)
    await model.apply(ConversationSnapshot(load: .ready, hasOlderHistory: true))
    #expect(model.historyState == .available)
    await model.apply(ConversationSnapshot(load: .ready, hasOlderHistory: true, isLoadingOlder: true))
    #expect(model.historyState == .loading)
    await model.apply(ConversationSnapshot(load: .ready, hasOlderHistory: true, didOlderFail: true))
    #expect(model.historyState == .failed)
    // A problem elsewhere is not a history problem.
    await model.apply(ConversationSnapshot(load: .ready, isOffline: true, hasOlderHistory: true, problem: .offline))
    #expect(model.historyState == .available)
    await model.apply(ConversationSnapshot(load: .ready, isOffline: true))
    #expect(model.historyState == .hidden)
    await fixture.session.stop()
}

@MainActor @Test func aStreamingTextGetsADocumentAfterItsFirstParse() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.apply(try chatSnapshot(.running, items: [textItem("m1:0", "Hello **there**", isStreaming: true)]))
    #expect(model.documents["m1:0"] == nil)
    #expect(await fixture.eventually { model.documents["m1:0"] != nil })
    await fixture.session.stop()
}

@MainActor @Test func markdownRendersAfterRetry() async throws {
    let clock = ManualClock()
    let fixture = try await Fixture(clock: clock)
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    /// The engine's own snapshots can arrive after a canned one and replace the items waiting for a parse, so the
    /// canned snapshot is applied until a parse has finished and its publication waits for the flush delay.
    func applyUntilAFlushWaits(_ snapshot: ConversationSnapshot) async throws -> Bool {
        await TestTimeScale.poll {
            await model.apply(snapshot)
            return clock.sleeperCount(waking: .milliseconds(50)) >= 1
        }
    }
    #expect(try await applyUntilAFlushWaits(chatSnapshot(.completed, items: [textItem("m1:0", "First")])))
    await model.retry()
    #expect(try await applyUntilAFlushWaits(chatSnapshot(.completed, items: [textItem("m2:0", "Second")])))
    clock.advance(.milliseconds(50))
    #expect(await fixture.eventually { model.documents["m2:0"] != nil })
    await model.stop()
    await fixture.session.stop()
}

private final class ThreadRecorder: @unchecked Sendable {
    private let lock = NSLock()
    private var values: [Bool] = []
    var isMainThread: [Bool] { lock.withLock { values } }
    func record(_ value: Bool) { lock.withLock { values.append(value) } }
}

@MainActor @Test func rowsAreBuiltOffTheMainActor() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    let recorder = ThreadRecorder()
    model.buildRows = { snapshot, now, calendar in
        recorder.record(Thread.isMainThread)
        return ThreadModel.defaultRowsBuilder(snapshot, now, calendar)
    }
    await model.apply(try chatSnapshot(.completed, items: [textItem("m1:0", "Hi")]))
    #expect(recorder.isMainThread == [false])
    #expect(model.rows.count == 2)
    await fixture.session.stop()
}

@MainActor @Test func aLaterModelOfTheSameThreadKeepsTheComposerOfTheEarlierOne() async throws {
    let fixture = try await Fixture()
    let first = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    let second = ThreadModel(threadID: "d0e515ef", session: fixture.session, composer: first.composer)
    #expect(second.composer === first.composer)
    await fixture.session.stop()
}

@MainActor @Test func aDeliveredClearNamesTheNewThreadWhileTheModelIsOpen() async throws {
    let fixture = try await Fixture()
    fixture.transport.answerMessages(with: #"{"status":"sent","uuid":null,"threadId":"n3w7hr3d"}"#)
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    #expect(await fixture.eventually { model.snapshot?.show != nil })
    #expect(model.replacementThreadID == nil)
    _ = try await fixture.session.send(OutboxDraft(target: .thread("d0e515ef"), text: "/clear"))
    #expect(await fixture.eventually { model.replacementThreadID == "n3w7hr3d" })
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aRefusedClearKeepsTheOldThreadAndShowsTheFailure() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    #expect(await fixture.eventually { model.snapshot?.show != nil })
    let sent = try await fixture.session.send(OutboxDraft(target: .thread("d0e515ef"), text: "/clear"))
    let outbox = try #require(await fixture.session.outbox())
    var isRefused = false
    for _ in 0..<600 where !isRefused {
        if case .refused? = await outbox.items().first?.state {
            isRefused = true
        } else {
            try await Task.sleep(for: .milliseconds(5))
        }
    }
    #expect(isRefused)
    #expect(model.replacementThreadID == nil)
    // The failure shows as for any message: the item stays in the outbox, in the conversation, for Retry or Edit.
    #expect(await fixture.eventually { model.rows.isEmpty == false })
    #expect(await outbox.items().map(\.id) == [sent.id])
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aClearDeliveredToAClosedEngineNamesNothing() async throws {
    let fixture = try await Fixture()
    fixture.transport.answerMessages(with: #"{"status":"sent","uuid":null,"threadId":"n3w7hr3d"}"#)
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    await model.start(initialTitle: nil)
    #expect(await fixture.eventually { model.snapshot?.show != nil })
    // The thread's engine is closed while the session still forwards to it; the delivery must name nothing.
    let engine = try #require(model.engine)
    await engine.close()
    _ = try await fixture.session.send(OutboxDraft(target: .thread("d0e515ef"), text: "/clear"))
    let outbox = try #require(await fixture.session.outbox())
    var isDelivered = false
    for _ in 0..<600 where !isDelivered {
        if await outbox.items().isEmpty { isDelivered = true } else { try await Task.sleep(for: .milliseconds(5)) }
    }
    #expect(isDelivered)
    try await Task.sleep(for: .milliseconds(50))
    #expect(model.replacementThreadID == nil)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aSlowRateLimitedActionShowsTheTakingLongerLine() async throws {
    let fixture = try await Fixture()
    let model = ThreadModel(threadID: "d0e515ef", session: fixture.session)
    #expect(model.actionLine == nil)
    await model.apply(ConversationSnapshot(load: .ready, isActionSlow: true))
    #expect(model.actionLine == String(localized: "chat.action.slow"))
    await model.apply(ConversationSnapshot(load: .ready))
    #expect(model.actionLine == nil)
    await fixture.session.stop()
}
