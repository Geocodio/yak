import Foundation
import Observation
import YakKit

/// What the drawer shows: the thread list of the feed, the unsent new threads of the outbox and the person's
/// search, filter and expanded rows. Views read finished values.
@MainActor @Observable
final class DrawerModel {
    /// How long the typing must stop before the search is sent.
    static let searchDelay: Duration = .milliseconds(300)

    private(set) var content: DrawerContent?
    private(set) var state: ThreadListState?
    /// The search text as typed; the list follows it 300 ms after the last change.
    var search = "" {
        didSet {
            guard search != oldValue else { return }
            scheduleSearch()
        }
    }

    @ObservationIgnored private let feed: ThreadListFeed
    @ObservationIgnored private let outbox: Outbox?
    @ObservationIgnored private let scene: SceneModel
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private let overview: OverviewActions?
    @ObservationIgnored private var unsent: [OutboxItem] = []
    @ObservationIgnored private var expanded: [String: Bool] = [:]
    @ObservationIgnored private var searchTask: Task<Void, Never>?
    @ObservationIgnored private var stackTasks: [String: (token: UUID, task: Task<Void, Never>)] = [:]
    /// The roots whose stack was fetched during this visit of the drawer, so a list update never asks again.
    @ObservationIgnored private var fetchedRoots: Set<String> = []
    @ObservationIgnored private var lineTask: Task<Void, Never>?
    @ObservationIgnored private var isVisible = false
    /// The root of every thread in the list, by thread id.
    @ObservationIgnored private var rootOfChild: [String: String] = [:]
    /// The stacks the menus read, by root id.
    private var stacks: [String: (stack: ThreadStack, fetchedAt: Date)] = [:]
    /// The sub-thread an action is running on.
    private(set) var runningChildID: String?
    /// The server's sentence about a refused action; it leaves by itself.
    private(set) var actionLine: String?
    private(set) var refusalCount = 0

    static let actionLineLifetime: Duration = .seconds(6)

    init(
        feed: ThreadListFeed, outbox: Outbox?, scene: SceneModel, clock: any YakClock,
        overview: OverviewActions? = nil
    ) {
        self.feed = feed
        self.outbox = outbox
        self.scene = scene
        self.clock = clock
        self.overview = overview
    }

    var isOffline: Bool { state?.isOffline ?? false }

    /// How many items wait for the person, for the threads button's badge.
    var needsYouCount: Int { state?.list?.needsYouCount ?? 0 }

    /// How many tasks wait for the person, for the badge on the Tasks tile.
    var needsYouTaskCount: Int {
        state?.list?.needsYou.filter { if case .task = $0 { true } else { false } }.count ?? 0
    }

    var filter: ThreadListFilter { scene.state.filter }

    /// Follows the feed and the outbox until the calling task is cancelled.
    func run() async {
        async let listing: Void = followFeed()
        async let unsentThreads: Void = followOutbox()
        _ = await (listing, unsentThreads)
    }

    private func followFeed() async {
        for await next in await feed.states() {
            state = next
            recompute()
        }
    }

    private func followOutbox() async {
        guard let outbox else { return }
        for await items in await outbox.changes() {
            unsent = items.filter { if case .newThread = $0.target { true } else { false } }
            recompute()
        }
    }

    /// True while the drawer is on screen and the app is in front; the feed polls only then.
    func setVisible(_ visible: Bool) async {
        isVisible = visible
        if visible { prefetchStacks() } else { cancelStackTasks() }
        await feed.setVisible(visible)
    }

    func setFilter(_ filter: ThreadListFilter) async {
        scene.setFilter(filter)
        await syncFilter()
    }

    /// Points the feed at the filter the scene shows and the text in the search field.
    func syncFilter() async {
        searchTask?.cancel()
        await feed.setFilter(scene.state.filter, search: search)
    }

    func toggle(_ rowID: String) {
        guard case .sections(let sections)? = content,
            let row = sections.lazy.flatMap(\.rows).first(where: { $0.id == rowID })
        else { return }
        expanded[rowID] = !row.isExpanded
        recompute()
    }

    func unsentItem(_ id: UUID) -> OutboxItem? { unsent.first { $0.id == id } }

    func retry() async {
        await feed.refresh()
    }

    /// Searches everyone's threads for the same text.
    func widenSearch() async {
        await setFilter(.everyone)
    }

