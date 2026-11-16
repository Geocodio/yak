import Foundation
import Observation
import YakKit

/// The pull requests of one thread: the rows and banner of the feed, and the "Ask Yak to fix" sends. The rules for
/// what is shown are YakKit's.
@MainActor @Observable
final class PullRequestCardModel {
    enum FixState: Equatable {
        case sending
        case sent
        case failed(String)
    }

    let threadID: String
    private(set) var rows: [PullRequestCardRow] = []
    private(set) var panel: PullRequestsPanel?
    private(set) var banner: PullRequestBanner?
    private(set) var load: ThreadLoadState = .loading
    private(set) var problem: ThreadProblem?
    private(set) var fixing: [Int: FixState] = [:]
    private(set) var successCount = 0
    private(set) var errorCount = 0

    @ObservationIgnored private let session: InstanceSession
    @ObservationIgnored private var feed: PullRequestsFeed?
    @ObservationIgnored private var actions: PullRequestActions?
    @ObservationIgnored private var readingTask: Task<Void, Never>?
    @ObservationIgnored private var seenVersion: String?

    init(threadID: String, session: InstanceSession) {
        self.threadID = threadID
        self.session = session
    }

    var withoutPullRequest: [RepositoryWithoutPullRequest] { panel?.withoutPullRequest ?? [] }
    var expectations: [String] { panel?.expectations ?? [] }
    var isEmpty: Bool { panel?.pullRequests.isEmpty ?? false }

    func start() async {
        guard feed == nil else { return }
        guard let client = await session.apiClient() else {
            load = .failed(.offline)
            return
        }
        let opened = PullRequestsFeed(threadID: threadID, client: client, clock: session.clock)
        feed = opened
        actions = PullRequestActions(threadID: threadID, client: client)
        let stream = await opened.snapshots()
        readingTask = Task { [weak self] in
            for await next in stream { self?.apply(next) }
        }
        await opened.setVisible(true)
    }

    func stop() async {
        readingTask?.cancel()
        readingTask = nil
        let closing = feed
        feed = nil
        await closing?.setVisible(false)
        await closing?.close()
    }

    func reload() async { await feed?.reload() }

    /// A newer `thread` or `repository` event means the pull requests may have changed.
    func threadChanged(_ snapshot: ConversationSnapshot) async {
        let version =
            "\(snapshot.show?.pullRequest?.number ?? 0):\(snapshot.show?.thread.pullRequestOpening?.state.rawValue ?? "")"
        guard seenVersion != nil, seenVersion != version else {
            seenVersion = version
            return
        }
        seenVersion = version
        await feed?.reload()
    }

    private func apply(_ next: PullRequestsFeed.Snapshot) {
        load = next.load
        problem = next.problem
        if let panel = next.panel {
            self.panel = panel
            rows = PullRequestCardRows.make(panel)
            banner = panel.banner
            // A fix that was sent stays "sent" until the row stops failing.
            for (id, state) in fixing where state == .sent {
                if rows.first(where: { $0.id == id })?.canAskToFix != true { fixing[id] = nil }
            }
        }
    }

    func askToFix(_ rowID: Int) async {
        guard fixing[rowID] != .sending, fixing[rowID] != .sent, let actions else { return }
        fixing[rowID] = .sending
        switch await actions.askToFix(rowID: rowID) {
        case .done, .alreadySent:
            fixing[rowID] = .sent
            successCount += 1
        case .refused(_, let message, _):
            fixing[rowID] = .failed(message)
            errorCount += 1
        case .failed(let failure):
            errorCount += 1
            if failure == .offline {
                fixing[rowID] = .failed(String(localized: "pullRequest.fix.offline"))
            } else if case .server(_, let message) = failure, !message.isEmpty {
                fixing[rowID] = .failed(message)
            } else {
                fixing[rowID] = .failed(String(localized: "pullRequest.fix.failed"))
            }
        }
    }
}
