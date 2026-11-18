import Foundation
import Observation
import YakKit

#if canImport(UIKit)
    import UIKit
#endif

/// The outbox items of this thread that carry a preview intent.
struct PreviewIntentState: Equatable, Sendable {
    /// Sent or waiting, not refused.
    var pending: Set<MessageIntent> = []
    /// The server's refusal sentence by intent.
    var refused: [MessageIntent: String] = [:]
}

/// Everything the model asks of the session, the outbox and the data store, so a test can script it.
protocol PreviewBackend: Sendable {
    var allowsLoopbackHTTP: Bool { get }
    func snapshots() async -> AsyncStream<PreviewSnapshot>
    func setVisible(_ visible: Bool) async
    func threadChanged(_ snapshot: ConversationSnapshot) async
    func refresh() async
    func tryAgain() async
    func restore() async -> ThreadActionOutcome
    func retryRestore() async -> ThreadActionOutcome
    func ticket(path: String, reason: PreviewTicketReason) async -> PreviewTicketOutcome
    func ask(_ intent: MessageIntent) async throws(SendProblem) -> OutboxItem
    func intentStates() async -> AsyncStream<PreviewIntentState>
    func prepareStore() async -> Bool
    func close() async
}

/// The web view, as far as the model drives it.
@MainActor
protocol PreviewPageDriver: AnyObject {
    func load(_ url: URL)
    func reload()
    func stopLoading()
    var currentURL: URL? { get }
}

enum PreviewPageState: Equatable {
    case idle, signingIn
    case loading(progress: Double)
    case slow, loaded
    case problem(PreviewPageProblem)
    case signInFailed, crashed, cannotShowFile
    /// A ticket refusal that a status refresh does not explain: the server's sentence.
    case refused(message: String)
}

/// The Preview pane's state. The web view's coordinator reports here and asks here; it decides nothing itself.
@MainActor @Observable
final class PreviewModel {
    static let slowAfter: Duration = .seconds(8)
    static let actionLineLifetime: Duration = .seconds(6)
    static let crashWindow: TimeInterval = 60
    /// How long the Files pane has to take the manifest request before the card says it did not open.
    static let fileOpenTimeout: Duration = .seconds(5)

    let threadID: String
    let instanceHost: String

    private(set) var snapshot = PreviewSnapshot()
    private(set) var card: PreviewCard?
    private(set) var page = PreviewPageState.idle
    private(set) var address = "/"
    private(set) var isSecure = false
    private(set) var actionLine: String?
    /// What the action line's Retry sends again, when the line has one.
    private(set) var actionLineRetry: PreviewAction?
    private(set) var isPerforming: PreviewAction?
    private(set) var intents = PreviewIntentState()
    /// A saved sign-in could not be erased; nothing loads until the app restarts.
    private(set) var isStoreBlocked = false
    private(set) var isVisible = false

    @ObservationIgnored private let backend: any PreviewBackend
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private let terminalAvailable: Bool
    @ObservationIgnored private let canOpenFiles: Bool
    @ObservationIgnored private let fileOpenRequest: @MainActor () -> FileOpenRequest?
    @ObservationIgnored private let setFileOpenRequest: @MainActor (FileOpenRequest?) -> Void
    @ObservationIgnored private var fileOpenTask: Task<Void, Never>?
    @ObservationIgnored private let openURL: @MainActor (URL) -> Void
    @ObservationIgnored private let focus: @MainActor (PaneID) -> Void
    @ObservationIgnored private weak var driver: (any PreviewPageDriver)?
    @ObservationIgnored private var readingTasks: [Task<Void, Never>] = []
    @ObservationIgnored private var lineTask: Task<Void, Never>?
    @ObservationIgnored private var slowTask: Task<Void, Never>?
    @ObservationIgnored private var hasSignedIn = false
    @ObservationIgnored private var isMintingPaused = false
    @ObservationIgnored private var lastCardWasPage = false
    @ObservationIgnored private var lastCrash: Date?
    @ObservationIgnored private var signInTask: Task<Void, Never>?
    @ObservationIgnored private var signInGeneration = 0
    @ObservationIgnored private var wasOffline = false
    @ObservationIgnored private var isStarted = false

