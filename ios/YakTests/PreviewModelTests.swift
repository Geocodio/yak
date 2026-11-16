import Foundation
import Testing
import YakKit

@testable import Yak

private final class TestClock: YakClock, @unchecked Sendable {
    private let lock = NSLock()
    private var current = Date(timeIntervalSince1970: 1_800_000_000)
    func now() -> Date { lock.withLock { current } }
    func advance(_ seconds: TimeInterval) { lock.withLock { current = current.addingTimeInterval(seconds) } }
    /// Sleeps until cancelled; the tests never wait for a timer.
    func sleep(for duration: Duration) async throws { try await Task.sleep(for: .seconds(3600)) }
}

private final class FakeBackend: PreviewBackend, @unchecked Sendable {
    private let lock = NSLock()
    private var calls: [String] = []
    private var tickets: [PreviewTicketOutcome] = []
    private var snapshotContinuation: AsyncStream<PreviewSnapshot>.Continuation?
    private var intentContinuation: AsyncStream<PreviewIntentState>.Continuation?
    var allowsLoopbackHTTP: Bool { false }
    var restoreOutcome = ThreadActionOutcome.done

    var recorded: [String] { lock.withLock { calls } }
    func count(_ prefix: String) -> Int { recorded.filter { $0.hasPrefix(prefix) }.count }
    func queue(_ outcome: PreviewTicketOutcome) { lock.withLock { tickets.append(outcome) } }
    func send(_ snapshot: PreviewSnapshot) { lock.withLock { snapshotContinuation }?.yield(snapshot) }
    func send(_ intents: PreviewIntentState) { lock.withLock { intentContinuation }?.yield(intents) }
    private func note(_ call: String) { lock.withLock { calls.append(call) } }

    func snapshots() async -> AsyncStream<PreviewSnapshot> {
        AsyncStream { continuation in lock.withLock { snapshotContinuation = continuation } }
    }
    func intentStates() async -> AsyncStream<PreviewIntentState> {
        AsyncStream { continuation in lock.withLock { intentContinuation = continuation } }
    }
    func setVisible(_ visible: Bool) async { note("visible \(visible)") }
    func threadChanged(_ snapshot: ConversationSnapshot) async { note("thread") }
    func refresh() async { note("refresh") }
    func tryAgain() async { note("tryAgain") }
    func restore() async -> ThreadActionOutcome {
        note("restore")
        return restoreOutcome
    }
    func retryRestore() async -> ThreadActionOutcome { restoreOutcome }
    func ticket(path: String, reason: PreviewTicketReason) async -> PreviewTicketOutcome {
        note("ticket \(path) \(reason)")
        return lock.withLock { tickets.isEmpty ? nil : tickets.removeFirst() }
            ?? .url(URL(string: "https://t-p7m4q2hx9z.yak.example.test/_yak/auth?ticket=a&to=%2F")!)
    }
    func ask(_ intent: MessageIntent) async throws(SendProblem) -> OutboxItem {
        note("ask \(intent.rawValue)")
        return OutboxItem(
            id: UUID(), target: .thread("d0e515ef"), text: "x", model: nil, delivery: .steer, attachments: [],
            createdAt: Date(), intent: intent)
    }
    func prepareStore() async -> Bool { true }
    func close() async { note("close") }
}

@MainActor private final class FakeDriver: PreviewPageDriver {
    var loads: [URL] = []
    var reloads = 0
    var currentURL: URL?
    func load(_ url: URL) {
        loads.append(url)
        currentURL = url
    }
    func reload() { reloads += 1 }
    func stopLoading() {}
}

private let previewURL = "https://t-p7m4q2hx9z.yak.example.test/"

private func status(
    _ state: PreviewState, url: String? = previewURL, setup: PreviewSetup? = nil
) -> PreviewStatus {
    PreviewStatus(
        state: state, steps: [], log: [], error: nil, url: url, port: 5173, coldStartCommand: "npm run dev",
        setup: setup ?? PreviewSetup(intent: nil, isRunning: false, endedWithoutManifest: false))
}

private func snapshot(
    _ state: PreviewState = .ready, url: String? = previewURL, isOffline: Bool = false, canDrive: Bool = true
) -> PreviewSnapshot {
    PreviewSnapshot(
        load: .ready, status: status(state, url: url), isOffline: isOffline, canDrive: canDrive,
        lastPreviewURL: url)
}

