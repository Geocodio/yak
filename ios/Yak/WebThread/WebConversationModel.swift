import Foundation
import Observation
import SwiftUI
import UIKit
import YakKit

#if canImport(os)
    import os
#endif

/// What the page asked the app to do, other than what the model handles itself. The pane that shows the conversation
/// carries each one out, because only it knows the thread model, the window's scene and the browser sheet.
enum WebConversationRequest: Equatable {
    case link(URL)
    /// A link to a thread of this instance, which opens in the app.
    case openThread(String)
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
    /// Starts an archive of the page as it is now and calls `completion` with it; nil when the web view cannot make
    /// one. The archive is requested before this returns, so a message sent right after it cannot change what it holds.
    func captureArchive(_ completion: @escaping @MainActor (Data?) -> Void)
}

/// The last loaded copy of a thread's page, kept on the phone for reading offline.
struct SavedWebCopy: Equatable {
    let threadID: String
    let archive: Data
    let savedAt: Date
}

/// What the line above a saved copy says, from what the model is doing about the live page.
enum SavedCopyState: Equatable {
    /// There is no connection.
    case offline
    /// The connection is back and the live page is loading.
    case reconnecting
    /// The live page takes longer than usual.
    case slow
    /// The live page could not be loaded; Retry is offered.
    case failed
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
    /// The instance's saved copies; nil when there is no store to keep them in.
    typealias Copies = @MainActor () async -> OfflineCopyStore?

    /// A thread switch that the page does not answer within this long starts the session over.
    static let switchTimeout: Duration = .seconds(12)
    /// The page must stay still this long before its copy is saved, so a page that is still settling is not saved.
    static let captureQuietPeriod: Duration = .seconds(2)
    /// How long a notice line stays.
    static let noticeLifetime: Duration = .seconds(6)

    private(set) var mode: Mode = .undecided
    private(set) var machine: WebConversationMachine
    /// The thread the pane shows, which the page is asked to show.
    private(set) var threadID: String?
    /// The thread the page says it shows, from its last `ready` or `threadChanged`.
    private(set) var pageThreadID: String?
    /// The thread of the page's last `ready`: the cover over the page lifts only once this is the shown thread.
    private(set) var readyThreadID: String?
    private(set) var isOnline = true
    /// A short line about something that went wrong beside the page, such as a file that could not be opened. It
    /// goes away by itself.
    private(set) var noticeLine: String?
    /// The saved copy of the shown thread, drawn instead of the live page while the live page is not there. It stays
    /// until the live page is ready, so the swap back has no empty moment.
    private(set) var savedCopy: SavedWebCopy?

    let instanceURL: URL
    let allowsLoopbackHTTP: Bool
    let policy: EmbedNavigationPolicy

    @ObservationIgnored var handleRequest: @MainActor (WebConversationRequest) -> Void = { _ in }
    @ObservationIgnored private var requestOwner: ObjectIdentifier?
    @ObservationIgnored private(set) var page: (any WebConversationPage)?
    @ObservationIgnored private let mint: Mint
    @ObservationIgnored private let copies: Copies
    @ObservationIgnored private var copyThreadIDs: Set<String> = []
    @ObservationIgnored private var copyTask: Task<Void, Never>?
    @ObservationIgnored private var readTask: Task<Void, Never>?
    /// The store of the last lookup, so a capture that must start at once knows which store it began with.
    @ObservationIgnored private var knownStore: OfflineCopyStore?
    /// Keeps the app running a little longer while a copy is saved after it left the foreground. The returned closure
    /// ends it, and may be called more than once.
    @ObservationIgnored var holdBackgroundTime: @MainActor () -> @MainActor () -> Void = BackgroundTime.begin
    @ObservationIgnored private var captureTask: Task<Void, Never>?
    @ObservationIgnored private let now: () -> WebConversationInstant
    @ObservationIgnored private let sleep: @Sendable (Duration) async throws -> Void
    @ObservationIgnored private var tickTask: Task<Void, Never>?
    @ObservationIgnored private var mintTask: Task<Void, Never>?
    @ObservationIgnored private var switchTask: Task<Void, Never>?
    @ObservationIgnored private var redeemRequest: URLRequest?
    @ObservationIgnored private var openedThreadID: String?
    @ObservationIgnored private var lastOutbox: [String: EmbedAppMessage] = [:]
    @ObservationIgnored private var lastProcessEndAt: WebConversationInstant?
    @ObservationIgnored private var noticeTask: Task<Void, Never>?
    @ObservationIgnored private var hasBegun = false
    @ObservationIgnored private var isPageHidden = false

