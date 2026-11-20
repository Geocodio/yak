import Foundation
import Observation
import SwiftUI
import YakKit

#if canImport(os)
    import os
#endif

/// What the page asked the app to do, other than what the model handles itself. The pane that shows the conversation
/// carries each one out, because only it knows the thread model, the window's scene and the browser sheet.
enum WebConversationRequest: Equatable {
    case link(URL)
    case openPane(EmbedPane)
    /// The page moved to another thread by itself, for instance through a link inside it.
    case threadChanged(threadID: String)
    case cardAnswered(card: EmbedCard, action: String, isOK: Bool)
    case unsent(key: UUID, action: EmbedUnsentAction)
}

extension EnvironmentValues {
    /// The window's web conversation; nil where no window provides one, which shows the native chat.
    @Entry var webConversation: WebConversationModel?
}

/// The web view, as far as the model needs it.
@MainActor protocol WebConversationPage: AnyObject {
    /// Loads the POST that redeems a code; the server answers with a redirect to the page.
    func loadRedeem(_ request: URLRequest)
    func deliver(_ message: EmbedAppMessage)
    /// Downloads a same-origin file through the web view's session and shows it in Quick Look.
    func quickLook(url: URL, name: String)
    func setHidden(_ isHidden: Bool)
}

/// The web conversation of one instance in one window: the web view's session and what it shows. One model serves
/// every thread of the window, so a switch of thread is an `open` message to the page and no new session.
@MainActor @Observable
final class WebConversationModel {
    /// Whether the conversation is the page, the native chat, or not decided yet.
    enum Mode: Equatable { case undecided, web, native }

    enum MintOutcome {
        case minted(WebSession)
        case failed(WebConversationMachine.MintFailure)
    }

    typealias Mint = @MainActor (String) async -> MintOutcome

    /// A thread switch that the page does not answer within this long starts the session over.
    static let switchTimeout: Duration = .seconds(12)

    private(set) var mode: Mode = .undecided
    private(set) var machine: WebConversationMachine
    /// The thread the pane shows, which the page is asked to show.
    private(set) var threadID: String?
    /// The thread the page says it shows, from its last `ready` or `threadChanged`.
    private(set) var pageThreadID: String?
    /// The thread of the page's last `ready`: the cover over the page lifts only once this is the shown thread.
    private(set) var readyThreadID: String?
    private(set) var title = ""
    private(set) var isOnline = true

    let instanceURL: URL
    let allowsLoopbackHTTP: Bool
    let policy: EmbedNavigationPolicy

    @ObservationIgnored var handleRequest: @MainActor (WebConversationRequest) -> Void = { _ in }
    @ObservationIgnored private(set) var page: (any WebConversationPage)?
    @ObservationIgnored private let mint: Mint
    @ObservationIgnored private let now: () -> WebConversationInstant
    @ObservationIgnored private let sleep: @Sendable (Duration) async throws -> Void
    @ObservationIgnored private var tickTask: Task<Void, Never>?
    @ObservationIgnored private var mintTask: Task<Void, Never>?
    @ObservationIgnored private var switchTask: Task<Void, Never>?
    @ObservationIgnored private var redeemRequest: URLRequest?
    @ObservationIgnored private var openedThreadID: String?
    @ObservationIgnored private var lastOutbox: [String: EmbedAppMessage] = [:]
    @ObservationIgnored private var lastProcessEndAt: WebConversationInstant?
    @ObservationIgnored private var hasBegun = false
    @ObservationIgnored private var isPageHidden = false