    init(
        threadID: String, instanceHost: String, backend: any PreviewBackend, clock: any YakClock,
        terminalAvailable: Bool, canOpenFiles: Bool = false,
        openURL: @escaping @MainActor (URL) -> Void = PreviewModel.openInSystem,
        focus: @escaping @MainActor (PaneID) -> Void = { _ in },
        fileOpenRequest: @escaping @MainActor () -> FileOpenRequest? = { nil },
        setFileOpenRequest: @escaping @MainActor (FileOpenRequest?) -> Void = { _ in }
    ) {
        self.canOpenFiles = canOpenFiles
        self.fileOpenRequest = fileOpenRequest
        self.setFileOpenRequest = setFileOpenRequest
        self.threadID = threadID
        self.instanceHost = instanceHost
        self.backend = backend
        self.clock = clock
        self.terminalAvailable = terminalAvailable
        self.openURL = openURL
        self.focus = focus
    }

    static func openInSystem(_ url: URL) {
        #if canImport(UIKit)
            UIApplication.shared.open(url)
        #endif
    }

    // MARK: Lifecycle

    func attach(_ driver: any PreviewPageDriver) { self.driver = driver }

    func start() async {
        guard !isStarted else { return }
        isStarted = true
        guard await backend.prepareStore() else {
            isStoreBlocked = true
            return
        }
        let snapshots = await backend.snapshots()
        let intentStates = await backend.intentStates()
        readingTasks = [
            Task { [weak self] in
                for await next in snapshots {
                    guard let self, !Task.isCancelled else { return }
                    self.apply(next)
                }
            },
            Task { [weak self] in
                for await next in intentStates {
                    guard let self, !Task.isCancelled else { return }
                    self.apply(intents: next)
                }
            },
        ]
        await backend.setVisible(isVisible)
    }

    func stop() async {
        isStarted = false
        for task in readingTasks { task.cancel() }
        readingTasks = []
        lineTask?.cancel()
        slowTask?.cancel()
        fileOpenTask?.cancel()
        signInTask?.cancel()
        driver?.stopLoading()
        await backend.close()
    }

    func setVisible(_ visible: Bool) async {
        isVisible = visible
        if isStarted { await backend.setVisible(visible) }
    }

    func update(thread snapshot: ConversationSnapshot?) async {
        guard let snapshot else { return }
        await backend.threadChanged(snapshot)
    }

    // MARK: Snapshots

    private var previewURL: URL? {
        (snapshot.status?.url ?? snapshot.lastPreviewURL).flatMap { URL(string: $0) }
    }

    private var previewAuthority: String? { previewURL.flatMap(PreviewNavigation.authority(of:)) }

    private func apply(_ next: PreviewSnapshot) {
        let wasOfflineBefore = wasOffline
        snapshot = next
        wasOffline = next.isOffline
        guard let status = next.status else {
            card = nil
            return
        }
        isSecure = status.url?.lowercased().hasPrefix("https://") ?? false
        let made = PreviewCard.make(
            status: status, canDrive: next.canDrive, blockedReason: next.blockedReason, isRestoring: next.isRestoring,
            sandboxState: next.sandboxState, hasKnownPreviewURL: next.lastPreviewURL != nil,
            restoreTookTooLong: next.restoreTookTooLong, pendingIntent: intents.pending.first,
            terminalAvailable: terminalAvailable, canOpenFiles: canOpenFiles)
        card = made
        let isPage = made.kind == .page
        if isPage, !lastCardWasPage, case .refused = page { page = .idle }
        if isPage, !hasSignedIn, !isMintingPaused, signInTask == nil {
            startSignIn(path: address, reason: .firstLoad)
        }
        lastCardWasPage = isPage
        if wasOfflineBefore, !next.isOffline, page == .problem(.offline) { Task { await reload() } }
    }

    private func apply(intents next: PreviewIntentState) {
        // A message that left the outbox without a refusal reached the server; the status asked for right after the
        // send can have reached it first, so it is asked for again.
        let delivered = intents.pending.subtracting(next.pending).filter { next.refused[$0] == nil }
        intents = next
        if !delivered.isEmpty { Task { await backend.refresh() } }
        if case .ask(let intent, _) = isPerforming, next.pending.contains(intent) || next.refused[intent] != nil {
            isPerforming = nil
            if let message = next.refused[intent] {
                setLine(String(localized: "drawer.unsent.refused \(message)"), retry: .ask(intent, isPending: false))
            }
        }
        if snapshot.status != nil { apply(snapshot) }
    }

