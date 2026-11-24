import Foundation
import Testing
import YakKit

@testable import Yak

private let instanceURL = URL(string: "https://yak.example.com")!
private let fileURL = URL(string: "https://yak.example.com/files/01HZX3K9ABCDEFGHJKMNPQRSTV")!

@MainActor private final class FakeViewerPage: FileViewerPage {
    var loads: [URLRequest] = []
    var stops = 0
    var closes = 0
    func load(_ request: URLRequest) { loads.append(request) }
    func stop() { stops += 1 }
    func close() { closes += 1 }
}

/// A sleep the test releases by hand: no real time passes, and a cancelled sleep ends at once.
private actor ViewerSleepGate {
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

    func release(_ duration: Duration) {
        for (id, waiter) in waiters where waiter.duration == duration {
            waiters.removeValue(forKey: id)
            waiter.continuation.resume()
        }
    }
}

@MainActor private final class ViewerHarness {
    let page = FakeViewerPage()
    let gate = ViewerSleepGate()
    var renewals = 0
    var renewalResult = true
    /// While set, a renewal waits here until `finishRenewal` is called.
    var holdsRenewal = false
    private var heldRenewal: CheckedContinuation<Void, Never>?
    let model: FileViewerModel

    init(isOnline: Bool = true, attach: Bool = true) {
        let gate = gate
        var box: ViewerHarness?
        model = FileViewerModel(
            url: fileURL, policy: FileViewerPolicy(fileURL: fileURL, instanceURL: instanceURL)!, isOnline: isOnline,
            renewSession: {
                box?.renewals += 1
                if box?.holdsRenewal == true { await box?.holdRenewal() }
                return box?.renewalResult ?? false
            }, sleep: { try await gate.sleep($0) })
        box = self
        if attach { model.attach(page) }
    }

    private func holdRenewal() async {
        await withCheckedContinuation { heldRenewal = $0 }
    }

    func finishRenewal() {
        heldRenewal?.resume()
        heldRenewal = nil
    }

    var isRenewalHeld: Bool { heldRenewal != nil }
}

@MainActor @Suite struct FileViewerModelTests {
    @Test func loadsTheFileOnAttachAndIsReadyWhenThePageFinishes() {
        let harness = ViewerHarness()
        #expect(harness.page.loads.map(\.url) == [fileURL])
        #expect(harness.page.loads.first?.httpMethod ?? "GET" == "GET")
        #expect(harness.model.phase == .loading)
        #expect(harness.model.presentation == .loading)
        harness.model.finished(pageTitle: "Weekly report.html - Yak")
        #expect(harness.model.phase == .ready)
        #expect(harness.model.title == "Weekly report.html")
        #expect(harness.model.presentation == .conversation)
    }

    @Test func aPageWithNoTitleKeepsTheFallbackName() {
        let harness = ViewerHarness()
        harness.model.finished(pageTitle: nil)
        #expect(harness.model.phase == .ready)
        #expect(harness.model.title == nil)
    }

    @Test func aSlowLoadShowsTheSlowLineThenTheFile() async {
        let harness = ViewerHarness()
        #expect(await TestTimeScale.poll { await harness.gate.isWaiting(for: FileViewerModel.slowAfter) })
        await harness.gate.release(FileViewerModel.slowAfter)
        #expect(await TestTimeScale.poll { harness.model.phase == .slow })
        #expect(harness.model.presentation == .slow)
        harness.model.finished(pageTitle: "Report - Yak")
        #expect(harness.model.phase == .ready)
    }

