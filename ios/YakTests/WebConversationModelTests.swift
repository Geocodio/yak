import Foundation
import Testing
import YakKit

@testable import Yak

private let instanceURL = URL(string: "https://yak.example.com")!

@MainActor private final class FakePage: WebConversationPage {
    var redeems: [URLRequest] = []
    var messages: [EmbedAppMessage] = []
    var quickLooks: [URL] = []
    var isHidden = false
    var archive: Data? = Data("archive".utf8)
    var captures = 0
    var pauses = 0
    var beforeArchive: (@MainActor () async -> Void)?

    func captureArchive(_ completion: @escaping @MainActor (Data?) -> Void) {
        captures += 1
        let hook = beforeArchive
        let data = archive
        Task {
            await hook?()
            completion(data)
        }
    }

    func loadRedeem(_ request: URLRequest) { redeems.append(request) }
    func deliver(_ message: EmbedAppMessage) { messages.append(message) }
    func quickLook(url: URL, name: String) { quickLooks.append(url) }
    func setHidden(_ isHidden: Bool) { self.isHidden = isHidden }
    func pauseMedia() { pauses += 1 }
}

/// A sleep the test releases by hand: no real time passes, and a cancelled sleep ends at once.
private actor SleepGate {
    private var waiters: [Int: (duration: Duration, continuation: CheckedContinuation<Void, any Error>)] = [:]
    private var next = 0

    func sleep(_ duration: Duration) async throws {
        let id = next
        next += 1
        try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { waiters[id] = (duration, $0) }
        } onCancel: {
            Task { await self.cancel(id) }
        }
    }

    private func cancel(_ id: Int) {
        waiters.removeValue(forKey: id)?.continuation.resume(throwing: CancellationError())
    }

    func isWaiting(for duration: Duration) -> Bool { waiters.values.contains { $0.duration == duration } }

    /// Ends every sleep of `duration`, as if that long had passed.
    func release(_ duration: Duration) {
        for (id, waiter) in waiters where waiter.duration == duration {
            waiters.removeValue(forKey: id)
            waiter.continuation.resume()
        }
    }
}

@MainActor private final class Harness {
    var paths: [String] = []
    var outcomes: [WebConversationModel.MintOutcome] = []
    var now = ContinuousClock.now
    var requests: [WebConversationRequest] = []
    let page = FakePage()
    let model: WebConversationModel
    let files = InMemoryFileStore()
    let store: OfflineCopyStore
    static let instanceID = UUID()

    static func session(code: String = "yakweb_abc123", redeemUrl: String? = nil) -> WebSession {
        WebSession(
            redeemUrl: redeemUrl ?? "https://yak.example.com/app/embed/session", code: code,
            expiresAt: "2026-11-20T10:00:00Z"
        )
    }

    init(
        supports: Bool = true, attachesPage: Bool = true, hasPane: Bool = true,
        sleep: @escaping @Sendable (Duration) async throws -> Void = { _ in try await Task.sleep(for: .seconds(3600)) }
    ) {
        var box: Harness?
        let store = OfflineCopyStore(instance: Self.instanceID, files: files)
        self.store = store
        model = WebConversationModel(
            instanceURL: instanceURL, allowsLoopbackHTTP: false,
            mint: { path in
                guard let harness = box else { return .failed(.network) }
                harness.paths.append(path)
                return harness.outcomes.isEmpty ? .minted(Self.session()) : harness.outcomes.removeFirst()
            },
            copies: { store },
            now: { box?.now ?? ContinuousClock.now }, sleep: sleep)
        box = self
        if attachesPage { model.attach(page) }
        if hasPane { model.takeRequests(owner: self) { [unowned self] in requests.append($0) } }
        model.configure(supportsEmbeddedThread: supports)
    }

    func waitForMints(_ count: Int) async -> Bool { await TestTimeScale.poll { paths.count >= count } }
    func waitForRedeems(_ count: Int) async -> Bool { await TestTimeScale.poll { page.redeems.count >= count } }

    static func ready(_ threadID: String, title: String = "Title") -> EmbedPageMessage {
        .ready(threadID: threadID, path: "/app/embed/threads/\(threadID)", latestSequence: 1, title: title)
    }

    /// Takes the page to ready for `threadID` through the whole path.
    func open(_ threadID: String) async {
        model.show(thread: threadID)
        #expect(await waitForRedeems(page.redeems.count + 1))
        model.navigationCommitted()
        model.receive(Self.ready(threadID))
    }

    func outboxItem(_ text: String, thread: String = "d0e515ef") -> OutboxItem {
        OutboxItem(
            id: UUID(), target: .thread(thread), text: text, model: nil, delivery: .steer, attachments: [],
            createdAt: Date(timeIntervalSince1970: 1_800_000_000))
    }
}

@MainActor @Suite struct WebConversationModelTests {
    // MARK: Saved copies

    @Test func aReadyPageIsSavedOnceItHasBeenStill() async {
        let gate = SleepGate()
        let harness = Harness(sleep: { try await gate.sleep($0) })
        await harness.open("d0e515ef")
        #expect(await TestTimeScale.poll { await gate.isWaiting(for: WebConversationModel.captureQuietPeriod) })
        #expect(await harness.store.entries().isEmpty)
        await gate.release(WebConversationModel.captureQuietPeriod)
        #expect(await TestTimeScale.poll { await harness.store.entries().count == 1 })
        #expect(await harness.store.copy(thread: "d0e515ef")?.archive == Data("archive".utf8))
    }

    @Test func aPageThatIsNotReadyOrIsOfflineIsNotSaved() async {
        let harness = Harness()
        harness.model.show(thread: "d0e515ef")
        #expect(await harness.waitForRedeems(1))
        await harness.model.saveCopy()
        #expect(harness.page.captures == 0)
        harness.model.navigationCommitted()
        harness.model.receive(Harness.ready("d0e515ef"))
        harness.model.connectivityChanged(isOnline: false)
        await harness.model.saveCopy()
        #expect(harness.page.captures == 0 && harness.model.savedCopy == nil)
        harness.model.connectivityChanged(isOnline: true)
        harness.page.archive = nil
        await harness.model.saveCopy()
        #expect(await harness.store.entries().isEmpty)
    }

