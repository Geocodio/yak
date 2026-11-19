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

    /// The state of the merge-order save.
    enum OrderState: Equatable {
        case idle
        case saving
        /// Still waiting after `slowAfter`; the send is not repeated.
        case slow
        case failed(String)
    }

    enum EditAvailability: Equatable {
        case hidden
        case offline
        case available
    }

    /// How long a save runs before the card says it is taking longer than usual.
    static let slowAfter: Duration = .seconds(8)

    let threadID: String
    private(set) var isEditing = false
    /// The open rows as the person arranged them while editing; nil outside editing.
    private(set) var draftOpenIDs: [Int]?
    private(set) var orderState: OrderState = .idle
    /// Set when the list changed on the server and was reloaded.
    private(set) var orderNotice: String?
    /// What VoiceOver says after a move.
    private(set) var orderAnnouncement: String?
    private(set) var orderSuccessCount = 0
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
    @ObservationIgnored private let slowAfter: Duration
    @ObservationIgnored private var slowTask: Task<Void, Never>?
    /// The newest panel that arrived while editing; it is shown when the move ends.
    @ObservationIgnored private var heldPanel: PullRequestsPanel?
    @ObservationIgnored private var failedOrder: [Int]?

    init(threadID: String, session: InstanceSession, slowAfter: Duration = PullRequestCardModel.slowAfter) {
        self.threadID = threadID
        self.session = session
        self.slowAfter = slowAfter
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
        slowTask?.cancel()
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
            // Rows never move under the finger: while editing, the newest panel waits for the end of the move.
            if isEditing {
                heldPanel = panel
                return
            }
            adopt(panel)
        }
    }

    private func adopt(_ panel: PullRequestsPanel) {
        self.panel = panel
        rows = PullRequestCardRows.make(panel)
        banner = panel.banner
        // A fix that was sent stays "sent" until the row stops failing.
        for (id, state) in fixing where state == .sent {
            if rows.first(where: { $0.id == id })?.canAskToFix != true { fixing[id] = nil }
        }
    }

    func askToFix(_ rowID: Int) async {
        guard fixing[rowID] != .sending, fixing[rowID] != .sent, let actions else { return }
        fixing[rowID] = .sending
        switch await actions.askToFix(rowID: rowID) {
        case .done, .alreadySent:
            fixing[rowID] = .sent
            successCount += 1
        case .refused(_, let message, _, _):
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

    // MARK: Merge order

    var canEditOrder: Bool { PullRequestOrder.canEdit(canDrive: panel?.canDrive ?? false, rows: rows) }

    func editAvailability(isOnline: Bool) -> EditAvailability {
        guard canEditOrder else { return .hidden }
        return isOnline ? .available : .offline
    }

    var isSavingOrder: Bool { orderState == .saving || orderState == .slow }

    /// The open rows in the order shown: the person's arrangement while editing, the server's otherwise.
    var openRows: [PullRequestCardRow] {
        let open = rows.filter { $0.state == .open }
        guard let draftOpenIDs else { return open }
        return draftOpenIDs.compactMap { id in open.first { $0.id == id } }
    }

    func beginEditing(isOnline: Bool) {
        guard editAvailability(isOnline: isOnline) == .available else { return }
        orderNotice = nil
        orderState = .idle
        draftOpenIDs = PullRequestOrder.openIDs(rows)
        isEditing = true
    }

    func endEditing() {
        guard !isSavingOrder else { return }
        isEditing = false
        draftOpenIDs = nil
        orderState = .idle
        failedOrder = nil
        flushHeldPanel()
    }

    /// A drag ended: `source` rows were dropped at `destination` (SwiftUI's `onMove` numbering).
    func moveRows(from source: IndexSet, to destination: Int) async {
        guard isEditing else { return }
        let current = draftOpenIDs ?? PullRequestOrder.openIDs(rows)
        await commit(
            PullRequestOrder.moving(current, from: source, to: destination), movedID: source.first.map { current[$0] })
    }

    /// The VoiceOver "Move up" and "Move down" actions.
    func moveRow(_ id: Int, by step: Int) async {
        let current = draftOpenIDs ?? PullRequestOrder.openIDs(rows)
        guard let next = PullRequestOrder.moving(current, id: id, by: step) else { return }
        await commit(next, movedID: id)
    }

    func retryOrder() async {
        guard let failedOrder else { return }
        await commit(failedOrder, movedID: nil)
    }

    private func commit(_ next: [Int], movedID: Int?) async {
        guard isEditing, !isSavingOrder, let actions, let feed else { return }
        let previous = draftOpenIDs ?? PullRequestOrder.openIDs(rows)
        guard next != previous else { return }
        draftOpenIDs = next
        orderNotice = nil
        failedOrder = nil
        orderState = .saving
        startSlowTimer()
        let full = PullRequestOrder.fullOrder(
            allIDs: rows.map(\.id), openIDs: Set(previous), openOrder: next)
        let outcome = await actions.reorder(full)
        slowTask?.cancel()
        slowTask = nil
        switch outcome {
        case .done, .alreadySent:
            await confirm(next, fullOrder: full, feed: feed)
            if let movedID, let place = next.firstIndex(of: movedID) {
                orderAnnouncement = String(localized: "pullRequest.order.moved \(place + 1) \(next.count)")
            }
        case .refused(let code, let message, let fieldErrors, _):
            draftOpenIDs = previous
            // The server answers 422 with an error on `order` when the pull requests of the thread are not the ones
            // that were sent.
            if code == "validation_failed", !(fieldErrors["order"] ?? []).isEmpty {
                await reloadAfterChange(feed: feed)
            } else {
                fail(message.isEmpty ? String(localized: "pullRequest.order.failed") : message, retry: next)
            }
        case .failed(let failure):
            draftOpenIDs = previous
            if failure == .offline {
                fail(String(localized: "pullRequest.order.offline"), retry: next)
            } else if case .server(_, let message) = failure, !message.isEmpty {
                fail(message, retry: next)
            } else {
                fail(String(localized: "pullRequest.order.failed"), retry: next)
            }
        }
        settleHeldPanel()
    }

    /// The drop is over: a panel that arrived meanwhile is shown now, and a changed set of open rows is said.
    private func settleHeldPanel() {
        guard orderState == .idle, let held = heldPanel else { return }
        heldPanel = nil
        let before = Set(draftOpenIDs ?? [])
        adopt(held)
        let open = PullRequestOrder.openIDs(rows)
        draftOpenIDs = open
        if Set(open) != before { orderNotice = String(localized: "pullRequest.order.changed") }
        leaveEditingIfNothingToOrder()
    }

    private func fail(_ message: String, retry order: [Int]) {
        orderState = .failed(message)
        failedOrder = order
        errorCount += 1
    }

    /// The server answered "updated": read the list again and keep it as the truth. A list that differs from what
    /// was sent means a pull request merged or closed meanwhile.
    private func confirm(_ sent: [Int], fullOrder: [Int], feed: PullRequestsFeed) async {
        guard let fetched = await feed.reload() else {
            // The save worked but the reload did not: the moved order stays on screen, and a panel read before the
            // save is dropped so it cannot move the row back.
            heldPanel = nil
            rows = rows.sorted { (fullOrder.firstIndex(of: $0.id) ?? 0) < (fullOrder.firstIndex(of: $1.id) ?? 0) }
            draftOpenIDs = sent
            orderState = .idle
            orderSuccessCount += 1
            return
        }
        heldPanel = nil
        adopt(fetched)
        let serverOpen = PullRequestOrder.openIDs(rows)
        draftOpenIDs = serverOpen
        orderState = .idle
        if serverOpen == sent {
            orderSuccessCount += 1
        } else {
            orderNotice = String(localized: "pullRequest.order.changed")
        }
        leaveEditingIfNothingToOrder()
    }

    private func reloadAfterChange(feed: PullRequestsFeed) async {
        if let fetched = await feed.reload() {
            heldPanel = nil
            adopt(fetched)
            draftOpenIDs = PullRequestOrder.openIDs(rows)
        }
        orderState = .idle
        orderNotice = String(localized: "pullRequest.order.changed")
        errorCount += 1
        leaveEditingIfNothingToOrder()
    }

    private func flushHeldPanel() {
        guard let held = heldPanel else { return }
        heldPanel = nil
        adopt(held)
    }

    private func leaveEditingIfNothingToOrder() {
        guard !canEditOrder else { return }
        isEditing = false
        draftOpenIDs = nil
    }

    private func startSlowTimer() {
        slowTask?.cancel()
        slowTask = Task { [weak self, slowAfter] in
            do { try await Task.sleep(for: slowAfter) } catch { return }
            guard let self, self.orderState == .saving else { return }
            self.orderState = .slow
        }
    }
}
