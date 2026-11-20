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

    func loadRedeem(_ request: URLRequest) { redeems.append(request) }
    func deliver(_ message: EmbedAppMessage) { messages.append(message) }
    func quickLook(url: URL, name: String) { quickLooks.append(url) }
    func setHidden(_ isHidden: Bool) { self.isHidden = isHidden }
}

@MainActor private final class Harness {
    var paths: [String] = []
    var outcomes: [WebConversationModel.MintOutcome] = []
    var now = ContinuousClock.now
    var requests: [WebConversationRequest] = []
    let page = FakePage()
    let model: WebConversationModel

    static func session(code: String = "yakweb_abc123", redeemUrl: String? = nil) -> WebSession {
        WebSession(
            redeemUrl: redeemUrl ?? "https://yak.example.com/app/embed/session", code: code,
            expiresAt: "2026-11-20T10:00:00Z"
        )
    }

    init(supports: Bool = true, attachesPage: Bool = true) {
        var box: Harness?
        model = WebConversationModel(
            instanceURL: instanceURL, allowsLoopbackHTTP: false,
            mint: { path in
                guard let harness = box else { return .failed(.network) }
                harness.paths.append(path)
                return harness.outcomes.isEmpty ? .minted(Self.session()) : harness.outcomes.removeFirst()
            },
            now: { box?.now ?? ContinuousClock.now }, sleep: { _ in try await Task.sleep(for: .seconds(3600)) })
        box = self
        if attachesPage { model.attach(page) }
        model.handleRequest = { [unowned self] in requests.append($0) }
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
    @Test func aServerWithoutTheEmbeddedPageUsesTheNativeChat() async {
        let harness = Harness(supports: false)
        harness.model.show(thread: "d0e515ef")
        #expect(harness.model.mode == .native)
        #expect(harness.model.phase == .unsupported)
        try? await Task.sleep(for: .milliseconds(50))
        #expect(harness.paths.isEmpty && harness.page.redeems.isEmpty)
    }

    @Test func theChoiceFollowsAChangeOfTheServersAnswer() async {
        let harness = Harness(supports: false)
        harness.model.show(thread: "d0e515ef")
        harness.model.configure(supportsEmbeddedThread: false)
        #expect(harness.model.mode == .native)
        harness.model.configure(supportsEmbeddedThread: true)
        #expect(harness.model.mode == .web)
        #expect(await harness.waitForRedeems(1))
        harness.model.navigationCommitted()
        harness.model.receive(Harness.ready("d0e515ef"))
        #expect(harness.model.loadPresentation == .conversation)
        harness.model.configure(supportsEmbeddedThread: false)
        #expect(harness.model.mode == .native && harness.model.phase == .unsupported)
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
        #expect(harness.model.title == "Title")
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
        harness.outcomes = [.failed(.network)]
        harness.model.connectivityChanged(isOnline: false)
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.network) })
        #expect(harness.model.loadPresentation == .offlineWithoutCopy)
        harness.model.connectivityChanged(isOnline: true)
        #expect(await harness.waitForRedeems(1))
    }

    @Test func aLaunchWhileOfflineShowsTheOfflineStateAndRecoversOnReconnect() async {
        let harness = Harness()
        // The pane reports the connection as soon as it appears, before it asks for the thread.
        harness.model.connectivityChanged(isOnline: false)
        harness.outcomes = [.failed(.network)]
        harness.model.show(thread: "d0e515ef")
        #expect(await TestTimeScale.poll { harness.model.phase == .failed(.network) })
        #expect(harness.model.loadPresentation == .offlineWithoutCopy)
        harness.model.connectivityChanged(isOnline: true)
        #expect(await harness.waitForMints(2))
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

    @Test func aQuickLookGoesToTheWebViewAndAFocusToThePage() async {
        let harness = Harness()
        await harness.open("d0e515ef")
        let file = URL(string: "https://yak.example.com/files/report.pdf")!
        harness.model.receive(.quickLook(url: file, name: "report.pdf", mimeType: nil))
        #expect(harness.page.quickLooks == [file])
        harness.model.focus(fragment: "card-question")
        #expect(harness.page.messages == [.focus(fragment: "card-question")])
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
