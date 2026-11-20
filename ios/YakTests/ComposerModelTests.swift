import Foundation
import Testing
import UIKit
import YakKit

@testable import Yak

/// A clock the tests step by hand; a sleeper wakes when `advance` passes its deadline.
private final class StepClock: YakClock, @unchecked Sendable {
    private struct Sleeper {
        let identifier: Int
        let deadline: Duration
        let continuation: CheckedContinuation<Void, any Error>
    }

    private let lock = NSLock()
    private var elapsed: Duration = .zero
    private var sleepers: [Sleeper] = []
    private var nextIdentifier = 0

    func now() -> Date { Date(timeIntervalSince1970: 1_800_000_000) }

    func sleep(for duration: Duration) async throws {
        let identifier = lock.withLock { () -> Int in
            nextIdentifier += 1
            return nextIdentifier
        }
        try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, any Error>) in
                let isCancelled: Bool = lock.withLock {
                    if Task.isCancelled { return true }
                    sleepers.append(
                        Sleeper(identifier: identifier, deadline: elapsed + duration, continuation: continuation))
                    return false
                }
                if isCancelled { continuation.resume(throwing: CancellationError()) }
            }
        } onCancel: {
            let removed: Sleeper? = lock.withLock {
                guard let index = sleepers.firstIndex(where: { $0.identifier == identifier }) else { return nil }
                return sleepers.remove(at: index)
            }
            removed?.continuation.resume(throwing: CancellationError())
        }
    }

    /// Waits until a sleeper is registered, so the advance cannot run ahead of the task that sleeps.
    func advance(by duration: Duration) async {
        let deadline = ContinuousClock.now.advanced(by: TestTimeScale.waitBound)
        while lock.withLock({ sleepers.isEmpty }), ContinuousClock.now < deadline {
            try? await Task.sleep(for: .milliseconds(1))
        }
        let target = lock.withLock { elapsed + duration }
        let due: [Sleeper] = lock.withLock {
            elapsed = target
            let ready = sleepers.filter { $0.deadline <= target }
            sleepers.removeAll { $0.deadline <= target }
            return ready
        }
        for sleeper in due { sleeper.continuation.resume() }
        for _ in 0..<200 { await Task.yield() }
    }
}