    // MARK: Signing in and loading

    private func signIn(path: String, reason: PreviewTicketReason) async {
        page = .signingIn
        switch await backend.ticket(path: path, reason: reason) {
        case .url(let url):
            hasSignedIn = true
            isMintingPaused = false
            driver?.load(url)
        case .tooManyAttempts, .unexpectedHost:
            page = .signInFailed
        case .refused(let code, let message):
            if code == "unavailable" {
                isMintingPaused = true
                page = .refused(message: message)
            } else {
                page = .idle
                await backend.refresh()
            }
        case .failed(let problem):
            page = problem == .offline ? .problem(.offline) : .problem(.other)
        }
    }

    func go(to typed: String) async {
        let path = PreviewAddress.normalize(typed, previewHost: previewAuthority)
        address = path
        guard !hasSignedIn else {
            if let previewURL, let url = PreviewAddress.url(path: path, previewURL: previewURL) { driver?.load(url) }
            return
        }
        await signIn(path: path, reason: .firstLoad)
    }

    func reload() async {
        switch page {
        case .signInFailed, .refused:
            isMintingPaused = false
            await signIn(path: address, reason: .personRetry)
        case .problem, .crashed, .cannotShowFile, .idle:
            if card?.kind != .page { await backend.refresh() }
            if let driver, driver.currentURL != nil, page != .problem(.offline) {
                driver.reload()
            } else if hasSignedIn, let previewURL, let url = PreviewAddress.url(path: address, previewURL: previewURL) {
                driver?.load(url)
            } else if card?.kind == .page {
                await signIn(path: address, reason: .signInAgain)
            }
        case .signingIn, .loading, .slow, .loaded:
            driver?.reload()
        }
    }

    /// A fresh ticket URL for the current path, for the system browser. Without a card page, the plain URL.
    func openInBrowserURL() async -> URL? {
        switch await backend.ticket(path: address, reason: .openInBrowser) {
        case .url(let url): return url
        case .refused(_, let message):
            setLine(message)
            return nil
        default:
            setLine(String(localized: "preview.line.safariFailed"))
            return nil
        }
    }

    /// The host of the preview, for the sentences that name it.
    var previewHost: String { previewAuthority ?? "" }

    func refreshStatus() async { await backend.refresh() }

    /// Stops the page load the person asked to stop.
    func stopLoading() {
        slowTask?.cancel()
        driver?.stopLoading()
        page = .loaded
    }

    /// A short confirmation above the bar.
    func show(line text: String) { setLine(text) }

    func copyableAddress() -> URL? {
        PreviewAddress.copyable(driver?.currentURL ?? previewURL)
    }

    // MARK: Actions

    func perform(_ action: PreviewAction) async {
        guard isPerforming == nil else { return }
        switch action {
        case .ask(let intent, _):
            isPerforming = action
            do {
                _ = try await backend.ask(intent)
                haptic()
                // The server may already show the setup; the status is asked for now.
                await backend.refresh()
                // The progress state ends when the outbox lists the item.
                if intents.pending.contains(intent) { isPerforming = nil }
            } catch .notSaved {
                isPerforming = nil
                setLine(String(localized: "preview.line.notSaved"))
            } catch {
                isPerforming = nil
                setLine(String(localized: "preview.line.notActive"))
            }
        case .tryAgain:
            if page == .signInFailed {
                await signIn(path: address, reason: .personRetry)
            } else {
                await backend.tryAgain()
            }
        case .restore:
            isPerforming = action
            report(await backend.restore())
            isPerforming = nil
        case .retryRestore:
            isPerforming = action
            report(await backend.retryRestore())
            isPerforming = nil
        case .openTerminal: focus(.terminal)
        case .editManifest: requestManifestOpen()
        case .showConversation: focus(.chat)
        case .openInBrowser:
            isPerforming = action
            if let url = await openInBrowserURL() { openURL(url) }
            isPerforming = nil
        }
    }