@MainActor @Suite struct PreviewModelTests {
    private func make(
        _ backend: FakeBackend = FakeBackend(), opened: ((URL) -> Void)? = nil
    ) -> (PreviewModel, FakeBackend, FakeDriver, TestClock) {
        let clock = TestClock()
        let model = PreviewModel(
            threadID: "d0e515ef", instanceHost: "yak.example.test", backend: backend, clock: clock,
            terminalAvailable: false, openURL: { opened?($0) })
        let driver = FakeDriver()
        model.attach(driver)
        return (model, backend, driver, clock)
    }

    private func settle(_ condition: () -> Bool) async {
        for _ in 0..<200 where !condition() {
            await Task.yield()
            try? await Task.sleep(for: .milliseconds(5))
        }
    }

    @Test func theFirstReadyLoadSignsInOnce() async throws {
        let (model, backend, driver, _) = make()
        await model.start()
        await model.setVisible(true)
        backend.send(snapshot())
        await settle { driver.loads.count == 1 }
        #expect(backend.recorded.contains("ticket / firstLoad"))
        #expect(driver.loads.first?.path == "/_yak/auth")
        backend.send(snapshot())
        backend.send(snapshot(url: previewURL, isOffline: false, canDrive: false))
        await settle { model.snapshot.canDrive == false }
        #expect(backend.count("ticket") == 1)
        await model.go(to: "pricing")
        #expect(driver.loads.last?.absoluteString == "https://t-p7m4q2hx9z.yak.example.test/pricing")
        #expect(backend.count("ticket") == 1)
    }