    @Test func aLoadThatNeverEndsFailsWithRetry() async {
        let harness = ViewerHarness()
        #expect(await TestTimeScale.poll { await harness.gate.isWaiting(for: FileViewerModel.slowAfter) })
        await harness.gate.release(FileViewerModel.slowAfter)
        let rest = FileViewerModel.failAfter - FileViewerModel.slowAfter
        #expect(await TestTimeScale.poll { await harness.gate.isWaiting(for: rest) })
        await harness.gate.release(rest)
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.timeout) })
        #expect(harness.page.stops == 1)
        harness.model.retry()
        #expect(harness.model.phase == .loading)
        #expect(harness.page.loads.count == 2)
    }

    @Test(arguments: [403, 404, 410])
    func aFileThatIsNotThereSaysSoAndNeverShowsAnErrorPage(status: Int) {
        let harness = ViewerHarness()
        let failure = harness.model.policy.failure(ofResponseFrom: fileURL, status: status, isMainFrame: true)!
        harness.model.failed(failure)
        #expect(harness.model.phase == .unavailable)
        #expect(harness.model.presentation == .serverError(message: String(localized: "viewer.failed.unavailable")))
        harness.model.finished(pageTitle: "Not found")
        #expect(harness.model.phase == .unavailable)
    }

    @Test func aServerErrorOffersRetryAndRetryLoadsAgain() {
        let harness = ViewerHarness()
        harness.model.failed(.server(500))
        #expect(harness.model.phase == .failed(.server))
        harness.model.retry()
        #expect(harness.model.phase == .loading)
        #expect(harness.page.loads.count == 2)
    }

    @Test func aNetworkFailureIsOfflineWhenTheDeviceIsOffline() {
        let online = ViewerHarness()
        online.model.loadFailed()
        #expect(online.model.phase == .failed(.network))
        let offline = ViewerHarness()
        offline.model.connectivityChanged(isOnline: false)
        offline.model.loadFailed()
        #expect(offline.model.phase == .offline)
        #expect(offline.model.presentation == .offlineWithoutCopy)
    }

    @Test func startingOfflineLoadsNothingUntilTheConnectionReturns() {
        let harness = ViewerHarness(isOnline: false)
        #expect(harness.model.phase == .offline)
        #expect(harness.page.loads.isEmpty)
        harness.model.connectivityChanged(isOnline: true)
        #expect(harness.model.phase == .loading)
        #expect(harness.page.loads.count == 1)
    }

    @Test func aLoadedFileStaysWhenTheConnectionGoesAndComes() {
        let harness = ViewerHarness()
        harness.model.finished(pageTitle: "Report - Yak")
        harness.model.connectivityChanged(isOnline: false)
        #expect(harness.model.phase == .ready)
        harness.model.connectivityChanged(isOnline: true)
        #expect(harness.model.phase == .ready)
        #expect(harness.page.loads.count == 1)
    }

    @Test func aNetworkFailureBecomesOfflineWhenTheAppNoticesTheConnectionIsGone() {
        let harness = ViewerHarness()
        harness.model.loadFailed()
        harness.model.connectivityChanged(isOnline: false)
        #expect(harness.model.phase == .offline)
        harness.model.connectivityChanged(isOnline: true)
        #expect(harness.model.phase == .loading)
    }

    @Test func aFailureForLackOfConnectionLoadsAgainWhenItReturns() {
        let harness = ViewerHarness()
        harness.model.loadFailed()
        #expect(harness.model.phase == .failed(.network))
        harness.model.connectivityChanged(isOnline: false)
        harness.model.connectivityChanged(isOnline: true)
        #expect(harness.model.phase == .loading)
        #expect(harness.page.loads.count == 2)
    }

    @Test func anExpiredSessionIsRenewedOnceThenTheFileLoadsAgain() async {
        let harness = ViewerHarness()
        harness.model.failed(.signedOut)
        #expect(harness.model.phase == .loading)
        #expect(await TestTimeScale.poll { harness.page.loads.count == 2 })
        #expect(harness.renewals == 1)
        harness.model.finished(pageTitle: "Report - Yak")
        #expect(harness.model.phase == .ready)
    }

    @Test func aSecondRefusalIsAnErrorAndNeverAnotherRenewal() async {
        let harness = ViewerHarness()
        harness.model.failed(.signedOut)
        #expect(await TestTimeScale.poll { harness.page.loads.count == 2 })
        harness.model.failed(.signedOut)
        #expect(harness.model.phase == .failed(.session))
        #expect(harness.renewals == 1)
        harness.model.retry()
        harness.model.failed(.signedOut)
        #expect(await TestTimeScale.poll { harness.page.loads.count == 4 })
        #expect(harness.renewals == 2)
    }

    @Test func aSessionThatCannotBeRenewedIsAnErrorWithRetry() async {
        let harness = ViewerHarness()
        harness.renewalResult = false
        harness.model.failed(.signedOut)
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.session) })
        #expect(harness.page.loads.count == 1)
    }

    @Test func aCrashedPageOffersRetry() {
        let harness = ViewerHarness()
        harness.model.finished(pageTitle: "Report - Yak")
        harness.model.processEnded()
        #expect(harness.model.phase == .failed(.crashed))
        harness.model.retry()
        #expect(harness.model.phase == .loading)
    }

    @Test func closingEndsTheLoadAndAnyDownloadStartedFromTheViewer() {
        let harness = ViewerHarness()
        harness.model.close()
        #expect(harness.page.closes == 1)
    }

    @Test func aLoadedViewerIsNotReplacedByALaterFailureOfSomethingTheReportAskedFor() {
        let harness = ViewerHarness()
        harness.model.finished(pageTitle: "Report - Yak")
        for failure in [FileViewerPolicy.Failure.unavailable, .server(500), .signedOut] {
            harness.model.failed(failure)
        }
        harness.model.loadFailed()
        harness.model.handedToQuickLook()
        #expect(harness.model.phase == .ready)
        #expect(harness.renewals == 0)
    }

    @Test func aFileThatIsNotAPageIsHandedToQuickLookAndNeverLeftLoading() {
        let harness = ViewerHarness()
        harness.model.handedToQuickLook()
        #expect(harness.model.phase == .handedOff)
        #expect(harness.model.presentation == .serverError(message: String(localized: "viewer.handedOff")))
        harness.model.retry()
        #expect(harness.model.phase == .loading)
    }

    @Test func aSecondWordOfTheSameRefusalDuringTheRenewalChangesNothing() async {
        let harness = ViewerHarness()
        harness.holdsRenewal = true
        harness.model.failed(.signedOut)
        harness.model.failed(.signedOut)
        #expect(await TestTimeScale.poll { harness.isRenewalHeld })
        #expect(harness.model.phase == .loading)
        #expect(harness.renewals == 1)
        harness.finishRenewal()
        #expect(await TestTimeScale.poll { harness.page.loads.count == 2 })
    }

    @Test func aSlowRenewalIsASessionErrorWithRetryNotATimeout() async {
        let harness = ViewerHarness()
        harness.holdsRenewal = true
        harness.renewalResult = false
        harness.model.failed(.signedOut)
        #expect(await TestTimeScale.poll { harness.isRenewalHeld })
        // The wait for the file is not running while the renewal is.
        #expect(await TestTimeScale.poll { await !harness.gate.isWaiting(for: FileViewerModel.slowAfter) })
        await harness.gate.release(FileViewerModel.slowAfter)
        await harness.gate.release(FileViewerModel.failAfter - FileViewerModel.slowAfter)
        #expect(harness.model.phase == .loading)
        harness.finishRenewal()
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.session) })
        #expect(harness.page.stops == 0)
    }

    @Test func aRedirectToTheLoginPageIsAnExpiredSessionThatRenewsOnce() async {
        let harness = ViewerHarness()
        let login = URL(string: "https://yak.example.com/login")!
        #expect(
            harness.model.policy.decide(url: login, method: nil, isMainFrame: true, isUserInitiated: false)
                == .signedOut)
        harness.model.failed(.signedOut)
        #expect(await TestTimeScale.poll { harness.page.loads.count == 2 })
        #expect(harness.renewals == 1)
        harness.model.failed(.signedOut)
        #expect(harness.model.phase == .failed(.session))
        #expect(harness.page.loads.count == 2)
    }
}