    @Test func anArchiveThatFinishesAfterTheCopiesWereErasedIsDropped() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.page.beforeArchive = { await harness.store.invalidate() }
        await harness.model.saveCopy()
        #expect(await harness.store.entries().isEmpty)
        #expect(harness.page.captures == 1)
    }

    @Test func aSessionThatEndedCancelsTheWaitingSave() async {
        let gate = SleepGate()
        let harness = Harness(sleep: { try await gate.sleep($0) })
        await harness.open("d0e515ef")
        #expect(await TestTimeScale.poll { await gate.isWaiting(for: WebConversationModel.captureQuietPeriod) })
        harness.model.sessionEnded()
        await gate.release(WebConversationModel.captureQuietPeriod)
        try? await Task.sleep(for: .milliseconds(100))
        #expect(harness.page.captures == 0)
    }

    @Test func leavingTheForegroundSavesTheCopyAtOnce() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        var held = 0
        var ended = 0
        harness.model.holdBackgroundTime = {
            held += 1
            return { ended += 1 }
        }
        harness.model.appLeftForeground()
        #expect(await TestTimeScale.poll { await harness.store.entries().count == 1 })
        #expect(await TestTimeScale.poll { ended >= 1 })
        #expect(held == 1)
    }

    @Test func theBackgroundTimeEndsWhenThereIsNothingToSave() async {
        let harness = Harness()
        var ended = 0
        harness.model.holdBackgroundTime = { { ended += 1 } }
        harness.model.appLeftForeground()
        #expect(await TestTimeScale.poll { ended >= 1 })
    }

    @Test func leavingAThreadSavesItsCopyBeforeThePageIsToldToSwitch() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        #expect(await TestTimeScale.poll { await harness.store.entries().isEmpty })
        harness.model.show(thread: "a1b2c3d4")
        #expect(harness.page.captures == 1)
        #expect(await TestTimeScale.poll { await harness.store.copy(thread: "d0e515ef") != nil })
    }

    @Test func aPageThatMovesToAnotherThreadByItselfIsSavedWhenItIsStill() async {
        let gate = SleepGate()
        let harness = Harness(sleep: { try await gate.sleep($0) })
        await harness.open("d0e515ef")
        harness.model.receive(.threadChanged(threadID: "a1b2c3d4", previousThreadID: "d0e515ef"))
        harness.model.show(thread: "a1b2c3d4")
        harness.model.receive(Harness.ready("a1b2c3d4"))
        try? await Task.sleep(for: .milliseconds(100))
        await gate.release(WebConversationModel.captureQuietPeriod)
        #expect(await TestTimeScale.poll { await harness.store.copy(thread: "a1b2c3d4") != nil })
    }

    @Test func aThreadFirstShownOfflineBeforeTheModeIsKnownStillShowsItsCopy() async {
        let harness = Harness(supports: false)
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        harness.model.configure(supportsEmbeddedThread: true)
        #expect(await TestTimeScale.poll { harness.model.savedCopy != nil })
        #expect(harness.model.phase == .offline && harness.model.savedCopyState == .offline)
    }

    @Test func aSavedCopyTellsAnUndecidedWindowThatThePageIsOffered() async {
        let harness = Harness(supports: false)
        #expect(await !harness.model.hasSavedCopy(of: "d0e515ef"))
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        #expect(await harness.model.hasSavedCopy(of: "d0e515ef"))
        #expect(await !harness.model.hasSavedCopy(of: "a1b2c3d4"))
    }

    @Test func aLostAccessEndsTheCopy() async {
        for failure in [WebConversationMachine.NavigationOutcome.http(403), .http(404)] {
            let harness = Harness()
            _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
            harness.model.connectivityChanged(isOnline: false)
            harness.model.show(thread: "d0e515ef")
            #expect(await TestTimeScale.poll { harness.model.savedCopy != nil })
            harness.model.connectivityChanged(isOnline: true)
            #expect(await harness.waitForRedeems(1))
            harness.model.navigationFailed(failure)
            #expect(harness.model.savedCopy == nil && harness.model.savedCopyState == nil)
            #expect(await TestTimeScale.poll { await harness.store.entries().isEmpty })
        }
    }

    @Test func aRejectedMintEndsTheCopyButAThrottledOneKeepsIt() async {
        let rejected = Harness()
        _ = await rejected.store.save(Data("saved".utf8), thread: "d0e515ef")
        rejected.model.connectivityChanged(isOnline: false)
        rejected.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { rejected.model.savedCopy != nil })
        rejected.outcomes = [.failed(.rejected)]
        rejected.model.connectivityChanged(isOnline: true)
        #expect(await TestTimeScale.poll { rejected.model.phase == .failed(.mintRejected) })
        #expect(rejected.model.savedCopy == nil)
        #expect(await TestTimeScale.poll { await rejected.store.entries().isEmpty })
        let throttled = APIFailure.server(APIError(status: 429, code: "x", message: "", details: [:]))
        #expect(WebConversationModel.mintFailure(of: throttled) == .server(429))
    }

    @Test func aCopyThatCannotBeShownIsDroppedAndTheOfflineStateShows() async {
        let harness = Harness()
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.savedCopy != nil })
        harness.model.savedCopyFailed()
        #expect(harness.model.savedCopy == nil)
        #expect(harness.model.phase == .failed(.network))
        #expect(harness.model.loadPresentation == .offlineWithoutCopy)
        #expect(await TestTimeScale.poll { await harness.store.entries().isEmpty })
    }

    @Test func offlineWithACopyShowsItWithItsTimeAndTheOfflineLine() async {
        let harness = Harness()
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.savedCopy != nil })
        #expect(harness.model.phase == .offline)
        #expect(harness.model.savedCopyState == .offline)
        #expect(harness.model.loadPresentation == .conversation)
        #expect(harness.model.savedCopy?.archive == Data("saved".utf8))
        #expect(harness.page.redeems.isEmpty)
    }

    @Test func clearingTheCopiesInSettingsFallsBackToTheOfflineStateAtOnceAndSavesAgainLater() async {
        let harness = Harness()
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.savedCopy != nil })
        await harness.store.removeAll()
        NotificationCenter.default.post(name: .offlineCopiesCleared, object: nil)
        #expect(await TestTimeScale.poll { harness.model.savedCopy == nil })
        #expect(await TestTimeScale.poll { harness.model.loadPresentation == .offlineWithoutCopy })
        #expect(await harness.store.save(Data("again".utf8), thread: "d0e515ef") == .saved)
    }

    @Test func clearingTheCopiesEndsALookupThatIsStillWaiting() async {
        let store = OfflineCopyStore(instance: UUID(), files: InMemoryFileStore())
        _ = await store.save(Data("saved".utf8), thread: "d0e515ef")
        let gate = LookupGate()
        let model = WebConversationModel(
            instanceURL: instanceURL, allowsLoopbackHTTP: false, mint: { _ in .failed(.network) },
            copies: {
                await gate.wait()
                return store
            })
        model.configure(supportsEmbeddedThread: true)
        model.connectivityChanged(isOnline: false)
        model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { model.phase == .failed(.network) })
        model.copiesWereCleared()
        await gate.release()
        // The finished lookup would find the copy and show it; it was cancelled, so the plain state stays.
        #expect(await !TestTimeScale.poll { model.phase == .offline || model.savedCopy != nil })
        #expect(model.phase == .failed(.network))
    }

    @Test func offlineWithoutACopyKeepsThePlainOfflineState() async {
        let harness = Harness()
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.network) })
        #expect(harness.model.savedCopy == nil && harness.model.savedCopyState == nil)
        #expect(harness.model.loadPresentation == .offlineWithoutCopy)
    }

    @Test func aCopyThatCannotBeReadFallsBackToThePlainOfflineState() async throws {
        let harness = Harness()
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        try harness.files.remove("\(InstanceFolders.offlineCopies(Harness.instanceID))/d0e515ef.webarchive")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.network) })
        #expect(harness.model.savedCopy == nil)
        #expect(harness.model.loadPresentation == .offlineWithoutCopy)
    }

    @Test func theCopyStaysUntilTheLivePageIsReadyAgain() async {
        let harness = Harness()
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.savedCopy != nil })
        harness.model.connectivityChanged(isOnline: true)
        #expect(harness.model.savedCopyState == .reconnecting)
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        #expect(harness.model.savedCopy != nil && harness.model.loadPresentation == .conversation)
        harness.model.receive(Harness.ready("d0e515ef"))
        #expect(harness.model.savedCopy == nil && harness.model.savedCopyState == nil)
    }

    @Test func aSlowOrFailedReconnectKeepsTheCopyWithItsOwnLine() async {
        let gate = SleepGate()
        let harness = Harness(sleep: { try await gate.sleep($0) })
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.savedCopy != nil })
        harness.model.connectivityChanged(isOnline: true)
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        harness.model.timePassed()
        harness.now = harness.now.advanced(by: .seconds(9))
        harness.model.timePassed()
        #expect(harness.model.savedCopyState == .slow)
        harness.now = harness.now.advanced(by: .seconds(31))
        harness.model.timePassed()
        #expect(harness.model.phase == .failed(.timeout))
        #expect(harness.model.savedCopyState == .failed)
        #expect(harness.model.loadPresentation == .conversation)
    }

    @Test func aThreadSwitchWhileOfflineShowsThatThreadsCopyOrTheOfflineState() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        _ = await harness.store.save(Data("other".utf8), thread: "a1b2c3d4")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "a1b2c3d4")
        #expect(await TestTimeScale.poll { harness.model.savedCopy?.threadID == "a1b2c3d4" })
        #expect(harness.page.messages.allSatisfy { if case .open = $0 { false } else { true } })
        harness.model.show(thread: "b2c3d4e5")
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.network) })
        #expect(harness.model.savedCopy == nil && harness.model.loadPresentation == .offlineWithoutCopy)
        harness.model.show(thread: "a1b2c3d4")
        #expect(await TestTimeScale.poll { harness.model.savedCopy?.threadID == "a1b2c3d4" })
    }

    @Test func aThreadThatIsGoneLosesItsCopy() async {
        let harness = Harness()
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.show(thread: "d0e515ef")
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationFailed(.http(404))
        #expect(await TestTimeScale.poll { await harness.store.entries().isEmpty })
    }

    @Test func aServerWithoutTheEmbeddedPageAsksForAnUpdate() async {
        let harness = Harness(supports: false)
        harness.model.show(thread: "d0e515ef")
        #expect(harness.model.mode == .unsupported)
        #expect(harness.model.phase == .unsupported)
        #expect(harness.model.loadPresentation == .serverNeedsUpdate)
        try? await Task.sleep(for: .milliseconds(50))
        #expect(harness.paths.isEmpty && harness.page.redeems.isEmpty)
    }

    @Test func theChoiceFollowsAChangeOfTheServersAnswer() async {
        let harness = Harness(supports: false)
        harness.model.show(thread: "d0e515ef")
        harness.model.configure(supportsEmbeddedThread: false)
        #expect(harness.model.mode == .unsupported)
        harness.model.configure(supportsEmbeddedThread: true)
        #expect(harness.model.mode == .web)
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        harness.model.receive(Harness.ready("d0e515ef"))
        #expect(harness.model.loadPresentation == .conversation)
        harness.model.configure(supportsEmbeddedThread: false)
        #expect(harness.model.mode == .unsupported && harness.model.phase == .unsupported)
        harness.model.configure(supportsEmbeddedThread: true)
        #expect(await harness.waitForRedeems(2))
        #expect(harness.model.loadPresentation == .loading)
    }

    @Test func aModelThatHasNotChosenYetMintsNothing() async {
        var mints = 0
        let undecided = WebConversationModel(
            instanceURL: instanceURL, allowsLoopbackHTTP: false,
            mint: { _ in
                mints += 1
                return .failed(.network)
            })
        undecided.show(thread: "d0e515ef")
        try? await Task.sleep(for: .milliseconds(50))
        #expect(undecided.mode == .undecided && mints == 0)
    }

    @Test func theMintEffectAsksForTheShownThreadAndTheRedeemEffectLoadsThePost() async throws {
        let harness = Harness()
        harness.model.show(thread: "d0e515ef")
        #expect(harness.model.phase == .loading(.minting))
        #expect(await harness.waitForRedeems(1))
        #expect(harness.paths == ["/app/embed/threads/d0e515ef"])
        let request = try #require(harness.page.redeems.first)
        #expect(request.httpMethod == "POST")
        #expect(String(decoding: request.httpBody ?? Data(), as: UTF8.self) == "code=yakweb_abc123")
        #expect(harness.model.phase == .loading(.redeeming))
        #expect(harness.model.loadPresentation == .loading)
    }

    @Test func aRequestThatWaitedForTheWebViewLoadsWhenItIsMade() async {
        let harness = Harness(attachesPage: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.phase == .loading(.redeeming) })
        #expect(harness.page.redeems.isEmpty)
        harness.model.attach(harness.page)
        #expect(harness.page.redeems.count == 1)
    }

    @Test func theCommitAndTheReadyMessageShowThePage() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        #expect(harness.model.phase == .ready)
        #expect(harness.model.loadPresentation == .conversation)
    }

    @Test func aMintThatFailsOnTheNetworkShowsAFailureAndRetryMintsAgain() async {
        let harness = Harness()
        harness.outcomes = [.failed(.network)]
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.network) })
        #expect(harness.model.loadPresentation == .serverError(message: String(localized: "web.failed.network")))
        harness.model.retry()
        #expect(await harness.waitForRedeems(1))
        #expect(harness.paths.count == 2)
    }

    @Test func aRedeemAddressOnAnotherHostIsARefusedMint() async {
        let harness = Harness()
        harness.outcomes = [.minted(Harness.session(redeemUrl: "https://evil.example.net/app/embed/session"))]
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.mintRejected) })
        #expect(harness.page.redeems.isEmpty)
    }

    @Test func aRefusedSessionIsRenewedOnceAndThenFailsInsteadOfLooping() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.navigationFailed(.http(401))
        #expect(await harness.waitForMints(2))
        #expect(await harness.waitForRedeems(2))
        harness.model.navigationFailed(.http(401))
        #expect(harness.model.phase == .failed(.sessionExpired))
        try? await Task.sleep(for: .milliseconds(50))
        #expect(harness.paths.count == 2)
    }

    @Test func theAuthMessageOfThePageMintsANewCode() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.receive(.authExpired)
        #expect(
            harness.model.phase == .signedOut || harness.model.phase == .loading(.minting)
                || harness.model.phase == .loading(.redeeming))
        #expect(await harness.waitForRedeems(2))
        #expect(harness.model.loadPresentation == .loading)
    }

    @Test func aServerErrorOnTheMainFrameIsAFailure() async {
        let harness = Harness()
        harness.model.show(thread: "d0e515ef")
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationFailed(.http(503))
        #expect(harness.model.phase == .failed(.server(503)))
        #expect(harness.model.loadPresentation == .serverError(message: String(localized: "web.failed.server")))
    }

    @Test func aPageThatNeverSaysReadyBecomesSlowThenFails() async {
        let harness = Harness()
        harness.model.show(thread: "d0e515ef")
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        harness.now = harness.now.advanced(by: .seconds(9))
        harness.model.timePassed()
        #expect(harness.model.phase == .slow)
        #expect(harness.model.loadPresentation == .slow)
        harness.now = harness.now.advanced(by: .seconds(30))
        harness.model.timePassed()
        #expect(harness.model.phase == .failed(.timeout))
        #expect(harness.model.loadPresentation == .serverError(message: String(localized: "web.failed.timeout")))
    }

    @Test func switchingThreadsAsksTheOpenPageAndDoesNotMintAgain() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.show(thread: "a1b2c3d4")
        #expect(harness.page.messages == [.open(threadID: "a1b2c3d4", fragment: nil)])
        // The old thread's page stays covered until the new thread is ready.
        #expect(harness.model.loadPresentation == .loading)
        harness.model.receive(.threadChanged(threadID: "a1b2c3d4", previousThreadID: "d0e515ef"))
        harness.model.receive(Harness.ready("a1b2c3d4"))
        #expect(harness.model.loadPresentation == .conversation)
        #expect(harness.paths.count == 1 && harness.page.redeems.count == 1)
        #expect(harness.requests.isEmpty)
    }

    @Test func aThreadChangeThePageMadeByItselfIsHandedToThePane() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.receive(.threadChanged(threadID: "a1b2c3d4", previousThreadID: "d0e515ef"))
        #expect(harness.requests == [.threadChanged(threadID: "a1b2c3d4")])
    }

    @Test func aSwitchThePageDoesNotAnswerWithinTheTimeoutStartsTheSessionOver() async {
        let gate = SleepGate()
        let harness = Harness(sleep: { try await gate.sleep($0) })
        await harness.open("d0e515ef")
        harness.model.show(thread: "a1b2c3d4")
        #expect(harness.page.messages == [.open(threadID: "a1b2c3d4", fragment: nil)])
        #expect(await TestTimeScale.poll { await gate.isWaiting(for: WebConversationModel.switchTimeout) })
        await gate.release(WebConversationModel.switchTimeout)
        #expect(await harness.waitForRedeems(2))
        #expect(harness.paths == ["/app/embed/threads/d0e515ef", "/app/embed/threads/a1b2c3d4"])
    }

    @Test func aSwitchThePageAnswersInTimeIsNotStartedOverAndItsTimerIsCancelled() async {
        let gate = SleepGate()
        let harness = Harness(sleep: { try await gate.sleep($0) })
        await harness.open("d0e515ef")
        harness.model.show(thread: "a1b2c3d4")
        harness.model.receive(.threadChanged(threadID: "a1b2c3d4", previousThreadID: "d0e515ef"))
        harness.model.receive(Harness.ready("a1b2c3d4"))
        #expect(await TestTimeScale.poll { await !gate.isWaiting(for: WebConversationModel.switchTimeout) })
        await gate.release(WebConversationModel.switchTimeout)
        await Task.yield()
        #expect(harness.page.redeems.count == 1 && harness.paths.count == 1)
    }

    @Test func aFastSwitchFromAToBAndBackEndsOnAWithoutAMint() async {
        let gate = SleepGate()
        let harness = Harness(sleep: { try await gate.sleep($0) })
        await harness.open("d0e515ef")
        harness.model.show(thread: "a1b2c3d4")
        harness.model.show(thread: "d0e515ef")
        // The page answers the first ask late, and the app asks for A again.
        harness.model.receive(.threadChanged(threadID: "a1b2c3d4", previousThreadID: "d0e515ef"))
        harness.model.receive(Harness.ready("a1b2c3d4"))
        #expect(
            harness.page.messages.suffix(2) == [
                .open(threadID: "a1b2c3d4", fragment: nil), .open(threadID: "d0e515ef", fragment: nil),
            ])
        #expect(harness.model.loadPresentation == .loading)
        harness.model.receive(.threadChanged(threadID: "d0e515ef", previousThreadID: "a1b2c3d4"))
        harness.model.receive(Harness.ready("d0e515ef"))
        #expect(harness.model.loadPresentation == .conversation)
        #expect(harness.paths.count == 1 && harness.page.redeems.count == 1)
        #expect(harness.requests.isEmpty)
    }

    @Test func aFailedPageStartsOverWhenAnotherThreadIsShown() async {
        let harness = Harness()
        harness.outcomes = [.failed(.network)]
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.network) })
        harness.model.show(thread: "a1b2c3d4")
        #expect(await harness.waitForRedeems(1))
        #expect(harness.paths == ["/app/embed/threads/d0e515ef", "/app/embed/threads/a1b2c3d4"])
    }

    @Test func aWebProcessThatEndedLoadsThePageWithANewSession() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.pageProcessEnded()
        #expect(await harness.waitForRedeems(2))
    }

    @Test func aPageThatKeepsCrashingEndsInAFailureWithRetryInsteadOfLooping() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.pageProcessEnded()
        #expect(await harness.waitForRedeems(2))
        harness.now = harness.now.advanced(by: .seconds(5))
        harness.model.pageProcessEnded()
        #expect(harness.model.phase == .failed(.pageCrashed))
        #expect(harness.model.loadPresentation == .serverError(message: String(localized: "web.failed.crashed")))
        try? await Task.sleep(for: .milliseconds(50))
        #expect(harness.paths.count == 2 && harness.page.redeems.count == 2)
        harness.model.retry()
        #expect(await harness.waitForRedeems(3))
    }

    @Test func aCrashLongAfterTheLastOneLoadsThePageAgain() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.pageProcessEnded()
        #expect(await harness.waitForRedeems(2))
        harness.now = harness.now.advanced(by: .seconds(31))
        harness.model.pageProcessEnded()
        #expect(await harness.waitForRedeems(3))
    }

    @Test func aDeviceThatIsNoLongerAuthorizedLeavesTheScreenToTheSession() {
        #expect(WebConversationModel.presentation(of: .deviceUnauthorized, isOnline: true) == .sessionOwns)
    }

    @Test func theOutboxReachesThePageOnlyWhenItChangesAndIsReady() async {
        let harness = Harness()
        let item = harness.outboxItem("Write tests")
        harness.model.outboxChanged(threadID: "d0e515ef", items: [item])
        #expect(harness.page.messages.isEmpty)
        await harness.open("d0e515ef")
        // The list that waited is sent as soon as the page is ready.
        let expected = EmbedOutboxProjection.message(threadID: "d0e515ef", items: [item])
        #expect(harness.page.messages == [expected])
        harness.model.outboxChanged(threadID: "d0e515ef", items: [item])
        #expect(harness.page.messages.count == 1)
        harness.model.outboxChanged(threadID: "d0e515ef", items: [])
        #expect(harness.page.messages.last == .outbox(threadID: "d0e515ef", items: []))
    }

    @Test func anOutboxUpdateOfTheOldThreadDoesNotReplaceTheNewThreadsList() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        let newer = harness.outboxItem("For the new thread", thread: "a1b2c3d4")
        harness.model.outboxChanged(threadID: "a1b2c3d4", items: [newer])
        harness.model.show(thread: "a1b2c3d4")
        // The old pane reports last, while the page cross-fades.
        harness.model.outboxChanged(threadID: "d0e515ef", items: [])
        harness.model.receive(.threadChanged(threadID: "a1b2c3d4", previousThreadID: "d0e515ef"))
        harness.model.receive(Harness.ready("a1b2c3d4"))
        #expect(
            harness.page.messages.last == EmbedOutboxProjection.message(threadID: "a1b2c3d4", items: [newer]))
    }

    @Test func anAcceptedSendRefreshesThePageAndAnotherThreadsOutboxIsNotSent() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.messageDelivered()
        #expect(harness.page.messages == [.refresh])
        harness.model.outboxChanged(threadID: "a1b2c3d4", items: [harness.outboxItem("Other", thread: "a1b2c3d4")])
        #expect(harness.page.messages == [.refresh])
    }

    @Test func connectivityIsToldToThePageAndShowsTheOfflineLine() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        #expect(!harness.model.showsOfflineLine)
        harness.model.connectivityChanged(isOnline: false)
        #expect(harness.model.showsOfflineLine)
        #expect(harness.page.messages == [.connectivity(isOnline: false)])
        harness.model.connectivityChanged(isOnline: false)
        #expect(harness.page.messages.count == 1)
        harness.model.connectivityChanged(isOnline: true)
        #expect(!harness.model.showsOfflineLine)
    }

    @Test func comingBackOnlineMintsAgainAfterANetworkFailure() async {
        let harness = Harness()
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.network) })
        #expect(harness.model.loadPresentation == .offlineWithoutCopy)
        #expect(harness.paths.isEmpty, "no code is asked for while offline")
        harness.model.connectivityChanged(isOnline: true)
        #expect(await harness.waitForRedeems(1))
    }

    @Test func aLaunchWhileOfflineShowsTheOfflineStateAndRecoversOnReconnect() async {
        let harness = Harness()
        // The pane reports the connection as soon as it appears, before it asks for the thread.
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.network) })
        #expect(harness.model.loadPresentation == .offlineWithoutCopy)
        #expect(harness.paths.isEmpty, "no code is asked for while offline")
        harness.model.connectivityChanged(isOnline: true)
        #expect(await harness.waitForRedeems(1))
        #expect(harness.model.loadPresentation == .loading)
    }

    @Test func messagesThatNeedThePaneAreHandedToIt() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        let link = URL(string: "https://example.com/docs")!
        harness.model.receive(.link(link))
        harness.model.receive(.openPane(.terminal))
        harness.model.receive(.cardAnswered(card: .question, action: "answer", isOK: true))
        let key = UUID()
        harness.model.receive(.unsentAction(key: key, action: .retry))
        harness.model.receive(.unknown(type: "future"))
        #expect(
            harness.requests == [
                .link(link), .openPane(.terminal), .cardAnswered(card: .question, action: "answer", isOK: true),
                .unsent(key: key, action: .retry),
            ])
    }

    @Test func aPaneThatGoesAwayLetsGoOfTheRequestsUnlessANewerPaneHasThem() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        final class Owner {}
        let first = Owner()
        let second = Owner()
        var firstHeard: [WebConversationRequest] = []
        var secondHeard: [WebConversationRequest] = []
        harness.model.takeRequests(owner: first) { firstHeard.append($0) }
        harness.model.takeRequests(owner: second) { secondHeard.append($0) }
        harness.model.releaseRequests(owner: first)
        harness.model.receive(.openPane(.terminal))
        #expect(firstHeard.isEmpty && secondHeard == [.openPane(.terminal)])
        harness.model.releaseRequests(owner: second)
        harness.model.receive(.openPane(.preview(url: nil)))
        #expect(secondHeard == [.openPane(.terminal)])
    }

    @Test func aLinkToAThreadOfTheInstanceOpensInTheAppAndOtherLinksInTheBrowser() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.receive(.link(URL(string: "https://yak.example.com/threads/a1b2c3d4#card-question")!))
        harness.model.receive(.link(URL(string: "https://yak.example.com/threads/a1b2c3d4/files")!))
        harness.model.receive(.link(URL(string: "https://other.example.com/threads/a1b2c3d4")!))
        #expect(
            harness.requests == [
                .openThread("a1b2c3d4"), .link(URL(string: "https://yak.example.com/threads/a1b2c3d4/files")!),
                .link(URL(string: "https://other.example.com/threads/a1b2c3d4")!),
            ])
    }

    @Test func aNoticeShowsAndGoesAwayByItself() async {
        let model = WebConversationModel(
            instanceURL: instanceURL, allowsLoopbackHTTP: false, mint: { _ in .failed(.network) }, sleep: { _ in })
        model.showNotice("The file could not be opened.")
        #expect(model.noticeLine == "The file could not be opened.")
        #expect(await TestTimeScale.poll { model.noticeLine == nil })
    }

    @Test func aQuickLookGoesToTheWebView() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        let file = URL(string: "https://yak.example.com/files/report.pdf")!
        harness.model.receive(.quickLook(url: file, name: "report.pdf", mimeType: nil))
        #expect(harness.page.quickLooks == [file])
    }

    @Test func thePageIsHiddenWhileTheAppIsCovered() {
        let harness = Harness()
        harness.model.setPageHidden(true)
        #expect(harness.page.isHidden)
        let later = FakePage()
        harness.model.attach(later)
        #expect(later.isHidden)
        harness.model.setPageHidden(false)
        #expect(!later.isHidden)
    }

    @Test func eachFailureHasItsOwnWords() {
        let network = WebConversationModel.presentation(of: .network, isOnline: true)
        let offline = WebConversationModel.presentation(of: .network, isOnline: false)
        let server = WebConversationModel.presentation(of: .server(500), isOnline: true)
        let timeout = WebConversationModel.presentation(of: .timeout, isOnline: true)
        let session = WebConversationModel.presentation(of: .sessionExpired, isOnline: true)
        #expect(offline == .offlineWithoutCopy)
        #expect(WebConversationModel.presentation(of: .notFound, isOnline: true) == .notFound)
        #expect(Set([network, server, timeout, session].map { "\($0)" }).count == 4)
        #expect(session == WebConversationModel.presentation(of: .mintRejected, isOnline: true))
    }

    @Test func aMintFailureIsMappedFromTheApiFailure() {
        func map(_ failure: APIFailure) -> WebConversationMachine.MintFailure {
            WebConversationModel.mintFailure(of: failure)
        }
        #expect(map(.network(.notConnectedToInternet)) == .network)
        #expect(map(.unauthorized(code: "device_revoked")) == .unauthorized)
        #expect(map(.server(APIError(status: 503, code: "x", message: "", details: [:]))) == .server(503))
        #expect(map(.server(APIError(status: 422, code: "x", message: "", details: [:]))) == .rejected)
        #expect(map(.notYak) == .rejected)
    }
}