private final class FakeComposerBackend: ComposerBackend, @unchecked Sendable {
    private let lock = NSLock()
    private var drafts: [ComposerDraftKey: ComposerDraft] = [:]
    private var sent: [OutboxDraft] = []
    private var failure: SendProblem?
    private var stopOutcome: ThreadActionOutcome = .done
    private var sendDelay: Duration?
    private var stopDelay: Duration?
    private var loadDelay: Duration?
    private var stopCalls = 0
    private var stored: [(reference: String, sendKey: UUID)] = []
    private var discarded: [OutboxAttachment] = []
    private var files: [String: Data] = [:]
    private var storeFailure: FileStoreError?
    private var storeDelay: Duration?
    private var saveDelay: Duration?
    private var limitsDelay: Duration?
    private var attachmentLimits: Me.AttachmentLimits? = try? JSONDecoder().decode(
        Me.AttachmentLimits.self, from: Data(#"{"maxFiles":8,"maxFileBytes":10485760}"#.utf8))

    var storageFailure: FileStoreError? {
        get { lock.withLock { storeFailure } }
        set { lock.withLock { storeFailure = newValue } }
    }
    var slowStore: Duration? {
        get { lock.withLock { storeDelay } }
        set { lock.withLock { storeDelay = newValue } }
    }
    /// Delays the write of every non-empty draft, so such a save can land after a later one.
    var slowSave: Duration? {
        get { lock.withLock { saveDelay } }
        set { lock.withLock { saveDelay = newValue } }
    }
    var slowLimits: Duration? {
        get { lock.withLock { limitsDelay } }
        set { lock.withLock { limitsDelay = newValue } }
    }
    private var held: [UUID: OutboxItem] = [:]
    /// The outbox could not save the list, so an edit gives nothing up.
    var takeFailsToSave = false
    func hold(_ item: OutboxItem) { lock.withLock { held[item.id] = item } }
    func takeOutboxItem(_ id: UUID) async -> OutboxTakeOutcome {
        if takeFailsToSave { return .notSaved }
        return lock.withLock { held.removeValue(forKey: id) }.map { .taken($0) } ?? .unavailable
    }
    func keep(_ data: Data, at path: String) { lock.withLock { files[path] = data } }

    var sendFailure: SendProblem? {
        get { lock.withLock { failure } }
        set { lock.withLock { failure = newValue } }
    }
    var stopResult: ThreadActionOutcome {
        get { lock.withLock { stopOutcome } }
        set { lock.withLock { stopOutcome = newValue } }
    }
    var slowSend: Duration? {
        get { lock.withLock { sendDelay } }
        set { lock.withLock { sendDelay = newValue } }
    }
    var slowStop: Duration? {
        get { lock.withLock { stopDelay } }
        set { lock.withLock { stopDelay = newValue } }
    }
    var slowLoad: Duration? {
        get { lock.withLock { loadDelay } }
        set { lock.withLock { loadDelay = newValue } }
    }
    var maximumFiles: Int {
        get { lock.withLock { attachmentLimits?.maxFiles ?? 0 } }
        set {
            lock.withLock {
                attachmentLimits = try? JSONDecoder().decode(
                    Me.AttachmentLimits.self, from: Data(#"{"maxFiles":\#(newValue),"maxFileBytes":10485760}"#.utf8))
            }
        }
    }
    var storedReferences: [String] { lock.withLock { stored.map(\.reference) } }
    var storedSendKeys: [UUID] { lock.withLock { stored.map(\.sendKey) } }
    var discardedReferences: [String] { lock.withLock { discarded.map(\.reference) } }
    var sentDrafts: [OutboxDraft] { lock.withLock { sent } }
    var stopCount: Int { lock.withLock { stopCalls } }
    func savedDraft(_ key: ComposerDraftKey) -> ComposerDraft? { lock.withLock { drafts[key] } }
    func seed(_ draft: ComposerDraft, for key: ComposerDraftKey) { lock.withLock { drafts[key] = draft } }

    func draft(_ key: ComposerDraftKey) async -> ComposerDraft? {
        if let delay = slowLoad { try? await Task.sleep(for: delay) }
        return lock.withLock { drafts[key] }
    }

    func saveDraft(_ draft: ComposerDraft?, for key: ComposerDraftKey) async {
        // A write already under way is not cut short by the caller's cancellation.
        if draft != nil, let delay = slowSave { await Task.detached { try? await Task.sleep(for: delay) }.value }
        lock.withLock { drafts[key] = draft }
    }

    func send(_ draft: OutboxDraft) async throws(SendProblem) -> OutboxItem {
        if let delay = slowSend { try? await Task.sleep(for: delay) }
        if let failure = sendFailure { throw failure }
        lock.withLock { sent.append(draft) }
        return OutboxItem(
            id: draft.sendKey ?? UUID(), target: draft.target, text: draft.text, model: draft.model,
            delivery: draft.delivery, attachments: draft.attachments, createdAt: Date())
    }

    func storeAttachment(_ prepared: PreparedAttachment, reference: String, sendKey: UUID) async throws(FileStoreError)
        -> OutboxAttachment
    {
        if let delay = slowStore { try? await Task.sleep(for: delay) }
        if let failure = storageFailure { throw failure }
        lock.withLock { stored.append((reference, sendKey)) }
        return OutboxAttachment(
            reference: reference, fileName: prepared.fileName, contentType: prepared.contentType,
            path: "attachments/\(sendKey)/\(prepared.fileName)")
    }

    private var dataReadCount = 0
    /// How many times a whole attachment was read.
    var attachmentReads: Int { lock.withLock { dataReadCount } }
    func attachmentData(_ attachment: OutboxAttachment) async -> Data? {
        lock.withLock {
            dataReadCount += 1
            return files[attachment.path]
        }
    }

    func attachmentExists(_ attachment: OutboxAttachment) async -> Bool {
        lock.withLock { files[attachment.path] != nil }
    }

    func discardAttachment(_ attachment: OutboxAttachment) async { lock.withLock { discarded.append(attachment) } }

    func limits() async -> Me.AttachmentLimits? {
        if let delay = slowLimits { try? await Task.sleep(for: delay) }
        return lock.withLock { attachmentLimits }
    }

    func stop() async -> ThreadActionOutcome {
        lock.withLock { stopCalls += 1 }
        if let delay = slowStop { try? await Task.sleep(for: delay) }
        return stopResult
    }
}

/// The real outbox and cache over an in-memory file store; the transport refuses every message, so an item that is
/// sent ends up refused and waits for Edit.
private final class OutboxBackedComposerBackend: ComposerBackend, @unchecked Sendable {
    struct RefusingTransport: HTTPTransport {
        func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
            let response = HTTPURLResponse(
                url: request.url!, statusCode: 409, httpVersion: nil, headerFields: ["Content-Type": "application/json"]
            )!
            return (Data(#"{"error":"thread_closed","message":"Closed"}"#.utf8), response)
        }
    }

    let files = InMemoryFileStore()
    let instance = UUID()
    let outbox: Outbox
    private let cache: Cache

    init() {
        outbox = Outbox(
            instance: instance,
            client: APIClient(
                baseURL: URL(string: "https://example.test")!, token: "test-token", transport: RefusingTransport(),
                appVersion: "1.0", systemDescription: "Test phone"),
            files: files, clock: SystemClock(), makeID: { UUID() })
        cache = Cache(instance: instance, files: files, clock: SystemClock())
    }

    func draft(_ key: ComposerDraftKey) async -> ComposerDraft? { await cache.composerDraft(key) }
    func saveDraft(_ draft: ComposerDraft?, for key: ComposerDraftKey) async {
        await cache.saveComposerDraft(draft, for: key)
    }
    func send(_ draft: OutboxDraft) async throws(SendProblem) -> OutboxItem {
        do { return try await outbox.enqueue(draft) } catch { throw .notSaved }
    }
    func stop() async -> ThreadActionOutcome { .done }
    func storeAttachment(_ prepared: PreparedAttachment, reference: String, sendKey: UUID) async throws(FileStoreError)
        -> OutboxAttachment
    {
        let path = OutboxInbox.attachmentPath(instance: instance, item: sendKey, fileName: prepared.fileName)
        try files.write(prepared.data, to: path, protection: .untilFirstUnlock)
        return OutboxAttachment(
            reference: reference, fileName: prepared.fileName, contentType: prepared.contentType, path: path)
    }
    func attachmentData(_ attachment: OutboxAttachment) async -> Data? { try? files.read(attachment.path) }
    func attachmentExists(_ attachment: OutboxAttachment) async -> Bool { (try? files.exists(attachment.path)) ?? true }
    func discardAttachment(_ attachment: OutboxAttachment) async { try? files.remove(attachment.path) }
    func limits() async -> Me.AttachmentLimits? { nil }
    func takeOutboxItem(_ id: UUID) async -> OutboxTakeOutcome {
        (try? await outbox.take(id)).map { .taken($0) } ?? .unavailable
    }

    /// Waits until the outbox has answered the item with a refusal.
    func waitForRefusal() async -> OutboxItem? {
        var refused: OutboxItem?
        _ = await TestTimeScale.poll {
            if let item = await outbox.items().first, case .refused = item.state { refused = item }
            return refused != nil
        }
        return refused
    }
}

private func attachment(_ reference: String) -> OutboxAttachment {
    OutboxAttachment(reference: reference, fileName: "a.png", contentType: "image/png", path: "attachments/a.png")
}

private func refusedItem(text: String, attachments: [OutboxAttachment]) -> OutboxItem {
    OutboxItem(
        id: UUID(), target: .thread("d0e515ef"), text: text, model: "sonnet", delivery: .steer,
        attachments: attachments, state: .refused(OutboxRefusal(code: "thread_closed", message: "Closed")),
        createdAt: Date())
}

private func imageData() -> Data {
    UIGraphicsImageRenderer(size: CGSize(width: 40, height: 30)).pngData { context in
        UIColor.systemTeal.setFill()
        context.fill(CGRect(x: 0, y: 0, width: 40, height: 30))
    }
}

private func snapshot(
    state: String, nextAttachmentNumber: Int = 1, models: String = "[]", canDrive: Bool = true,
    effort: String = "null"
) throws
    -> ConversationSnapshot
{
    let json = """
        {"thread":{"id":"d0e515ef","name":"retry-failed-rows","title":"Retry failed rows","repo":"example/api",
        "kind":"root","status":"open","visibility":"team","branch":null,"sandboxState":"running","runnerState":"idle",
        "blockedReason":null,"parentThreadId":null,"rootThreadId":null,"lastActivityAt":"2026-11-15T09:30:00Z",
        "pullRequestCount":0,"isOwner":true,"isStarred":false},"canDrive":\(canDrive),"messages":[],
        "composer":{"state":"\(state)","model":"sonnet","effort":\(effort),"accountName":"Test account","nextAttachmentNumber":\(nextAttachmentNumber),"models":\(models)},
        "notice":null,"panes":{"chat":true,"preview":false,"terminal":true,"changes":true,"files":true},
        "question":null,"latestSequence":14}
        """
    let show = try JSONDecoder().decode(ThreadShow.self, from: Data(json.utf8))
    return ConversationSnapshot(load: .ready, show: show)
}

@MainActor private func makeModel(
    _ id: String = "d0e515ef", backend: FakeComposerBackend, clock: StepClock
) -> ComposerModel {
    ComposerModel(key: .thread(id), backend: backend, clock: clock)
}

@MainActor struct ComposerModelTests {
    @Test func typedTextIsNeverLost() async throws {
        let backend = FakeComposerBackend()
        let clock = StepClock()
        let first = makeModel(backend: backend, clock: clock)
        await first.load()
        first.text = "Use the draft names"
        await clock.advance(by: .milliseconds(500))
        let other = makeModel("a1b2c3d4", backend: backend, clock: clock)
        await other.load()
        #expect(other.text == "")
        let again = makeModel(backend: backend, clock: clock)
        await again.load()
        #expect(again.text == "Use the draft names")
        backend.sendFailure = .notSaved
        await again.send(delivery: .steer)
        #expect(again.text == "Use the draft names")
        #expect(again.problem == "This message could not be saved. Try again.")
        again.update(snapshot: try snapshot(state: "readonly"))
        #expect(again.bar.unavailable == .readOnly)
        #expect(again.text == "Use the draft names")
        let refused = refusedItem(text: "Old text", attachments: [attachment("Image #1")])
        backend.hold(refused)
        await again.restore(refused)
        #expect(again.text == "Old text\nUse the draft names")
        #expect(again.attachments.map(\.reference) == ["Image #1"])
    }

    @Test func aSuccessfulSendClearsTheFieldAndTheDraft() async throws {
        let backend = FakeComposerBackend()
        let clock = StepClock()
        let sendKey = UUID()
        backend.keep(imageData(), at: attachment("Image #1").path)
        backend.seed(
            ComposerDraft(
                text: "  Add the test  ", model: "opus", sendKey: sendKey, attachments: [attachment("Image #1")]),
            for: .thread("d0e515ef"))
        let composer = makeModel(backend: backend, clock: clock)
        await composer.load()
        await composer.send(delivery: .steer)
        let sent = try #require(backend.sentDrafts.first)
        #expect(sent.text == "Add the test" && sent.model == "opus" && sent.delivery == .steer)
        #expect(sent.sendKey == sendKey && sent.attachments.map(\.reference) == ["Image #1"])
        #expect(composer.text == "" && composer.attachments.isEmpty && composer.problem == nil)
        #expect(backend.savedDraft(.thread("d0e515ef")) == nil)
        #expect(composer.acceptedSendCount == 1 && composer.refusalCount == 0)
    }

    @Test func aLongPressSendsAfterTheTurn() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "working"))
        composer.text = "Then update the docs"
        await composer.send(delivery: .afterTurn)
        #expect(backend.sentDrafts.map(\.delivery) == [.afterTurn])
    }

    @Test func aDoubleTapSendsOnce() async {
        let backend = FakeComposerBackend()
        backend.slowSend = .milliseconds(100)
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.text = "Once"
        async let first: Void = composer.send(delivery: .steer)
        async let second: Void = composer.send(delivery: .steer)
        _ = await (first, second)
        #expect(backend.sentDrafts.count == 1)
    }

    @Test func aNotActiveSessionKeepsEverythingAndSaysSo() async {
        let backend = FakeComposerBackend()
        backend.sendFailure = .notActive
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.text = "Wait for me"
        await composer.send(delivery: .steer)
        #expect(composer.text == "Wait for me")
        #expect(composer.problem == "Yak is not connected yet. Try again in a moment.")
        #expect(composer.refusalCount == 1 && composer.acceptedSendCount == 0)
    }

    @Test func stopShowsProgressAndTheRefusal() async throws {
        let backend = FakeComposerBackend()
        backend.slowStop = .milliseconds(100)
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "working"))
        async let first: Void = composer.stop()
        await Task.yield()
        async let second: Void = composer.stop()
        _ = await (first, second)
        #expect(backend.stopCount == 1 && !composer.isStopping)
        backend.slowStop = nil
        backend.stopResult = .refused(code: "no_turn", message: "Nothing is running.", fieldErrors: [:])
        await composer.stop()
        #expect(composer.problem == "Nothing is running.")
        backend.stopResult = .failed(.offline)
        await composer.stop()
        #expect(composer.problem == "Stop did not reach Yak. Check the connection and try again.")
        backend.stopResult = .done
        await composer.stop()
        #expect(composer.problem == nil)
    }

