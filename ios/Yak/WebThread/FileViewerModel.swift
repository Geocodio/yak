import Foundation
import Observation
import YakKit

/// The web view of the file viewer, as far as the model needs it.
@MainActor protocol FileViewerPage: AnyObject {
    func load(_ request: URLRequest)
    func stop()
}

/// What the file viewer shows: one shared file's page, and the state of loading it. The web view reports what
/// happened; this decides what the person sees.
@MainActor @Observable
final class FileViewerModel {
    enum Phase: Equatable {
        case loading
        /// No answer after `slowAfter`; still waiting.
        case slow
        case ready
        /// The server has no such file for this person (403, 404, 410).
        case unavailable
        case failed(Problem)
        case offline
    }

    enum Problem: Equatable {
        case network, server, timeout, session, crashed
    }

    /// The same waits as the thread page.
    static let slowAfter: Duration = WebConversationMachine.Timing().slowAfter
    static let failAfter: Duration = WebConversationMachine.Timing().failAfter

    let url: URL
    let policy: FileViewerPolicy
    private(set) var phase: Phase = .loading
    /// The file's name, from the page's title once it is loaded.
    private(set) var title: String?
    private(set) var isOnline: Bool
    @ObservationIgnored private(set) var page: (any FileViewerPage)?
    @ObservationIgnored private let renewSession: @MainActor () async -> Bool
    @ObservationIgnored private let sleep: @Sendable (Duration) async throws -> Void
    @ObservationIgnored private var hasRenewed = false
    @ObservationIgnored private var timerTask: Task<Void, Never>?
    @ObservationIgnored private var renewTask: Task<Void, Never>?

    init(
        url: URL, policy: FileViewerPolicy, isOnline: Bool,
        renewSession: @escaping @MainActor () async -> Bool,
        sleep: @escaping @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }
    ) {
        self.url = url
        self.policy = policy
        self.isOnline = isOnline
        self.renewSession = renewSession
        self.sleep = sleep
    }

    /// The web view is made; the file loads now.
    func attach(_ page: any FileViewerPage) {
        self.page = page
        start()
    }

    /// Loads the file again after a failure the person asked to retry.
    func retry() {
        hasRenewed = false
        start()
    }

    /// The connection came back: a load that waited for it, or failed for lack of it, starts again.
    func connectivityChanged(isOnline: Bool) {
        guard self.isOnline != isOnline else { return }
        self.isOnline = isOnline
        guard isOnline else {
            // A load that failed just before the app noticed the connection was gone was a lack of connection.
            if phase == .failed(.network) { phase = .offline }
            return
        }
        if phase == .offline || phase == .failed(.network) { start() }
    }

    /// Cancels what is running; the viewer closed.
    func close() {
        timerTask?.cancel()
        renewTask?.cancel()
        page?.stop()
    }

    private func start() {
        timerTask?.cancel()
        renewTask?.cancel()
        guard isOnline else {
            phase = .offline
            return
        }
        phase = .loading
        var request = URLRequest(url: url)
        request.cachePolicy = .reloadIgnoringLocalCacheData
        page?.load(request)
        timerTask = Task { [weak self, sleep] in
            do { try await sleep(Self.slowAfter) } catch { return }
            self?.waitedLong()
            do { try await sleep(Self.failAfter - Self.slowAfter) } catch { return }
            self?.waitedTooLong()
        }
    }

    private var isWaiting: Bool { phase == .loading || phase == .slow }

    private func waitedLong() { if phase == .loading { phase = .slow } }

    private func waitedTooLong() {
        guard isWaiting else { return }
        renewTask?.cancel()
        page?.stop()
        phase = .failed(.timeout)
    }

    // MARK: From the web view

    /// The page and what it frames are loaded.
    func finished(pageTitle: String?) {
        guard isWaiting else { return }
        timerTask?.cancel()
        title = FileViewerPolicy.name(fromPageTitle: pageTitle) ?? title
        phase = .ready
    }

    /// A response of 400 or more, from the page or from what it frames.
    func failed(_ failure: FileViewerPolicy.Failure) {
        switch failure {
        case .signedOut: signedOut()
        case .unavailable: end(.unavailable)
        case .server: end(.failed(.server))
        }
    }

    /// The load itself failed: no connection, a lost connection or a refused one.
    func loadFailed() {
        guard isWaiting else { return }
        end(isOnline ? .failed(.network) : .offline)
    }

    func processEnded() { end(.failed(.crashed)) }

    private func end(_ phase: Phase) {
        timerTask?.cancel()
        renewTask?.cancel()
        self.phase = phase
    }

    /// The session is not accepted: a new one is minted once, by the thread page's own hand-off, and the file loads
    /// again. A second refusal is an error with Retry.
    private func signedOut() {
        guard isWaiting else { return }
        guard !hasRenewed else { return end(.failed(.session)) }
        hasRenewed = true
        phase = .loading
        renewTask?.cancel()
        renewTask = Task { [weak self, renewSession] in
            let isRenewed = await renewSession()
            guard !Task.isCancelled, let self else { return }
            if isRenewed { start() } else { end(.failed(.session)) }
        }
    }

    // MARK: Presentation

    /// The load states in the words and look of the thread page.
    var presentation: ThreadLoadPresentation {
        switch phase {
        case .loading: .loading
        case .slow: .slow
        case .ready: .conversation
        case .unavailable: .serverError(message: String(localized: "viewer.failed.unavailable"))
        case .offline: .offlineWithoutCopy
        case .failed(let problem):
            switch problem {
            case .network: .serverError(message: String(localized: "viewer.failed.network"))
            case .server: .serverError(message: String(localized: "viewer.failed.server"))
            case .timeout: .serverError(message: String(localized: "viewer.failed.timeout"))
            case .session: .serverError(message: String(localized: "viewer.failed.session"))
            case .crashed: .serverError(message: String(localized: "viewer.failed.crashed"))
            }
        }
    }
}