    @Test func aBounceSignsInAgain() async throws {
        let (model, backend, driver, _) = make()
        await model.start()
        backend.send(snapshot())
        await settle { driver.loads.count == 1 }
        let bounce = URL(
            string:
                "https://yak.example.test/deployments/auth-bounce?to=https%3A%2F%2Ft-p7m4q2hx9z.yak.example.test%2Fpricing%3Fplan%3Dteam"
        )!
        #expect(
            model.decide(navigation: bounce, isMainFrame: true, isUserInitiated: false)
                == .mintTicket(path: "/pricing?plan=team"))
        await settle { driver.loads.count == 2 }
        #expect(backend.recorded.contains("ticket /pricing?plan=team signInAgain"))
        backend.queue(.tooManyAttempts)
        _ = model.decide(navigation: bounce, isMainFrame: true, isUserInitiated: false)
        await settle { model.page == .signInFailed }
        #expect(model.page == .signInFailed)
        await model.perform(.tryAgain)
        #expect(backend.recorded.contains { $0.hasSuffix("personRetry") })
    }

    @Test func wakingPagesRefetchTheStatus() async throws {
        let (model, backend, _, _) = make()
        await model.start()
        backend.send(snapshot())
        let url = URL(string: previewURL)!
        await settle { model.card != nil }
        #expect(model.responded(status: 425, url: url, isMainFrame: true, canShow: true) == false)
        await settle { backend.count("refresh") == 1 }
        #expect(backend.count("refresh") == 1)
    }

    @Test func anOfflinePageReloadsWhenTheNetworkReturns() async throws {
        let (model, backend, driver, _) = make()
        await model.start()
        backend.send(snapshot())
        await settle { driver.loads.count == 1 }
        model.pageFailed(.notConnectedToInternet)
        #expect(model.page == .problem(.offline))
        backend.send(snapshot(isOffline: true))
        await settle { model.snapshot.isOffline }
        backend.send(snapshot(isOffline: false))
        await settle { driver.loads.count == 2 || driver.reloads == 1 }
        #expect(driver.loads.count == 2 || driver.reloads == 1)
    }

    @Test func aCrashedPageReloadsOnceThenShowsTheCard() async throws {
        let (model, backend, driver, clock) = make()
        await model.start()
        await model.setVisible(true)
        backend.send(snapshot())
        await settle { driver.loads.count == 1 }
        model.pageProcessEnded()
        #expect(driver.reloads == 1)
        clock.advance(10)
        model.pageProcessEnded()
        #expect(driver.reloads == 1)
        #expect(model.page == .crashed)
    }

    @Test func askingSendsTheIntentOnce() async throws {
        let (model, backend, _, _) = make()
        await model.start()
        backend.send(snapshot(.failing, url: nil))
        await settle { model.card != nil }
        async let first: Void = model.perform(.ask(.fixPreview, isPending: false))
        async let second: Void = model.perform(.ask(.fixPreview, isPending: false))
        _ = await (first, second)
        #expect(backend.count("ask") == 1)
        #expect(model.isPerforming != nil)
        backend.send(PreviewIntentState(pending: [.fixPreview], refused: [:]))
        await settle { model.isPerforming == nil }
        #expect(model.isPerforming == nil)
    }

    @Test func aRefusedIntentShowsALineWithRetryThatSendsItAgain() async throws {
        let (model, backend, _, _) = make()
        await model.start()
        backend.send(snapshot(.failing, url: nil))
        await settle { model.card != nil }
        await model.perform(.ask(.fixPreview, isPending: false))
        backend.send(PreviewIntentState(pending: [], refused: [.fixPreview: "Too long."]))
        await settle { model.actionLine != nil }
        #expect(model.actionLine == "Not sent. Too long.")
        #expect(model.actionLineRetry == .ask(.fixPreview, isPending: false))
        let before = backend.count("ask")
        await model.perform(try #require(model.actionLineRetry))
        #expect(backend.count("ask") == before + 1)
    }

    @Test func copyNeverCarriesATicket() async throws {
        let (model, backend, driver, _) = make()
        await model.start()
        backend.send(snapshot())
        await settle { driver.currentURL != nil }
        driver.currentURL = URL(string: "https://t-p7m4q2hx9z.yak.example.test/_yak/auth?ticket=abc&to=%2Fpricing")
        let copied = try #require(model.copyableAddress())
        #expect(!copied.absoluteString.contains("ticket"))
        #expect(copied.path == "/pricing")
    }

    @Test func aHiddenPaneTellsTheSession() async throws {
        let (model, backend, _, _) = make()
        await model.start()
        await model.setVisible(false)
        #expect(backend.recorded.contains("visible false"))
        await model.stop()
        #expect(backend.recorded.contains("close"))
    }

    @Test func aFileThatCannotShowIsCancelled() async throws {
        let (model, _, _, _) = make()
        await model.start()
        let url = URL(string: previewURL)!
        #expect(model.responded(status: 200, url: url, isMainFrame: true, canShow: false) == false)
        #expect(model.page == .cannotShowFile)
    }

    @Test func aScriptOpenIsCancelledWithALine() async throws {
        nonisolated(unsafe) var opened: [URL] = []
        let (model, backend, _, _) = make(opened: { opened.append($0) })
        await model.start()
        backend.send(snapshot())
        await settle { model.card != nil }
        let other = URL(string: "https://other.example.com/x")!
        #expect(model.decide(navigation: other, isMainFrame: true, isUserInitiated: false) == .cancelUnrequestedOpen)
        #expect(model.actionLine == "The preview tried to open another site.")
        #expect(opened.isEmpty)
        #expect(model.decide(navigation: other, isMainFrame: true, isUserInitiated: true) == .openInBrowser(other))
        #expect(opened == [other])
    }

    @Test func previewsSwitchedOffShowsTheSentence() async throws {
        let backend = FakeBackend()
        backend.queue(.refused(code: "unavailable", message: "Previews are switched off on this Yak."))
        let (model, _, driver, clock) = make(backend)
        await model.start()
        backend.send(snapshot())
        await settle { model.page != .idle }
        await settle { model.page == .refused(message: "Previews are switched off on this Yak.") }
        #expect(model.page == .refused(message: "Previews are switched off on this Yak."))
        clock.advance(60)
        backend.send(snapshot())
        await settle { false }
        #expect(backend.count("ticket") == 1)
        #expect(backend.count("refresh") == 0)
        #expect(driver.loads.isEmpty)
    }

    @Test func aFinishedRestoreShowsNoError() async throws {
        let (model, backend, _, _) = make()
        await model.start()
        backend.send(snapshot(.destroyed, url: nil))
        await settle { model.card != nil }
        await model.perform(.restore)
        #expect(model.actionLine == nil)
        #expect(model.isPerforming == nil)
    }
}