    private func scheduleSearch() {
        searchTask?.cancel()
        searchTask = Task { [clock] in
            do { try await clock.sleep(for: Self.searchDelay) } catch { return }
            await self.feed.setFilter(self.scene.state.filter, search: self.search)
        }
    }

    private func recompute() {
        guard let state, let list = state.list else {
            content = nil
            return
        }
        let next = DrawerSections.make(
            list: list, filter: state.filter, search: state.search, expanded: expanded, unsentNewThreads: unsent)
        if next != content { content = next }
        rootOfChild = [:]
        let needing = list.needsYou.compactMap { item -> ThreadRow? in
            if case .thread(let row) = item { row } else { nil }
        }
        for root in list.threads + list.starred + needing { noteDescendants(of: root, root: root.id) }
        prefetchStacks()
    }

    private func noteDescendants(of row: ThreadRow, root: String) {
        for child in row.children {
            rootOfChild[child.id] = root
            noteDescendants(of: child, root: root)
        }
    }

    // MARK: Sub-thread menus

    /// The root rows that are expanded.
    private var expandedRoots: Set<String> {
        guard case .sections(let sections)? = content else { return [] }
        return Set(
            sections.flatMap(\.rows).compactMap { row in
                if case .thread(let id) = row.target, row.depth == 0, row.childCount > 0, row.isExpanded {
                    id
                } else {
                    nil
                }
            })
    }

    /// Fetches the stack of every expanded root once per visit; collapsing a root or hiding the drawer cancels its
    /// fetch and forgets that it was fetched.
    private func prefetchStacks() {
        guard isVisible else { return }
        let roots = expandedRoots
        for (root, running) in stackTasks where !roots.contains(root) {
            running.task.cancel()
            stackTasks[root] = nil
        }
        fetchedRoots.formIntersection(roots)
        for root in roots where !fetchedRoots.contains(root) { fetchStack(of: root) }
    }

    private func fetchStack(of root: String) {
        guard let overview else { return }
        fetchedRoots.insert(root)
        let token = UUID()
        let task = Task { [weak self] in
            // A failed fetch is logged by the actions; the menu stays in its loading state.
            if let answer = try? await overview.stack(root) { self?.stacks[root] = answer }
            // A fetch that was cancelled and replaced must not remove the handle of the one that replaced it.
            if self?.stackTasks[root]?.token == token { self?.stackTasks[root] = nil }
        }
        stackTasks[root] = (token, task)
    }

    private func cancelStackTasks() {
        for running in stackTasks.values { running.task.cancel() }
        stackTasks = [:]
        fetchedRoots = []
    }

    /// What a long press on a sub-thread row offers.
    func childMenu(_ row: DrawerRow) -> DrawerChildMenu {
        guard case .thread(let id) = row.target, let root = rootOfChild[id] else { return .ready([.open]) }
        let known = stacks[root]
        return SubThreadActions.forDrawerRow(
            childID: id, stack: known?.stack, fetchedAt: known?.fetchedAt, now: clock.now())
    }

    /// A menu is opening: a stack older than ten seconds is fetched again, so the next press has the actions.
    func menuOpening(_ row: DrawerRow) {
        guard case .thread(let id) = row.target, let root = rootOfChild[id], stackTasks[root] == nil else { return }
        if case .loading = childMenu(row) { fetchStack(of: root) }
    }

    func perform(_ action: SubThreadAction, on row: DrawerRow) async {
        guard action != .open, case .thread(let id) = row.target, let overview, runningChildID == nil else { return }
        runningChildID = id
        setActionLine(nil)
        let outcome: ThreadActionOutcome
        switch action {
        case .takeOver: outcome = await overview.takeOver(id)
        case .split: outcome = await overview.split(id)
        default: outcome = await overview.drop(id)
        }
        runningChildID = nil
        switch outcome {
        case .done, .alreadySent:
            if let root = rootOfChild[id] { stacks[root] = nil }
            await feed.refresh()
        case .refused(_, let message, _, _):
            refusalCount += 1
            setActionLine(message)
        case .failed(let problem):
            refusalCount += 1
            setActionLine(
                problem == .offline
                    ? String(localized: "overview.action.notSent") : OverviewModel.sentence(for: problem))
        }
    }

    private func setActionLine(_ text: String?) {
        actionLine = text
        lineTask?.cancel()
        guard text != nil else { return }
        lineTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.actionLineLifetime) } catch { return }
            self?.actionLine = nil
        }
    }
}