    /// Hands the manifest to the Files pane and waits for it to take the request: the card shows progress until
    /// `fileOpenRequestChanged` sees it taken, or says it did not open after `fileOpenTimeout`.
    private func requestManifestOpen() {
        let request = FileOpenRequest(path: PreviewCard.manifestPath, edit: true)
        isPerforming = .editManifest
        actionLine = nil
        setFileOpenRequest(request)
        focus(.files)
        fileOpenTask?.cancel()
        fileOpenTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.fileOpenTimeout) } catch { return }
            guard let self, self.fileOpenRequest()?.id == request.id else { return }
            self.setFileOpenRequest(nil)
            self.isPerforming = nil
            self.setLine(String(localized: "preview.line.filesDidNotOpen"), retry: .editManifest)
        }
    }

    /// The Files pane cleared the request: it took it, so the card is done waiting.
    func fileOpenRequestChanged() {
        guard fileOpenRequest() == nil, isPerforming == .editManifest else { return }
        fileOpenTask?.cancel()
        isPerforming = nil
    }

    private func report(_ outcome: ThreadActionOutcome) {
        switch outcome {
        case .done, .alreadySent: break
        case .refused(_, let message, _): setLine(message)
        case .failed(.offline): setLine(String(localized: "preview.line.restoreOffline"))
        case .failed: setLine(String(localized: "preview.line.restoreFailed"))
        }
    }

    private func haptic() {
        #if canImport(UIKit)
            UIImpactFeedbackGenerator(style: .light).impactOccurred()
        #endif
    }

    // MARK: Action line

    private func setLine(_ text: String, retry: PreviewAction? = nil) {
        actionLine = text
        actionLineRetry = retry
        lineTask?.cancel()
        lineTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.actionLineLifetime) } catch { return }
            self?.actionLine = nil
            self?.actionLineRetry = nil
        }
    }

    // MARK: The web view's reports

    func decide(navigation url: URL, isMainFrame: Bool, isUserInitiated: Bool) -> PreviewNavigation.Decision {
        let decision = PreviewNavigation.decide(
            url: url, previewHost: previewAuthority ?? "", instanceHost: instanceHost, isMainFrame: isMainFrame,
            isUserInitiated: isUserInitiated, allowsLoopbackHTTP: backend.allowsLoopbackHTTP)
        switch decision {
        case .mintTicket(let path):
            startSignIn(path: path, reason: .signInAgain)
        case .openInBrowser(let target): openURL(target)
        case .cancelUnrequestedOpen: setLine(String(localized: "preview.line.unrequestedOpen"))
        case .allow, .cancel: break
        }
        return decision
    }

    /// Replaces any signing-in task; a task that finishes clears the handle only while the handle is still its own,
    /// so a newer task stays cancellable by stop().
    private func startSignIn(path: String, reason: PreviewTicketReason) {
        signInTask?.cancel()
        signInGeneration += 1
        let generation = signInGeneration
        signInTask = Task { [weak self] in
            await self?.signIn(path: path, reason: reason)
            if self?.signInGeneration == generation { self?.signInTask = nil }
        }
    }

    /// False cancels the response.
    func responded(status: Int, url: URL, isMainFrame: Bool, canShow: Bool) -> Bool {
        if isMainFrame, !canShow {
            page = .cannotShowFile
            return false
        }
        switch PreviewNavigation.respond(
            status: status, url: url, previewHost: previewAuthority ?? "", isMainFrame: isMainFrame)
        {
        case .allow: return true
        case .refetchStatus:
            Task { await backend.refresh() }
            return false
        case .mintAgain(let path):
            startSignIn(path: path, reason: .signInAgain)
            return false
        }
    }

    func pageStarted() {
        if page == .cannotShowFile { return }
        page = .loading(progress: 0)
        slowTask?.cancel()
        slowTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.slowAfter) } catch { return }
            if case .loading = self?.page { self?.page = .slow }
        }
    }

    func pageProgress(_ value: Double) {
        if case .loading = page { page = .loading(progress: value) }
    }

    func pageFinished(url: URL?) {
        slowTask?.cancel()
        page = .loaded
        if let url, PreviewNavigation.authority(of: url) == previewAuthority { address = PreviewAddress.display(url) }
    }

    func pageFailed(_ code: URLError.Code) {
        slowTask?.cancel()
        guard let problem = PreviewPageProblem.make(code) else { return }
        page = .problem(problem)
    }

    func pageProcessEnded() {
        slowTask?.cancel()
        let now = clock.now()
        if let lastCrash, now.timeIntervalSince(lastCrash) < Self.crashWindow {
            page = .crashed
            return
        }
        lastCrash = now
        if isVisible { driver?.reload() } else { page = .crashed }
    }
}