// MARK: Focus targets

private func target(_ thread: String = "d0e515ef", _ fragment: String = "card-question") -> PendingFocus {
    PendingFocus(threadID: thread, fragment: fragment)!
}

@MainActor private func focusMessages(_ page: FakePage) -> [EmbedAppMessage] {
    page.messages.filter { if case .focus = $0 { true } else { false } }
}

@MainActor @Suite struct WebConversationFocusTests {
    @Test func aTargetIsSentOnceAfterThePageReportsReady() async {
        let harness = Harness()
        harness.model.show(thread: "d0e515ef")
        harness.model.focus(target())
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        #expect(focusMessages(harness.page).isEmpty)
        harness.model.receive(Harness.ready("d0e515ef"))
        #expect(focusMessages(harness.page) == [.focus(fragment: "card-question")])
        #expect(harness.model.pendingFocusTarget == nil)
        harness.model.receive(Harness.ready("d0e515ef"))
        #expect(focusMessages(harness.page).count == 1)
    }

    @Test func aTargetForAnOpenPageScrollsItWithoutALoad() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        let loads = harness.page.redeems.count
        harness.model.focus(target("d0e515ef", "message-9f2c1a4e"))
        #expect(focusMessages(harness.page) == [.focus(fragment: "message-9f2c1a4e")])
        #expect(harness.page.redeems.count == loads)
        #expect(harness.model.phase == .ready)
    }

    @Test func aLaterTargetReplacesOneThatWasNotSent() async {
        let harness = Harness()
        harness.model.show(thread: "d0e515ef")
        harness.model.focus(target("d0e515ef", "card-question"))
        harness.model.focus(target("d0e515ef", "message-9f2c1a4e"))
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        harness.model.receive(Harness.ready("d0e515ef"))
        #expect(focusMessages(harness.page) == [.focus(fragment: "message-9f2c1a4e")])
    }

    @Test func aTargetWaitsForTheThreadSwitchInsideThePage() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.show(thread: "a1b2c3d4")
        harness.model.focus(target("a1b2c3d4"))
        #expect(harness.page.messages.contains(.open(threadID: "a1b2c3d4", fragment: nil)))
        #expect(focusMessages(harness.page).isEmpty)
        harness.model.receive(.threadChanged(threadID: "a1b2c3d4", previousThreadID: "d0e515ef"))
        #expect(focusMessages(harness.page).isEmpty)
        harness.model.receive(Harness.ready("a1b2c3d4"))
        #expect(focusMessages(harness.page) == [.focus(fragment: "card-question")])
    }

    @Test func aTargetForAnotherThreadThanTheShownOneIsLetGo() async {
        let harness = Harness()
        harness.model.show(thread: "d0e515ef")
        harness.model.focus(target("a1b2c3d4"))
        #expect(harness.model.pendingFocusTarget == nil)
    }

    @Test func leavingTheThreadDropsATargetThatNeverGotItsPage() async {
        let harness = Harness()
        harness.model.show(thread: "d0e515ef")
        harness.model.focus(target())
        harness.model.show(thread: "a1b2c3d4")
        #expect(harness.model.pendingFocusTarget == nil)
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        harness.model.receive(Harness.ready("a1b2c3d4"))
        #expect(focusMessages(harness.page).isEmpty)
    }

    @Test func aPaneThatStaysGoneDropsTheTargetButAFastRemountKeepsIt() async {
        let gate = SleepGate()
        let harness = Harness(sleep: { try await gate.sleep($0) })
        let first = NSObject()
        let second = NSObject()
        harness.model.takeRequests(owner: first) { _ in }
        harness.model.show(thread: "d0e515ef")
        harness.model.focus(target())
        harness.model.releaseRequests(owner: first)
        harness.model.takeRequests(owner: second) { _ in }
        await gate.release(WebConversationModel.focusReleaseGrace)
        try? await Task.sleep(for: .milliseconds(50))
        #expect(harness.model.pendingFocusTarget == target())

        harness.model.releaseRequests(owner: second)
        #expect(await TestTimeScale.poll { await gate.isWaiting(for: WebConversationModel.focusReleaseGrace) })
        await gate.release(WebConversationModel.focusReleaseGrace)
        #expect(await TestTimeScale.poll { harness.model.pendingFocusTarget == nil })
    }

    @Test func theNativeChatTakesNoTarget() {
        let harness = Harness(supports: false)
        harness.model.show(thread: "d0e515ef")
        harness.model.focus(target())
        #expect(harness.model.pendingFocusTarget == nil)
    }

    @Test func aMissReportedByThePageChangesNothing() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        harness.model.focus(target("d0e515ef", "message-0000aaaa"))
        harness.model.receive(.focusMissed(fragment: "message-0000aaaa"))
        #expect(harness.model.phase == .ready)
        #expect(harness.model.loadPresentation == .conversation)
        #expect(harness.requests.isEmpty)
        #expect(harness.model.noticeLine == nil)
    }

    @Test func offlineTheSavedCopyIsScrolledToTheTarget() async {
        let harness = Harness()
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        harness.model.focus(target())
        #expect(await TestTimeScale.poll { harness.model.savedCopy != nil })
        #expect(await TestTimeScale.poll { harness.model.copyFocus != nil })
        #expect(harness.model.copyFocus?.fragment == "card-question")
        #expect(harness.model.pendingFocusTarget == nil)
        #expect(focusMessages(harness.page).isEmpty)
    }

    @Test func aTargetWaitsWhileTheLivePageLoadsBehindTheCopy() async {
        let harness = Harness()
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.savedCopy != nil })
        harness.model.connectivityChanged(isOnline: true)
        #expect(harness.model.savedCopyState == .reconnecting)
        harness.model.focus(target())
        #expect(harness.model.copyFocus == nil)
        #expect(harness.model.pendingFocusTarget == target())
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        harness.model.receive(Harness.ready("d0e515ef"))
        #expect(focusMessages(harness.page) == [.focus(fragment: "card-question")])
    }

    @Test func theCopyTargetGoesWhenTheCopyDoes() async {
        let harness = Harness()
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        harness.model.focus(target())
        #expect(await TestTimeScale.poll { harness.model.copyFocus != nil })
        harness.model.savedCopyFailed()
        #expect(harness.model.copyFocus == nil)
    }

    @Test func aCopyThatRanTheTargetLeavesNoneForTheNextView() async {
        let harness = Harness()
        _ = await harness.store.save(Data("saved".utf8), thread: "d0e515ef")
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        harness.model.focus(target())
        #expect(await TestTimeScale.poll { harness.model.copyFocus != nil })
        let serial = harness.model.copyFocus?.serial ?? 0
        harness.model.copyFocusHandled(serial: serial)
        #expect(harness.model.copyFocus == nil)
    }

    @Test func aTargetWaitsWhileTheLockCoversThePage() async {
        let harness = Harness()
        harness.model.setPageHidden(true)
        harness.model.show(thread: "d0e515ef")
        harness.model.focus(target())
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        harness.model.receive(Harness.ready("d0e515ef"))
        #expect(focusMessages(harness.page).isEmpty)
        #expect(harness.model.pendingFocusTarget == target())
        harness.model.setPageHidden(false)
        #expect(focusMessages(harness.page) == [.focus(fragment: "card-question")])
        #expect(harness.model.pendingFocusTarget == nil)
    }

    // MARK: Media

    @Test func lockingAndLeavingTheForegroundPauseTheMediaOfThePage() {
        let harness = Harness()
        harness.model.setPageHidden(false)
        #expect(harness.page.pauses == 0)
        harness.model.setPageHidden(true)
        #expect(harness.page.pauses == 1)
        harness.model.appLeftForeground()
        #expect(harness.page.pauses == 2)
    }

    // MARK: Prewarm

    private static func policy(_ thread: String) -> WebPrewarmPolicy {
        WebPrewarmPolicy(
            isActive: true, isLocked: false, isSessionActive: true, isOnline: true, isLowDataMode: false,
            serverOffersPage: true, hasPreparedPage: false, candidateThreadID: thread)
    }

    private func prewarmHarness(supports: Bool = true) -> Harness {
        let harness = Harness(supports: supports, attachesPage: false, hasPane: false)
        harness.model.makePage = { [page = harness.page] _ in page }
        return harness
    }

    @Test func aPreparedPageIsMintedAndLoadedBeforeAPaneShowsTheThread() async {
        let harness = prewarmHarness()
        #expect(!harness.model.hasPreparedPage)
        harness.model.prewarm(Self.policy("d0e515ef"))
        #expect(harness.model.hasPreparedPage)
        #expect(await harness.waitForRedeems(1))
        #expect(harness.paths == ["/app/embed/threads/d0e515ef"])
        harness.model.navigationCommitted()
        harness.model.receive(Harness.ready("d0e515ef"))
        // The pane that shows the thread finds it ready and asks for nothing more.
        harness.model.show(thread: "d0e515ef")
        #expect(harness.model.loadPresentation == .conversation)
        #expect(harness.paths.count == 1 && harness.page.redeems.count == 1)
    }

    @Test func aPaneThatShowsAnotherThreadSwitchesThePreparedPageWithoutAnotherSession() async {
        let harness = prewarmHarness()
        harness.model.prewarm(Self.policy("d0e515ef"))
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        harness.model.receive(Harness.ready("d0e515ef"))
        harness.model.show(thread: "a1b2c3d4")
        #expect(harness.page.messages.contains(.open(threadID: "a1b2c3d4", fragment: nil)))
        #expect(harness.paths.count == 1 && harness.page.redeems.count == 1)
    }

    @Test func onePageIsPreparedAtMost() async {
        let harness = prewarmHarness()
        harness.model.prewarm(Self.policy("d0e515ef"))
        harness.model.prewarm(Self.policy("a1b2c3d4"))
        #expect(await harness.waitForRedeems(1))
        try? await Task.sleep(for: .milliseconds(50))
        #expect(harness.paths == ["/app/embed/threads/d0e515ef"])
        #expect(harness.model.threadID == "d0e515ef")
    }

    @Test func nothingIsPreparedOnceAThreadIsShown() async {
        let harness = prewarmHarness()
        harness.model.attach(harness.page)
        harness.model.show(thread: "d0e515ef")
        harness.model.prewarm(Self.policy("a1b2c3d4"))
        #expect(harness.model.threadID == "d0e515ef")
        #expect(await harness.waitForRedeems(1))
        try? await Task.sleep(for: .milliseconds(50))
        #expect(harness.paths == ["/app/embed/threads/d0e515ef"])
    }

    @Test func nothingIsPreparedForAServerWithoutThePageOrBeforeTheServerHasAnswered() async {
        let older = prewarmHarness(supports: false)
        older.model.prewarm(Self.policy("d0e515ef"))
        #expect(!older.model.hasPreparedPage)
        let undecided = Harness(supports: true, attachesPage: false, hasPane: false)
        undecided.model.makePage = { [page = undecided.page] _ in page }
        let fresh = WebConversationModel(
            instanceURL: instanceURL, allowsLoopbackHTTP: false, mint: { _ in .failed(.network) })
        fresh.makePage = { _ in undecided.page }
        fresh.prewarm(Self.policy("d0e515ef"))
        #expect(!fresh.hasPreparedPage)
        try? await Task.sleep(for: .milliseconds(50))
        #expect(older.paths.isEmpty && undecided.paths.isEmpty)
    }

    // MARK: Copies of prepared pages

    /// Lets the page of `id` finish loading and the model's quiet period for saving run out.
    private func readyAndStill(_ harness: Harness, _ id: String) async {
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        harness.model.receive(Harness.ready(id))
    }

    @Test func aPreparedPageNoPaneShowedIsNeverSaved() async {
        let harness = prewarmHarness()
        harness.model.prewarm(Self.policy("d0e515ef"))
        await readyAndStill(harness, "d0e515ef")
        await harness.model.saveCopy()
        harness.model.appLeftForeground()
        try? await Task.sleep(for: .milliseconds(100))
        #expect(await harness.store.entries().isEmpty)
        #expect(harness.page.captures == 0)
    }

    @Test func aPreparedPageIsNotSavedWhenAnotherThreadIsOpenedAfterwards() async {
        let harness = prewarmHarness()
        harness.model.prewarm(Self.policy("d0e515ef"))
        await readyAndStill(harness, "d0e515ef")
        harness.model.takeRequests(owner: harness) { _ in }
        harness.model.show(thread: "a1b2c3d4")
        try? await Task.sleep(for: .milliseconds(100))
        #expect(await harness.store.entries().isEmpty)
        #expect(harness.page.captures == 0)
    }

    @Test func aPreparedPageThatAPaneShowsIsSavedAsBefore() async {
        let harness = prewarmHarness()
        harness.model.prewarm(Self.policy("d0e515ef"))
        await readyAndStill(harness, "d0e515ef")
        harness.model.takeRequests(owner: harness) { _ in }
        harness.model.show(thread: "d0e515ef")
        await harness.model.saveCopy()
        #expect(await harness.store.entries().map(\.threadID) == ["d0e515ef"])
    }

    @Test func nothingIsSavedWhileNoPaneShowsTheConversation() async {
        let harness = Harness(hasPane: false)
        await harness.open("d0e515ef")
        await harness.model.saveCopy()
        #expect(await harness.store.entries().isEmpty)
    }

    @Test func withoutAPageFactoryNothingIsPrepared() {
        let harness = Harness(supports: true, attachesPage: false, hasPane: false)
        harness.model.prewarm(Self.policy("d0e515ef"))
        #expect(!harness.model.hasPreparedPage)
    }
}

private actor LookupGate {
    private var isOpen = false
    private var waiting: [CheckedContinuation<Void, Never>] = []

    func wait() async {
        if isOpen { return }
        await withCheckedContinuation { waiting.append($0) }
    }

    func release() {
        isOpen = true
        for continuation in waiting { continuation.resume() }
        waiting = []
    }
}