@MainActor @Suite struct FileLinkRoutingTests {
    private func model() -> WebConversationModel {
        WebConversationModel(
            instanceURL: instanceURL, allowsLoopbackHTTP: false, mint: { _ in .failed(.network) }, copies: { nil })
    }

    @Test func aFilePageOpensInTheViewerAThreadInTheAppAndOtherLinksInTheBrowser() {
        let model = model()
        var requests: [WebConversationRequest] = []
        model.handleRequest = { requests.append($0) }
        model.open(link: fileURL)
        model.open(link: URL(string: "https://yak.example.com/threads/d0e515ef")!)
        model.open(link: URL(string: "https://example.com/docs")!)
        model.open(link: URL(string: "https://yak.example.com/files/01HZX3K9ABCDEFGHJKMNPQRSTV/raw")!)
        model.open(link: URL(string: "https://other.example.com/files/01HZX3K9ABCDEFGHJKMNPQRSTV")!)
        #expect(
            requests == [
                .file(fileURL), .openThread("d0e515ef"), .link(URL(string: "https://example.com/docs")!),
                .link(URL(string: "https://yak.example.com/files/01HZX3K9ABCDEFGHJKMNPQRSTV/raw")!),
                .link(URL(string: "https://other.example.com/files/01HZX3K9ABCDEFGHJKMNPQRSTV")!),
            ])
    }

    @Test func renewingTheSessionRunsTheHandOffAgainAndEndsWhenThePageIsReady() async {
        let page = FakeRenewPage()
        let model = WebConversationModel(
            instanceURL: instanceURL, allowsLoopbackHTTP: false,
            mint: { _ in
                .minted(
                    WebSession(
                        redeemUrl: "https://yak.example.com/app/embed/session", code: "yakweb_abc123",
                        expiresAt: "2026-11-20T10:00:00Z"))
            }, copies: { nil })
        model.attach(page)
        model.configure(supportsEmbeddedThread: true)
        model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { page.redeems == 1 })
        model.navigationCommitted()
        model.receive(.ready(threadID: "d0e515ef", path: "/app/embed/threads/d0e515ef", latestSequence: 1, title: "T"))
        let renewal = Task { await model.renewSession() }
        #expect(await TestTimeScale.poll { page.redeems == 2 })
        model.navigationCommitted()
        model.receive(.ready(threadID: "d0e515ef", path: "/app/embed/threads/d0e515ef", latestSequence: 1, title: "T"))
        #expect(await renewal.value)
    }

    @Test func renewingWithoutAConversationFails() async {
        #expect(await model().renewSession() == false)
    }
}

@MainActor private final class FakeRenewPage: WebConversationPage {
    var redeems = 0
    func loadRedeem(_ request: URLRequest) { redeems += 1 }
    func deliver(_ message: EmbedAppMessage) {}
    func quickLook(url: URL, name: String) {}
    func setHidden(_ isHidden: Bool) {}
    func pauseMedia() {}
    func captureArchive(_ completion: @escaping @MainActor (Data?) -> Void) { completion(nil) }
}
