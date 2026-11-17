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
    @ObservationIgnored private var unsent: [OutboxItem] = []
    @ObservationIgnored private var expanded: [String: Bool] = [:]
    @ObservationIgnored private var searchTask: Task<Void, Never>?

    init(feed: ThreadListFeed, outbox: Outbox?, scene: SceneModel, clock: any YakClock) {
        self.feed = feed
        self.outbox = outbox
        self.scene = scene
        self.clock = clock
    }

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
    }
}