    @Test func theKeyboardShortcutsFollowTheBar() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.text = "Hello"
        composer.update(snapshot: try snapshot(state: "readonly"))
        await composer.send(delivery: .steer)
        #expect(backend.sentDrafts.isEmpty)
        composer.update(snapshot: try snapshot(state: "idle"))
        await composer.send(delivery: .afterTurn)
        #expect(backend.sentDrafts.isEmpty)
        await composer.stop()
        #expect(backend.stopCount == 0)
        composer.update(snapshot: try snapshot(state: "working"))
        await composer.send(delivery: .afterTurn)
        #expect(backend.sentDrafts.map(\.delivery) == [.afterTurn])
    }

    @Test func loadingChecksThatAFileExistsWithoutReadingIt() async throws {
        let backend = FakeComposerBackend()
        let kept = OutboxAttachment(
            reference: "File #1", fileName: "a.pdf", contentType: "application/pdf", path: "attachments/a.pdf")
        let gone = OutboxAttachment(
            reference: "File #2", fileName: "b.pdf", contentType: "application/pdf", path: "attachments/b.pdf")
        backend.keep(Data("pdf".utf8), at: kept.path)
        backend.seed(ComposerDraft(text: "Hi", attachments: [kept, gone]), for: .thread("d0e515ef"))
        let composer = makeModel(backend: backend, clock: StepClock())
        await composer.load()
        #expect(composer.attachments == [kept])
        #expect(composer.problem == "An attachment of this draft is gone from this device, so it was removed.")
        #expect(backend.attachmentReads == 0)
    }

    @Test func aFlushBeforeTheLoadKeepsTheSavedDraft() async {
        let backend = FakeComposerBackend()
        backend.seed(ComposerDraft(text: "Saved text"), for: .thread("d0e515ef"))
        let composer = makeModel(backend: backend, clock: StepClock())
        await composer.flush()
        #expect(backend.savedDraft(.thread("d0e515ef"))?.text == "Saved text")
        await composer.load()
        #expect(composer.text == "Saved text")
    }

    @Test func textTypedWhileASendIsInFlightStays() async {
        let backend = FakeComposerBackend()
        backend.slowSend = .milliseconds(100)
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.text = "First"
        async let inFlight: Void = composer.send(delivery: .steer)
        while !composer.isSending { await Task.yield() }
        #expect(composer.text == "")
        composer.text = " and more"
        await inFlight
        #expect(composer.text == " and more")
        #expect(backend.sentDrafts.map(\.text) == ["First"])
        #expect(backend.savedDraft(.thread("d0e515ef"))?.text == " and more")
    }

    @Test func aLateLoadDoesNotOverwriteTyping() async {
        let backend = FakeComposerBackend()
        backend.slowLoad = .milliseconds(100)
        backend.seed(ComposerDraft(text: "Saved text"), for: .thread("d0e515ef"))
        let composer = makeModel(backend: backend, clock: StepClock())
        async let loading: Void = composer.load()
        await Task.yield()
        composer.text = "Typed first"
        await loading
        #expect(composer.text == "Typed first")
    }

    @Test func flushSavesNowAndAnEmptyDraftIsRemoved() async {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.text = "Keep this"
        await composer.flush()
        #expect(backend.savedDraft(.thread("d0e515ef"))?.text == "Keep this")
        composer.text = "  "
        await composer.flush()
        #expect(backend.savedDraft(.thread("d0e515ef")) == nil)
    }

    @Test func theBarFollowsTheServerAndKeepsTheText() async throws {
        let composer = makeModel(backend: FakeComposerBackend(), clock: StepClock())
        composer.text = "Hello"
        composer.update(snapshot: try snapshot(state: "disabled_closed"))
        #expect(composer.bar.unavailable == .closed)
        composer.update(snapshot: try snapshot(state: "idle"))
        #expect(composer.bar.unavailable == nil && composer.bar.showsSend && composer.text == "Hello")
    }

    @Test func attachmentsAreNumberedFromTheServersNumber() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle", nextAttachmentNumber: 3))
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        await composer.add(Data("hello".utf8), fileName: "notes.txt", contentType: "text/plain")
        #expect(composer.attachments.map(\.reference) == ["Image #3", "File #4"])
        #expect(composer.attachments.first?.contentType == "image/jpeg")
        #expect(composer.thumbnails["Image #3"] != nil && composer.thumbnails["File #4"] == nil)
        let keys = Set(backend.storedSendKeys)
        #expect(keys.count == 1)
        #expect(backend.savedDraft(.thread("d0e515ef"))?.sendKey == keys.first)
        #expect(backend.savedDraft(.thread("d0e515ef"))?.attachments.count == 2)
    }

    @Test func aNumberIsNeverGivenTwiceAfterARemove() async throws {
        let composer = makeModel(backend: FakeComposerBackend(), clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle", nextAttachmentNumber: 3))
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        await composer.add(Data("hello".utf8), fileName: "notes.txt", contentType: "text/plain")
        await composer.remove(composer.attachments[0])
        await composer.add(imageData(), fileName: "b.png", contentType: "image/png")
        #expect(composer.attachments.map(\.reference) == ["File #4", "Image #5"])
    }

    @Test func twoAddsInFlightGetDifferentNumbers() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle"))
        async let first: Void = composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        async let second: Void = composer.add(imageData(), fileName: "b.png", contentType: "image/png")
        _ = await (first, second)
        #expect(Set(composer.attachments.map(\.reference)) == ["Image #1", "Image #2"])
        #expect(backend.storedSendKeys.count == 2 && Set(backend.storedSendKeys).count == 1)
    }

    @Test func sendWaitsForAnAttachmentThatIsStillBeingAdded() async throws {
        let backend = FakeComposerBackend()
        backend.slowStore = .milliseconds(300)
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle"))
        composer.text = "Hi"
        let adding = Task { await composer.add(imageData(), fileName: "a.png", contentType: "image/png") }
        while composer.preparingCount == 0 { await Task.yield() }
        #expect(!composer.canSend)
        await composer.send(delivery: .steer)
        #expect(backend.sentDrafts.isEmpty)
        await adding.value
        #expect(composer.canSend)
        await composer.send(delivery: .steer)
        #expect(backend.sentDrafts.count == 1 && backend.sentDrafts[0].attachments.count == 1)
    }

    @Test func aFailedAddKeepsASendKeyItDidNotCreate() async throws {
        let backend = FakeComposerBackend()
        let key = UUID()
        backend.seed(ComposerDraft(text: "Hi", model: nil, sendKey: key, attachments: []), for: .thread("d0e515ef"))
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle"))
        await composer.load()
        backend.storageFailure = .unreadable
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        await composer.send(delivery: .steer)
        #expect(backend.sentDrafts.first?.sendKey == key)
    }

    @Test func aFullDiskIsNotCalledUnreadable() async {
        let backend = FakeComposerBackend()
        backend.storageFailure = .unreadable
        let composer = makeModel(backend: backend, clock: StepClock())
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        #expect(composer.problem == "This file could not be saved on this device. Try again.")
        #expect(composer.attachments.isEmpty)
    }

    @Test func aRestoredDraftShowsItsPictures() async {
        let backend = FakeComposerBackend()
        let stored = OutboxAttachment(
            reference: "Image #1", fileName: "a.jpg", contentType: "image/jpeg", path: "attachments/a.jpg")
        backend.keep(imageData(), at: stored.path)
        backend.seed(ComposerDraft(text: "", attachments: [stored]), for: .thread("d0e515ef"))
        let composer = makeModel(backend: backend, clock: StepClock())
        await composer.load()
        #expect(composer.thumbnails["Image #1"] != nil)
    }

    @Test func theCountIsCheckedFirst() async {
        let backend = FakeComposerBackend()
        backend.maximumFiles = 1
        let composer = makeModel(backend: backend, clock: StepClock())
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        await composer.add(imageData(), fileName: "b.png", contentType: "image/png")
        #expect(composer.attachments.count == 1 && backend.storedReferences.count == 1)
        #expect(composer.problem == "You can attach up to 1 file.")
        #expect(composer.refusalCount == 1)
    }

    @Test func aFileThatIsTooLargeOrUnreadableSaysSo() async {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        await composer.add(Data(count: 11 * 1024 * 1024), fileName: "big.bin", contentType: "application/octet-stream")
        #expect(composer.problem?.hasPrefix("This file is larger than") == true)
        await composer.add(Data("nope".utf8), fileName: "x.png", contentType: "image/png")
        #expect(composer.problem == "This file could not be read.")
        #expect(composer.attachments.isEmpty && backend.storedReferences.isEmpty)
    }

    @Test func removingDiscardsTheFile() async {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        let added = composer.attachments[0]
        await composer.remove(added)
        #expect(composer.attachments.isEmpty && composer.thumbnails.isEmpty)
        #expect(backend.discardedReferences == [added.reference])
        #expect(backend.savedDraft(.thread("d0e515ef")) == nil)
    }

    @Test func aPickedModelIsSentAndKeptInTheDraft() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle"))
        composer.text = "Use the big one"
        composer.selectModel("opus")
        #expect(composer.modelLabel == "opus")
        await composer.flush()
        #expect(backend.savedDraft(.thread("d0e515ef"))?.model == "opus")
        await composer.send(delivery: .steer)
        #expect(backend.sentDrafts.first?.model == "opus")
        #expect(composer.effectiveModel == "sonnet")
    }

    private static let effortModels = """
        [{"family":"opus","latest":{"value":"opus","label":"Opus 5.5","description":"","efforts":["low","medium","high","xhigh","max"]},"older":[]},
        {"family":"sonnet","latest":{"value":"sonnet","label":"Sonnet 5","description":"","efforts":["low","medium","high"]},"older":[]},
        {"family":"haiku","latest":{"value":"haiku","label":"Haiku","description":"","efforts":[]},"older":[]}]
        """

    @Test func theThreadsEffortIsShownAndSwitchingModelClampsIt() async throws {
        let composer = makeModel(backend: FakeComposerBackend(), clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle", models: Self.effortModels, effort: "\"high\""))
        #expect(composer.effectiveEffort == .high)
        #expect(composer.modelLabel == "Sonnet 5 · High")
        composer.selectEffort(.low)
        composer.selectModel("opus")
        composer.selectEffort(.max)
        #expect(composer.modelLabel == "Opus 5.5 · Max")
        composer.selectModel("sonnet")
        #expect(composer.effectiveEffort == .high)
        composer.selectModel("haiku")
        #expect(composer.effectiveEffort == nil && composer.modelLabel == "Haiku")
        #expect(composer.supportedEfforts.isEmpty)
    }

    @Test func aPickedEffortIsSentWithItsModelAndAutoSendsNone() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle", models: Self.effortModels, effort: "\"high\""))
        composer.text = "Think harder"
        composer.selectEffort(.medium)
        await composer.flush()
        #expect(backend.savedDraft(.thread("d0e515ef"))?.effort == .medium)
        await composer.send(delivery: .steer)
        #expect(backend.sentDrafts.first?.model == "sonnet" && backend.sentDrafts.first?.effort == .medium)
        #expect(composer.effectiveEffort == .high)
        composer.text = "Back to the default"
        composer.selectEffort(nil)
        await composer.send(delivery: .steer)
        #expect(backend.sentDrafts.last?.model == "sonnet" && backend.sentDrafts.last?.effort == nil)
    }

    // MARK: One draft, one in-flight send

    @Test func attachmentsAddedWhileSendingBelongToTheNextDraft() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle"))
        composer.text = "First"
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        let sentKey = try #require(backend.storedSendKeys.first)
        backend.slowSend = .milliseconds(100)
        async let inFlight: Void = composer.send(delivery: .steer)
        while !composer.isSending { await Task.yield() }
        #expect(composer.attachments.isEmpty)
        await composer.add(imageData(), fileName: "b.png", contentType: "image/png")
        await inFlight
        #expect(backend.sentDrafts.map(\.sendKey) == [sentKey])
        #expect(backend.storedSendKeys.count == 2 && backend.storedSendKeys[1] != sentKey)
        #expect(composer.attachments.count == 1)
        backend.slowSend = nil
        await composer.send(delivery: .steer)
        #expect(backend.sentDrafts.count == 2)
        #expect(
            backend.sentDrafts[1].sendKey == backend.storedSendKeys[1] && backend.sentDrafts[1].attachments.count == 1)
    }

    @Test func aFailedSendBringsTheTakenDraftBackInFrontOfWhatWasTyped() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle"))
        composer.text = "First"
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        let first = composer.attachments[0]
        let sentKey = try #require(backend.storedSendKeys.first)
        backend.slowSend = .milliseconds(100)
        backend.sendFailure = .notSaved
        async let inFlight: Void = composer.send(delivery: .steer)
        while !composer.isSending { await Task.yield() }
        composer.text = "more"
        await composer.add(imageData(), fileName: "b.png", contentType: "image/png")
        await inFlight
        #expect(composer.text == "First\nmore")
        #expect(composer.attachments.first == first && composer.attachments.count == 2)
        #expect(composer.thumbnails[first.reference] != nil)
        #expect(composer.problem == "This message could not be saved. Try again.")
        backend.sendFailure = nil
        backend.slowSend = nil
        await composer.send(delivery: .steer)
        #expect(backend.sentDrafts.count == 1 && backend.sentDrafts[0].sendKey == sentKey)
        #expect(backend.sentDrafts[0].attachments.count == 2)
    }

    @Test func aRemoveCannotReachTheFilesOfAnInFlightSend() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle"))
        composer.text = "First"
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        let inFlightAttachment = composer.attachments[0]
        backend.slowSend = .milliseconds(100)
        async let inFlight: Void = composer.send(delivery: .steer)
        while !composer.isSending { await Task.yield() }
        await composer.remove(inFlightAttachment)
        await inFlight
        #expect(backend.discardedReferences.isEmpty)
        #expect(backend.sentDrafts.first?.attachments == [inFlightAttachment])
    }

    @Test func aDraftHasItsSendKeyFromTheFirstCharacter() async {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.text = "a"
        await composer.flush()
        let key = backend.savedDraft(.thread("d0e515ef"))?.sendKey
        #expect(key != nil)
        await composer.send(delivery: .steer)
        #expect(backend.sentDrafts.first?.sendKey == key)
        composer.text = "b"
        await composer.flush()
        #expect(backend.savedDraft(.thread("d0e515ef"))?.sendKey != key)
    }

    @Test func aLateSaveCannotBringASentDraftBack() async {
        let backend = FakeComposerBackend()
        backend.slowSave = .milliseconds(150)
        let clock = StepClock()
        let composer = makeModel(backend: backend, clock: clock)
        composer.text = "Sent soon"
        await clock.advance(by: .milliseconds(500))
        try? await Task.sleep(for: .milliseconds(30))
        await composer.send(delivery: .steer)
        try? await Task.sleep(for: .milliseconds(400))
        #expect(backend.sentDrafts.count == 1)
        #expect(backend.savedDraft(.thread("d0e515ef")) == nil)
    }

    @Test func aDraftThatLoadsWithMissingFilesDropsThemAndSaysSo() async {
        let backend = FakeComposerBackend()
        let kept = OutboxAttachment(
            reference: "Image #2", fileName: "k.jpg", contentType: "image/jpeg", path: "a/k.jpg")
        backend.keep(imageData(), at: kept.path)
        backend.seed(
            ComposerDraft(text: "Hi", sendKey: UUID(), attachments: [attachment("Image #1"), kept]),
            for: .thread("d0e515ef"))
        let composer = makeModel(backend: backend, clock: StepClock())
        await composer.load()
        #expect(composer.attachments == [kept])
        #expect(composer.problem == "An attachment of this draft is gone from this device, so it was removed.")
        #expect(backend.savedDraft(.thread("d0e515ef"))?.attachments == [kept])
    }

    @Test func anAddThatWaitsForTheLimitsAlreadyCountsAsBeingAdded() async throws {
        let backend = FakeComposerBackend()
        backend.slowLimits = .milliseconds(150)
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.text = "Hi"
        let adding = Task { await composer.add(imageData(), fileName: "a.png", contentType: "image/png") }
        await Task.yield()
        await Task.yield()
        #expect(composer.preparingCount == 1 && !composer.canSend)
        await adding.value
        #expect(composer.preparingCount == 0 && composer.canSend)
    }

    // MARK: Picks and Edit

    @Test func aPickCountsAsBeingAddedFromThePickUntilEveryItemIsDone() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle"))
        composer.text = "Hi"
        composer.reservePicks(3)
        #expect(composer.preparingCount == 3 && !composer.canSend)
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png", isReserved: true)
        #expect(composer.preparingCount == 2 && !composer.canSend)
        composer.releasePick()
        #expect(composer.preparingCount == 1 && !composer.canSend && composer.problem == "This file could not be read.")
        await composer.add(imageData(), fileName: "b.png", contentType: "image/png", isReserved: true)
        #expect(composer.preparingCount == 0 && composer.canSend)
        #expect(composer.attachments.count == 2)
    }

    @Test func releasingOnePickKeepsTheSendKeyWhileAnotherIsStillStoring() async throws {
        let composer = makeModel(backend: FakeComposerBackend(), clock: StepClock())
        composer.reservePicks(2)
        #expect(composer.sendKey != nil)
        composer.releasePick()
        #expect(composer.sendKey != nil)
        composer.releasePick()
        #expect(composer.sendKey == nil)
    }

    @Test func aReservedPickStillHonorsTheFileLimit() async throws {
        let backend = FakeComposerBackend()
        backend.maximumFiles = 1
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.reservePicks(2)
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png", isReserved: true)
        await composer.add(imageData(), fileName: "b.png", contentType: "image/png", isReserved: true)
        #expect(composer.attachments.count == 1 && composer.preparingCount == 0)
        #expect(composer.problem == "You can attach up to 1 file.")
    }

    @Test func aDropOfAsManyFilesAsFreePlacesAttachesAllOfThemWithTheRealCount() async throws {
        let composer = makeModel(backend: FakeComposerBackend(), clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle"))
        let drop = ComposerDropModel.make(for: composer)
        let data = imageData()
        await drop.receive(
            count: composer.maximumFiles,
            stat: { _ in (DroppedItem(byteCount: data.count, isImage: true), "a.png") },
            load: { index, _ in (data, "file\(index).png", "image/png") })
        #expect(composer.attachments.count == composer.maximumFiles)
        #expect(drop.line == nil)
    }

    @Test func editOnARefusedItemMovesItsFilesIntoTheDraftAndTheNextSendReallySendsThem() async throws {
        let backend = OutboxBackedComposerBackend()
        let composer = ComposerModel(key: .thread("d0e515ef"), backend: backend, clock: StepClock())
        composer.update(snapshot: try snapshot(state: "idle"))
        composer.text = "Look at this"
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        let sentAttachment = try #require(composer.attachments.first)
        await composer.send(delivery: .steer)
        #expect(composer.text == "" && composer.attachments.isEmpty)
        let refused = try #require(await backend.waitForRefusal())

        composer.text = "Typed meanwhile"
        let isRestored = await composer.restore(refused)
        #expect(isRestored)
        #expect(await backend.outbox.items().isEmpty)
        #expect(composer.text == "Look at this\nTyped meanwhile")
        #expect(composer.attachments == [sentAttachment])
        #expect(try backend.files.read(sentAttachment.path) != nil)

        await composer.send(delivery: .steer)
        let again = try #require(await backend.outbox.items().first)
        #expect(again.text == "Look at this\nTyped meanwhile" && again.attachments == [sentAttachment])
        #expect(again.id == refused.id)
        #expect(try backend.files.read(sentAttachment.path) != nil)
    }

    @Test func editOnAnItemTheOutboxHasNotGivenUpChangesNothing() async throws {
        let backend = FakeComposerBackend()
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.text = "Mine"
        let isRestored = await composer.restore(refusedItem(text: "Gone", attachments: [attachment("Image #1")]))
        #expect(!isRestored)
        #expect(composer.text == "Mine" && composer.attachments.isEmpty)
        #expect(composer.problem == "This message cannot be edited any more.")
    }

    @Test func editWhenTheOutboxCannotSaveSaysSavingFailedAndChangesNothing() async throws {
        let backend = FakeComposerBackend()
        backend.takeFailsToSave = true
        let composer = makeModel(backend: backend, clock: StepClock())
        composer.text = "Mine"
        let isRestored = await composer.restore(refusedItem(text: "Kept", attachments: []))
        #expect(!isRestored)
        #expect(composer.text == "Mine")
        #expect(composer.problem?.contains("saving failed") == true)
    }
}
