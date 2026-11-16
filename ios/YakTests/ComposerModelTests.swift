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
        for _ in 0..<2000 where lock.withLock({ sleepers.isEmpty }) { await Task.yield() }
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
    private var attachmentLimits: Me.AttachmentLimits? = try? JSONDecoder().decode(
        Me.AttachmentLimits.self, from: Data(#"{"maxFiles":8,"maxFileBytes":10485760}"#.utf8))

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
        lock.withLock { stored.append((reference, sendKey)) }
        return OutboxAttachment(
            reference: reference, fileName: prepared.fileName, contentType: prepared.contentType,
            path: "attachments/\(sendKey)/\(prepared.fileName)")
    }

    func discardAttachment(_ attachment: OutboxAttachment) async { lock.withLock { discarded.append(attachment) } }

    func limits() async -> Me.AttachmentLimits? { lock.withLock { attachmentLimits } }

    func stop() async -> ThreadActionOutcome {
        lock.withLock { stopCalls += 1 }
        if let delay = slowStop { try? await Task.sleep(for: delay) }
        return stopResult
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

private func snapshot(state: String, nextAttachmentNumber: Int = 1, models: String = "[]", canDrive: Bool = true) throws
    -> ConversationSnapshot
{
    let json = """
        {"thread":{"id":"d0e515ef","name":"retry-failed-rows","title":"Retry failed rows","repo":"example/api",
        "kind":"root","status":"open","visibility":"team","branch":null,"sandboxState":"running","runnerState":"idle",
        "blockedReason":null,"parentThreadId":null,"rootThreadId":null,"lastActivityAt":"2026-11-15T09:30:00Z",
        "pullRequestCount":0,"isOwner":true,"isStarred":false},"canDrive":\(canDrive),"messages":[],
        "composer":{"state":"\(state)","model":"sonnet","accountName":"Test account","nextAttachmentNumber":\(nextAttachmentNumber),"models":\(models)},
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
        again.restore(refusedItem(text: "Old text", attachments: [attachment("Image #1")]))
        #expect(again.text == "Old text")
        #expect(again.attachments.map(\.reference) == ["Image #1"])
    }

    @Test func aSuccessfulSendClearsTheFieldAndTheDraft() async throws {
        let backend = FakeComposerBackend()
        let clock = StepClock()
        let sendKey = UUID()
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
        #expect(composer.refusalCount == 1)
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
        await Task.yield()
        composer.text = "First and more"
        await inFlight
        #expect(composer.text == "First and more")
        #expect(backend.savedDraft(.thread("d0e515ef"))?.text == "First and more")
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

    @Test func theCountIsCheckedFirst() async {
        let backend = FakeComposerBackend()
        backend.maximumFiles = 1
        let composer = makeModel(backend: backend, clock: StepClock())
        await composer.add(imageData(), fileName: "a.png", contentType: "image/png")
        await composer.add(imageData(), fileName: "b.png", contentType: "image/png")
        #expect(composer.attachments.count == 1 && backend.storedReferences.count == 1)
        #expect(composer.problem == "You can attach up to 1 files.")
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
}
