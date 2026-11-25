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

    /// The tabs the server accepts; an older server's until `configure` says otherwise.
    private(set) var tabs = ThreadTabs(serverFilters: nil)
    /// The Review badge: every answer carries it, so it stays while another tab is on screen.
    private(set) var reviewCount = 0
    /// The person's own starter key, so the sheet can say "You".
    private(set) var viewerStarterKey: String?
    /// The Review groups the person unfolded; a group starts folded or open by its key.
    @ObservationIgnored private var openedGroups: Set<String> = []
    @ObservationIgnored private var hasConfigured = false

    /// The tab and filters the list shows: what the feed loads, so the row never says one thing over another list.
    /// An older server's `yak` filter reads as Mine started by Yak.
    var selection: ThreadListSelection {
        guard let state else { return scene.state.selection }
        if state.filter == .yak { return ThreadListSelection.migrated(.yak) }
        return ThreadListSelection(filter: state.filter, sources: state.sources, starters: state.starters)
    }

    var filter: ThreadListFilter { selection.filter }

    /// What the Filter sheet offers for the selected tab: nil until a list has loaded.
    var facets: ThreadFacets? { state?.list?.facets }

    func sourceChoices() -> [FilterChoice] {
        ThreadTabs.choices(from: facets?.sources ?? [], picked: selection.sources)
    }

    func starterChoices() -> [FilterChoice] {
        // An older server lists no facets and knows one starter filter, Yak.
        guard tabs.supportsFacets else {
            return [
                FilterChoice(
                    key: "yak", label: "yak", count: 0, isPicked: selection.starters.contains("yak"), role: .yak)
            ]
        }
        return ThreadTabs.choices(from: facets?.starters ?? [], picked: selection.starters, viewerKey: viewerStarterKey)
    }

    /// The server's tabs and the person's id arrive after the list may have started; a change re-reads the tab.
    func configure(serverFilters: [String]?, viewerID: String?) async {
        let next = ThreadTabs(serverFilters: serverFilters)
        let viewer = viewerID.map { "user:\($0)" }
        guard !hasConfigured || next != tabs || viewer != viewerStarterKey else { return }
        hasConfigured = true
        let tabsChanged = next != tabs
        tabs = next
        viewerStarterKey = viewer
        // Before the cold start the feed stays on Mine, which the cold start reads; the window's tab is applied after it.
        if tabsChanged, scene.hasAppliedColdStart { await syncFilter() }
    }

    /// Follows the feed and the outbox until the calling task is cancelled.
    func run() async {
        async let listing: Void = followFeed()
        async let unsentThreads: Void = followOutbox()
        _ = await (listing, unsentThreads)
    }

    private func followFeed() async {
        for await next in await feed.states() {
            state = next
            if let list = next.list { reviewCount = list.reviewCount }
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

    /// Picks a tab; the filters in the sheet stay on.
    func setFilter(_ filter: ThreadListFilter) async {
        var next = selection
        next.filter = filter
        scene.setSelection(next)
        await syncFilter()
    }

    func toggleSource(_ key: String) async { await toggle(key, in: \.sources) }
    func toggleStarter(_ key: String) async { await toggle(key, in: \.starters) }

    func clearFilters() async {
        var next = selection
        next.sources = []
        next.starters = []
        scene.setSelection(next)
        await syncFilter()
    }

    private func toggle(_ key: String, in field: WritableKeyPath<ThreadListSelection, [String]>) async {
        var next = selection
        if let index = next[keyPath: field].firstIndex(of: key) {
            next[keyPath: field].remove(at: index)
        } else {
            next[keyPath: field].append(key)
        }
        scene.setSelection(next)
        await syncFilter()
    }

    /// Points the feed at the tab and filters the scene holds and the text in the search field. A tab the server does
    /// not list reads as Mine.
    func syncFilter() async {
        searchTask?.cancel()
        let held = scene.state.selection
        if tabs.resolved(held.filter) != held.filter {
            var next = held
            next.filter = tabs.resolved(held.filter)
            scene.setSelection(next)
        }
        await sendToFeed()
    }

    private func sendToFeed() async {
        let held = scene.state.selection
        let asked = tabs.request(filter: held.filter, starters: held.starters)
        await feed.setFilter(asked.filter, search: search, sources: held.sources, starters: asked.starters)
    }

    /// A Review group folds open or shut.
    func toggleGroup(_ key: String) {
        if !openedGroups.insert(key).inserted { openedGroups.remove(key) }
        recompute()
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
            await self.sendToFeed()
        }
    }

    private func recompute() {
        guard let state, let list = state.list else {
            content = nil
            return
        }
        let next = DrawerSections.make(
            list: list, filter: state.filter, search: state.search, expanded: expanded, unsentNewThreads: unsent,
            openedGroups: openedGroups)
        if next != content { content = next }
        rootOfChild = [:]
        let needing = list.needsYou.compactMap { item -> ThreadRow? in
            if case .thread(let row) = item { row } else { nil }
        }
        for root in list.threads + list.starred + needing + list.reviewGroups.flatMap(\.rows) {
            noteDescendants(of: root, root: root.id)
        }
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