    init(
        instanceURL: URL, allowsLoopbackHTTP: Bool, mint: @escaping Mint, copies: @escaping Copies = { nil },
        now: @escaping () -> WebConversationInstant = { ContinuousClock.now },
        sleep: @escaping @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }
    ) {
        self.instanceURL = instanceURL
        self.allowsLoopbackHTTP = allowsLoopbackHTTP
        self.mint = mint
        self.copies = copies
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
            },
            copies: { [weak session] in await session?.offlineCopies() })
    }

    nonisolated static func mintFailure(of failure: APIFailure) -> WebConversationMachine.MintFailure {
        switch failure {
        case .network: .network
        case .unauthorized: .unauthorized
        case .server(let error): error.status >= 500 || error.status == 429 ? .server(error.status) : .rejected
        default: .rejected
        }
    }

    // MARK: The pane that carries out requests

    /// The pane that shows the conversation now carries out what the page asks for.
    func takeRequests(owner: AnyObject, _ handler: @escaping @MainActor (WebConversationRequest) -> Void) {
        requestOwner = ObjectIdentifier(owner)
        handleRequest = handler
    }

    /// A pane that goes away lets go of the requests, unless a newer pane has taken them over, so the window's model
    /// does not keep the old pane's thread alive or send a link tap to a pane that is gone.
    func releaseRequests(owner: AnyObject) {
        guard requestOwner == ObjectIdentifier(owner) else { return }
        requestOwner = nil
        handleRequest = { _ in }
    }

    // MARK: Decision

    /// Chooses the page or the native chat from what the server offers, and follows a later change of the answer:
    /// the machine is built again for the new answer, because it is built for one `me.web` value.
    func configure(supportsEmbeddedThread: Bool) {
        let chosen: Mode = supportsEmbeddedThread ? .web : .native
        guard mode != chosen else { return }
        mode = chosen
        mintTask?.cancel()
        switchTask?.cancel()
        tickTask?.cancel()
        tickTask = nil
        pageThreadID = nil
        readyThreadID = nil
        openedThreadID = nil
        hasBegun = false
        machine = makeMachine(supportsEmbeddedThread: supportsEmbeddedThread)
        if supportsEmbeddedThread {
            // A thread shown before the mode was known still needs to learn whether it has a copy.
            refreshCopyAvailability()
            if threadID != nil { begin() }
        }
    }

    private func makeMachine(supportsEmbeddedThread: Bool) -> WebConversationMachine {
        WebConversationMachine(
            supportsEmbeddedThread: supportsEmbeddedThread,
            hasOfflineCopy: threadID.map(copyThreadIDs.contains) ?? false, threadID: threadID)
    }

    // MARK: Showing a thread

    /// The pane shows `threadID`: the first time this loads the page, later it asks the open page to switch.
    func show(thread id: String) {
        guard threadID != id else { return }
        // The copy of the thread being left is as new as what the person last saw.
        if mode == .web, let old = threadID { saveOnLeaving(old) }
        threadID = id
        guard mode == .web else { return }
        refreshCopyAvailability()
        if !hasBegun {
            begin()
        } else if case .failed(let failure) = machine.phase, failure != .deviceUnauthorized {
            restart()
        } else if !isOnline, machine.phase == .ready || machine.phase == .offline {
            // Without a connection the page cannot visit another thread: the saved copy of it, if any, shows instead.
            restart()
        } else if machine.phase == .offline {
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
        machine = makeMachine(supportsEmbeddedThread: true)
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
            guard !Task.isCancelled, let self, self.pageThreadID != self.threadID else { return }
            self.restart()
        }
    }

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
        updateSavedCopy()
    }

    private func startMint() {
        mintTask?.cancel()
        guard isOnline else { return apply(.mintFailed(.network)) }
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
            guard !Task.isCancelled else { return }
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
        if isOnline { scheduleCapture() }
    }

    /// A link of the page: a thread of this instance opens in the app, anything else in the browser sheet.
    func open(link url: URL) {
        handleRequest(policy.threadID(in: url).map(WebConversationRequest.openThread) ?? .link(url))
    }

    /// A decoded message of the page. Messages that need the pane go to `handleRequest`.
    func receive(_ message: EmbedPageMessage) {
        switch message {
        case .ready(let readyThreadID, _, _, _):
            pageThreadID = readyThreadID
            self.readyThreadID = readyThreadID
            if threadID == readyThreadID {
                switchTask?.cancel()
                openedThreadID = nil
            }
            apply(.page(message))
            sendHeldMessages()
            switchPageIfNeeded()
            scheduleCapture()
        case .threadChanged(let changedThreadID, _):
            pageThreadID = changedThreadID
            apply(.opened(threadID: changedThreadID))
            scheduleCapture()
            if changedThreadID == threadID {
                openedThreadID = nil
            } else if openedThreadID == nil {
                // Nothing was asked of the page, so it moved by itself and the window follows it.
                handleRequest(.threadChanged(threadID: changedThreadID))
            }
        case .link(let url): open(link: url)
        case .openPane(let pane): handleRequest(.openPane(pane))
        case .quickLook(let url, let name, _): page?.quickLook(url: url, name: name)
        case .cardAnswered(let card, let action, let isOK):
            handleRequest(.cardAnswered(card: card, action: action, isOK: isOK))
            scheduleCapture()
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
    func messageDelivered() {
        send(.refresh)
        scheduleCapture()
    }

    private func sendHeldMessages() {
        if !isOnline { send(.connectivity(isOnline: false)) }
        if let pageThreadID, let held = lastOutbox[pageThreadID] { send(held) }
    }

    private func send(_ message: EmbedAppMessage) {
        guard mode == .web else { return }
        page?.deliver(message)
    }

    /// Says `text` for a few seconds in the line beside the page.
    func showNotice(_ text: String) {
        noticeLine = text
        noticeTask?.cancel()
        noticeTask = Task { [weak self, sleep] in
            do { try await sleep(Self.noticeLifetime) } catch { return }
            self?.noticeLine = nil
        }
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
        if savedCopyState != nil { return .conversation }
        switch machine.phase {
        case .ready:
            return readyThreadID == threadID || threadID == nil ? .conversation : .loading
        case .loading, .signedOut: return .loading
        case .slow: return .slow
        // A copy that is still being read shows the loading state; a copy that cannot be read ends in `.failed(.network)`.
        case .offline: return .loading
        case .unsupported: return .conversation
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

// MARK: Offline copies

extension WebConversationModel {
    /// What the line above the saved copy says; nil when no copy of the shown thread is drawn.
    var savedCopyState: SavedCopyState? {
        guard let savedCopy, savedCopy.threadID == threadID else { return nil }
        switch machine.phase {
        case .offline: return .offline
        case .loading, .signedOut: return .reconnecting
        case .slow: return .slow
        case .failed(let failure): return failure.isRetryable ? .failed : nil
        case .ready, .unsupported: return nil
        }
    }

    /// Learns which threads have a copy, so the machine can show one when the load fails for lack of a network.
    fileprivate func refreshCopyAvailability() {
        copyTask?.cancel()
        copyTask = Task { [weak self] in
            guard let self, let store = await copies() else { return }
            knownStore = store
            let known = Set(await store.entries().map(\.threadID))
            guard !Task.isCancelled else { return }
            copyThreadIDs = known
            if let threadID { apply(.offlineCopyChanged(isAvailable: known.contains(threadID))) }
        }
    }

    /// Keeps `savedCopy` in step with the machine: read when the machine shows the copy, kept while the live page
    /// loads again, dropped when the live page is ready or the thread is gone.
    fileprivate func updateSavedCopy() {
        guard let threadID else {
            savedCopy = nil
            return
        }
        if let savedCopy, savedCopy.threadID != threadID { self.savedCopy = nil }
        switch machine.phase {
        case .offline:
            if savedCopy == nil { readCopy(of: threadID) }
        case .ready:
            if readyThreadID == threadID { savedCopy = nil }
        case .failed(let failure):
            if !failure.isRetryable { savedCopy = nil }
            // A person who lost access, or whose thread is gone, must not keep reading the old copy.
            if failure == .notFound || failure == .forbidden || failure == .mintRejected {
                savedCopy = nil
                forgetCopy(of: threadID)
            }
        case .unsupported: savedCopy = nil
        case .loading, .slow, .signedOut: break
        }
    }

    private func readCopy(of id: String) {
        readTask?.cancel()
        readTask = Task { [weak self] in
            guard let self else { return }
            let copy = await copies()?.copy(thread: id)
            guard !Task.isCancelled, threadID == id, machine.phase == .offline else { return }
            if let copy {
                savedCopy = SavedWebCopy(threadID: id, archive: copy.archive, savedAt: copy.savedAt)
            } else {
                // The copy is gone or cannot be read now: the plain offline state shows.
                Self.log("A saved copy could not be read")
                copyThreadIDs.remove(id)
                apply(.offlineCopyChanged(isAvailable: false))
            }
        }
    }

    /// Whether a copy of the thread is saved. A copy exists only where the server offered the page.
    func hasSavedCopy(of id: String) async -> Bool {
        guard let store = await copies() else { return false }
        return await store.entries().contains { $0.threadID == id }
    }

    /// The thread is gone or hidden: its copy goes too.
    private func forgetCopy(of id: String) {
        copyThreadIDs.remove(id)
        Task { [copies] in await copies()?.remove(thread: id) }
    }

    // MARK: Saving

    /// Saves a copy once the page has been still for `captureQuietPeriod`.
    func scheduleCapture() {
        captureTask?.cancel()
        captureTask = Task { [weak self, sleep] in
            do { try await sleep(Self.captureQuietPeriod) } catch { return }
            await self?.saveCopy()
        }
    }

    /// The session ended (revoked, unpaired): nothing more is saved or read.
    func sessionEnded() {
        captureTask?.cancel()
        copyTask?.cancel()
        readTask?.cancel()
        savedCopy = nil
    }

    /// The app leaves the foreground: the copy is saved now, because the page may not live on.
    func appLeftForeground() {
        captureTask?.cancel()
        let endBackgroundTime = holdBackgroundTime()
        captureTask = Task { [weak self] in
            defer { endBackgroundTime() }
            await self?.saveCopy()
        }
    }

    /// The pane that showed the page goes away.
    func paneLeft() {
        if let pageThreadID { saveOnLeaving(pageThreadID) }
    }

    /// Asks for the archive of the page at once, before the page is told to show another thread, and saves it under
    /// the thread it was made for. A page that is not that thread's ready page, or moved on by the time the archive
    /// is back, is not saved.
    private func saveOnLeaving(_ id: String) {
        guard mode == .web, isOnline, machine.phase == .ready, readyThreadID == id, pageThreadID == id, let page,
            let store = knownStore
        else { return }
        page.captureArchive { [weak self] archive in
            guard let self, let archive else { return }
            Task { await self.keep(archive, of: id, in: store) }
        }
    }

    /// Saves the page as it is, when it is the ready page of the shown thread. A page that moved on, failed or went
    /// offline while the archive was made is not saved.
    func saveCopy() async {
        guard !Task.isCancelled, mode == .web, isOnline, machine.phase == .ready, let id = threadID,
            readyThreadID == id, pageThreadID == id, let page
        else { return }
        // The store is taken before the archive is made: an archive that finishes after the copies were erased meets
        // the erased store, which refuses it.
        guard let store = await copies() else { return }
        knownStore = store
        let archive = await withCheckedContinuation { continuation in
            page.captureArchive { continuation.resume(returning: $0) }
        }
        guard let archive else { return Self.log("A page archive could not be made") }
        guard !Task.isCancelled, machine.phase == .ready, isOnline, pageThreadID == id, threadID == id else { return }
        await keep(archive, of: id, in: store)
    }

    private func keep(_ archive: Data, of id: String, in store: OfflineCopyStore) async {
        guard let current = await copies(), current === store else { return }
        switch await store.save(archive, thread: id) {
        case .saved:
            copyThreadIDs.insert(id)
            if id == threadID { apply(.offlineCopyChanged(isAvailable: true)) }
        case .tooLarge: Self.log("A page archive was too large to keep")
        case .notSaved: Self.log("A page archive could not be saved")
        }
    }

    /// The saved copy could not be shown: it is dropped, forgotten and removed, and the plain offline state shows.
    func savedCopyFailed() {
        guard let id = savedCopy?.threadID ?? threadID else { return }
        Self.log("A saved copy could not be shown")
        copyThreadIDs.remove(id)
        apply(.offlineCopyChanged(isAvailable: false))
        savedCopy = nil
        Task { [copies] in await copies()?.remove(thread: id) }
    }

    fileprivate nonisolated static func log(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "WebConversation").error("\(message, privacy: .public)")
        #endif
    }
}

/// The app's background time while a copy is saved after the app left the foreground.
@MainActor
enum BackgroundTime {
    private final class Hold {
        var identifier = UIBackgroundTaskIdentifier.invalid
        func end() {
            guard identifier != .invalid else { return }
            UIApplication.shared.endBackgroundTask(identifier)
            identifier = .invalid
        }
    }

    static func begin() -> @MainActor () -> Void {
        let hold = Hold()
        hold.identifier = UIApplication.shared.beginBackgroundTask(withName: "SavedCopy") { hold.end() }
        return { hold.end() }
    }
}