    init(
        instanceURL: URL, allowsLoopbackHTTP: Bool, mint: @escaping Mint,
        now: @escaping () -> WebConversationInstant = { ContinuousClock.now },
        sleep: @escaping @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }
    ) {
        self.instanceURL = instanceURL
        self.allowsLoopbackHTTP = allowsLoopbackHTTP
        self.mint = mint
        self.now = now
        self.sleep = sleep
        policy = EmbedNavigationPolicy(instanceURL: instanceURL, allowsLoopbackHTTP: allowsLoopbackHTTP)
        machine = WebConversationMachine(supportsEmbeddedThread: false)
    }

    /// A model that mints through the session's web-session endpoint.
    convenience init(session: InstanceSession) {
        let loopback = session.allowsLoopbackHTTP
        self.init(
            instanceURL: session.instance.url, allowsLoopbackHTTP: loopback,
            mint: { [weak session] path in
                guard let session else { return .failed(.network) }
                do throws(APIFailure) {
                    guard let minted = try await session.webSession(path: path) else { return .failed(.network) }
                    return .minted(minted)
                } catch {
                    return .failed(Self.mintFailure(of: error))
                }
            })
    }

    nonisolated static func mintFailure(of failure: APIFailure) -> WebConversationMachine.MintFailure {
        switch failure {
        case .network: .network
        case .unauthorized: .unauthorized
        case .server(let error): error.status >= 500 ? .server(error.status) : .rejected
        default: .rejected
        }
    }

    // MARK: Decision

    /// Chooses the page or the native chat from what the server offers. The first choice stands for the window.
    func configure(supportsEmbeddedThread: Bool) {
        guard mode == .undecided else { return }
        mode = supportsEmbeddedThread ? .web : .native
        machine = WebConversationMachine(supportsEmbeddedThread: supportsEmbeddedThread, threadID: threadID)
        if supportsEmbeddedThread, threadID != nil { begin() }
    }

    // MARK: Showing a thread

    /// The pane shows `threadID`: the first time this loads the page, later it asks the open page to switch.
    func show(thread id: String) {
        guard threadID != id else { return }
        threadID = id
        guard mode == .web else { return }
        if !hasBegun {
            begin()
        } else if case .failed(let failure) = machine.phase, failure != .deviceUnauthorized {
            restart()
        } else {
            switchPageIfNeeded()
        }
    }

    private func begin() {
        hasBegun = true
        apply(.begin)
    }

    /// Starts a fresh session for the shown thread: a new code, a new load.
    private func restart() {
        guard mode == .web, threadID != nil else { return }
        machine = WebConversationMachine(supportsEmbeddedThread: true, threadID: threadID)
        pageThreadID = nil
        readyThreadID = nil
        openedThreadID = nil
        switchTask?.cancel()
        hasBegun = true
        apply(.begin)
    }

    private func switchPageIfNeeded() {
        guard machine.phase == .ready, let threadID, pageThreadID != threadID, openedThreadID != threadID else {
            return
        }
        openedThreadID = threadID
        send(.open(threadID: threadID, fragment: nil))
        apply(.opened(threadID: threadID))
        switchTask?.cancel()
        switchTask = Task { [weak self, sleep] in
            do { try await sleep(Self.switchTimeout) } catch { return }
            guard let self, self.pageThreadID != self.threadID else { return }
            self.restart()
        }
    }

    /// Scrolls the open page to a fragment such as `card-question`, from a push or a link.
    func focus(fragment: String) { send(.focus(fragment: fragment)) }

    func retry() {
        switch machine.phase {
        case .slow: restart()
        default: apply(.retry)
        }
    }

    // MARK: Machine

    var phase: WebConversationMachine.Phase { machine.phase }

    private func apply(_ event: WebConversationMachine.Event) {
        let effects = machine.handle(event, at: now())
        for effect in effects {
            switch effect {
            case .mint: startMint()
            case .redeem: redeem()
            }
        }
        scheduleTick()
    }

    private func startMint() {
        mintTask?.cancel()
        guard let threadID, let path = EmbedRedeem.threadPath(threadID) else {
            apply(.mintFailed(.rejected))
            return
        }
        mintTask = Task { [weak self] in
            guard let self else { return }
            let result = await mint(path)
            guard !Task.isCancelled else { return }
            switch result {
            case .failed(let failure): apply(.mintFailed(failure))
            case .minted(let session):
                // The code is checked before the machine hears of it: a refused one is a refused mint.
                guard
                    let request = EmbedRedeem.redeemRequest(
                        for: session, instanceURL: instanceURL, allowsLoopbackHTTP: allowsLoopbackHTTP)
                else { return apply(.mintFailed(.rejected)) }
                redeemRequest = request
                apply(.mintSucceeded)
            }
        }
    }

    private func redeem() {
        guard let request = redeemRequest, let page else { return }
        redeemRequest = nil
        pageThreadID = nil
        readyThreadID = nil
        openedThreadID = nil
        page.loadRedeem(request)
    }

    /// The web view is made; a request that waited for it loads now.
    func attach(_ page: any WebConversationPage) {
        self.page = page
        page.setHidden(isPageHidden)
        if machine.phase == .loading(.redeeming) { redeem() }
    }

    private func scheduleTick() {
        tickTask?.cancel()
        tickTask = nil
        guard let deadline = machine.nextDeadline else { return }
        let wait = max(.zero, now().duration(to: deadline))
        tickTask = Task { [weak self, sleep] in
            do { try await sleep(wait) } catch { return }
            self?.timePassed()
        }
    }

    /// The machine's next deadline is due: the page is slow, or has taken too long.
    func timePassed() { apply(.tick) }

    // MARK: From the web view

    func navigationCommitted() { apply(.navigation(.committed)) }

    func navigationFailed(_ outcome: WebConversationMachine.NavigationOutcome) { apply(.navigation(outcome)) }

    /// The web view's page process ended; a new session loads the page again, unless it also ended within the
    /// re-mint interval before, which fails with Retry instead of looping.
    func pageProcessEnded() {
        let at = now()
        let previous = lastProcessEndAt
        lastProcessEndAt = at
        guard mode == .web else { return }
        if let previous, previous.duration(to: at) < machine.timing.reMintInterval {
            mintTask?.cancel()
            switchTask?.cancel()
            apply(.pageCrashLoop)
        } else {
            restart()
        }
    }

    func connectivityChanged(isOnline: Bool) {
        guard self.isOnline != isOnline else { return }
        self.isOnline = isOnline
        guard mode == .web else { return }
        send(.connectivity(isOnline: isOnline))
        apply(.connectivity(isOnline: isOnline))
    }

    /// A decoded message of the page. Messages that need the pane go to `handleRequest`.
    func receive(_ message: EmbedPageMessage) {
        switch message {
        case .ready(let readyThreadID, _, _, let readyTitle):
            title = readyTitle
            pageThreadID = readyThreadID
            self.readyThreadID = readyThreadID
            if threadID == readyThreadID {
                switchTask?.cancel()
                openedThreadID = nil
            }
            apply(.page(message))
            sendHeldMessages()
            switchPageIfNeeded()
        case .threadChanged(let changedThreadID, _):
            pageThreadID = changedThreadID
            apply(.opened(threadID: changedThreadID))
            if changedThreadID == threadID {
                openedThreadID = nil
            } else if openedThreadID == nil {
                // Nothing was asked of the page, so it moved by itself and the window follows it.
                handleRequest(.threadChanged(threadID: changedThreadID))
            }
        case .link(let url): handleRequest(.link(url))
        case .openPane(let pane): handleRequest(.openPane(pane))
        case .quickLook(let url, let name, _): page?.quickLook(url: url, name: name)
        case .cardAnswered(let card, let action, let isOK):
            handleRequest(.cardAnswered(card: card, action: action, isOK: isOK))
        case .unsentAction(let key, let action): handleRequest(.unsent(key: key, action: action))
        case .authExpired, .error: apply(.page(message))
        case .unknown: break
        }
    }

    // MARK: To the page

    /// The unsent messages of a thread, kept per thread so an update from a pane that is leaving cannot replace the
    /// list of the thread that is arriving. The page is told when the list changes; a list that the page has not
    /// seen yet is sent when it is ready.
    func outboxChanged(threadID: String, items: [OutboxItem]) {
        let message = EmbedOutboxProjection.message(threadID: threadID, items: items)
        guard message != lastOutbox[threadID] else { return }
        lastOutbox[threadID] = message
        if machine.phase == .ready, pageThreadID == threadID { send(message) }
    }

    /// The server took a message of the shown thread, so the page reloads what it shows.
    func messageDelivered() { send(.refresh) }

    private func sendHeldMessages() {
        if !isOnline { send(.connectivity(isOnline: false)) }
        if let pageThreadID, let held = lastOutbox[pageThreadID] { send(held) }
    }

    private func send(_ message: EmbedAppMessage) {
        guard mode == .web else { return }
        page?.deliver(message)
    }

    // MARK: Hiding

    /// The page is hidden while the app is locked or covered, so a web field cannot keep focus under the cover.
    func setPageHidden(_ isHidden: Bool) {
        isPageHidden = isHidden
        page?.setHidden(isHidden)
    }

    // MARK: What the pane draws

    /// The load state the pane draws over the page, in the words of the native chat's own states.
    var loadPresentation: ThreadLoadPresentation {
        switch machine.phase {
        case .ready:
            return readyThreadID == threadID || threadID == nil ? .conversation : .loading
        case .loading, .signedOut: return .loading
        case .slow: return .slow
        case .offline, .unsupported: return .conversation
        case .failed(let failure): return Self.presentation(of: failure, isOnline: isOnline)
        }
    }

    nonisolated static func presentation(of failure: WebConversationMachine.Failure, isOnline: Bool)
        -> ThreadLoadPresentation
    {
        switch failure {
        case .notFound: .notFound
        case .network: isOnline ? .serverError(message: String(localized: "web.failed.network")) : .offlineWithoutCopy
        case .server: .serverError(message: String(localized: "web.failed.server"))
        case .timeout: .serverError(message: String(localized: "web.failed.timeout"))
        case .sessionExpired, .forbidden, .mintRejected:
            .serverError(message: String(localized: "web.failed.session"))
        case .deviceUnauthorized: .sessionOwns
        case .pageCrashed: .serverError(message: String(localized: "web.failed.crashed"))
        }
    }

    /// The page is up and the connection is gone: the native offline line shows over the last page.
    var showsOfflineLine: Bool { machine.phase == .ready && !isOnline }
}
