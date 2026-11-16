import Foundation
import Observation
import YakKit

/// What one window restores after a relaunch.
struct SceneState: Codable, Equatable {
    /// The instance the thread and filter belong to; a restored state for another instance is not used.
    var instanceID: UUID?
    var threadID: String?
    var focusedPane: PaneID = .chat
    var isDrawerOpen = false
    var isNewThreadOpen = false
    var filter: ThreadListFilter = .mine
}

enum LinkOutcome: Equatable {
    case handled
    case switchInstance(Instance)
    case unknownInstance(URL)
    case openTaskPage(URL)
}

/// Where one window is: the open thread, the focused pane, the drawer and the new-thread screen.
@MainActor @Observable
final class SceneModel {
    private(set) var state: SceneState
    private(set) var hasAppliedColdStart = false
    /// Shows "Signed in as <name>" in the drawer once after a pairing.
    var justPairedUserName: String?
    var newThreadPrefill: (repo: String?, text: String?)?
    /// The outbox item of an unsent new thread the person opened from the drawer.
    var newThreadItem: UUID?
    /// Counts the requests to open the new-thread screen, so a screen that is already open starts again for an
    /// unsent item or a link.
    private(set) var newThreadOpenCount = 0
    /// Counts the requests to put the cursor in the drawer's search field (Command K).
    private(set) var searchFocusRequest = 0
    /// Counts the requests to put the cursor in the composer (the activity strip).
    private(set) var composerFocusRequest = 0

    init(state: SceneState) {
        self.state = state
    }

    /// Reads a restored state; data that does not decode gives the default state.
    static func restoredState(from data: Data?) -> SceneState {
        data.flatMap { try? JSONDecoder().decode(SceneState.self, from: $0) } ?? SceneState()
    }

    var encodedState: Data? { try? JSONEncoder().encode(state) }

    /// Only the first call changes anything; later list changes never move the person.
    func applyColdStart(_ destination: ColdStartDestination) {
        guard !hasAppliedColdStart else { return }
        hasAppliedColdStart = true
        switch destination {
        case .thread(let id):
            state.threadID = id
            state.isNewThreadOpen = false
            state.isDrawerOpen = false
        case .drawer(let filter):
            state.filter = filter
            state.isDrawerOpen = true
        case .newThread:
            state.isNewThreadOpen = true
            state.isDrawerOpen = false
        }
    }

    /// A fresh window for `instanceID`: the cold start runs again. The banner after a pairing stays.
    func reset(instanceID: UUID) {
        state = SceneState(instanceID: instanceID)
        hasAppliedColdStart = false
        newThreadPrefill = nil
        newThreadItem = nil
    }

    func open(thread id: String, isCompactWidth: Bool) {
        state.threadID = id
        state.focusedPane = .chat
        state.isNewThreadOpen = false
        if isCompactWidth { state.isDrawerOpen = false }
        justPairedUserName = nil
    }

    func openNewThread(repo: String?, text: String?, item: UUID? = nil) {
        newThreadPrefill = (repo, text)
        newThreadItem = item
        if item != nil || repo != nil || text != nil { newThreadOpenCount += 1 }
        state.isNewThreadOpen = true
        state.isDrawerOpen = false
        justPairedUserName = nil
    }

    func setDrawerOpen(_ open: Bool) { state.isDrawerOpen = open }
    /// Leaves the open thread, so a wide window shows its empty detail next to the always visible list.
    func closeThread() { state.threadID = nil }
    func focus(pane: PaneID) { state.focusedPane = pane }
    /// Whether `pane` is on screen. A pane or its model that pauses work, polls or shows a chip only while visible
    /// asks this, never `focusedPane` itself, so a layout that shows two panes changes only this body.
    /// With `panes`, a focused pane the thread does not have counts as chat, the pane the thread screen then shows.
    func isShown(_ pane: PaneID, among panes: [PaneID]? = nil) -> Bool { shownPane(among: panes) == pane }
    func shownPane(among panes: [PaneID]?) -> PaneID {
        guard let panes else { return state.focusedPane }
        return panes.contains(state.focusedPane) ? state.focusedPane : .chat
    }
    func requestSearchFocus() {
        state.isDrawerOpen = true
        searchFocusRequest += 1
    }
    /// The composer may not exist yet when the request comes (it lives in Chat's bar), so it also reads the pending
    /// flag when it appears.
    private(set) var isComposerFocusPending = false
    func requestComposerFocus() {
        isComposerFocusPending = true
        composerFocusRequest += 1
    }
    func takeComposerFocus() -> Bool {
        defer { isComposerFocusPending = false }
        return isComposerFocusPending
    }
    func setFilter(_ filter: ThreadListFilter) { state.filter = filter }

    func handle(_ link: DeepLink, active: Instance, instances: [Instance], isCompactWidth: Bool) -> LinkOutcome {
        switch link {
        case .pair:
            return .handled
        case .task(let address, let id):
            return .openTaskPage(address.appending(path: "tasks").appending(path: id))
        case .thread(let address, let id):
            guard let target = Self.instance(at: address, active: active, instances: instances) else {
                return .unknownInstance(address)
            }
            if target.id == active.id {
                open(thread: id, isCompactWidth: isCompactWidth)
                return .handled
            }
            // The shell of the other instance starts on this thread.
            hasAppliedColdStart = true
            state.instanceID = target.id
            open(thread: id, isCompactWidth: true)
            return .switchInstance(target)
        case .newThread(let address, let repo, let text):
            guard let target = Self.instance(at: address, active: active, instances: instances) else {
                return .unknownInstance(address)
            }
            openNewThread(repo: repo, text: text)
            if target.id == active.id { return .handled }
            hasAppliedColdStart = true
            state.instanceID = target.id
            return .switchInstance(target)
        }
    }

    static func isSameAddress(_ first: URL, _ second: URL) -> Bool {
        func key(_ url: URL) -> String { url.absoluteString.lowercased().trimmingSuffix("/") }
        return key(first) == key(second)
    }

    /// The paired instance whose normalized address is `address`.
    private static func instance(at address: URL, active: Instance, instances: [Instance]) -> Instance? {
        if isSameAddress(active.url, address) { return active }
        return instances.first { isSameAddress($0.url, address) }
    }
}

extension String {
    fileprivate func trimmingSuffix(_ suffix: String) -> String {
        hasSuffix(suffix) ? String(dropLast(suffix.count)) : self
    }
}
