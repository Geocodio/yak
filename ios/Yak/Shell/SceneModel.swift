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
    /// The Overview shows beside the conversation on a wide window; it stays open across threads.
    var isOverviewPanelOpen = false
    /// The pane beside the focused one in a two-slot layout. Nil until one is needed.
    var secondPane: PaneID?
    /// The second pane sits first (leading or lower).
    var secondPaneLeads = false
    /// The pane the Overview panel covers while it is open.
    var behindPane: PaneID?
    /// The last pane that sat beside the focused one.
    var lastSecondPane: PaneID?
    /// The upper slot's share of the height in the stacked layout.
    var stackedFraction: Double = 0.5
}

extension SceneState {
    private enum CodingKeys: String, CodingKey {
        case instanceID, threadID, focusedPane, isDrawerOpen, isNewThreadOpen, filter, isOverviewPanelOpen
        case secondPane, secondPaneLeads, behindPane, lastSecondPane, stackedFraction
    }

    /// A stored state from an earlier version has no panel field and reads as closed.
    init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        instanceID = try container.decodeIfPresent(UUID.self, forKey: .instanceID)
        threadID = try container.decodeIfPresent(String.self, forKey: .threadID)
        // A pane name this build does not know reads as missing; it never discards the rest of the state.
        func pane(_ key: CodingKeys) -> PaneID? {
            (try? container.decodeIfPresent(String.self, forKey: key)).flatMap { $0 }.flatMap(PaneID.init(rawValue:))
        }
        focusedPane = pane(.focusedPane) ?? .chat
        secondPane = pane(.secondPane)
        behindPane = pane(.behindPane)
        lastSecondPane = pane(.lastSecondPane)
        secondPaneLeads = try container.decodeIfPresent(Bool.self, forKey: .secondPaneLeads) ?? false
        stackedFraction = try container.decodeIfPresent(Double.self, forKey: .stackedFraction) ?? 0.5
        isDrawerOpen = try container.decodeIfPresent(Bool.self, forKey: .isDrawerOpen) ?? false
        isNewThreadOpen = try container.decodeIfPresent(Bool.self, forKey: .isNewThreadOpen) ?? false
        filter = try container.decodeIfPresent(ThreadListFilter.self, forKey: .filter) ?? .mine
        isOverviewPanelOpen = try container.decodeIfPresent(Bool.self, forKey: .isOverviewPanelOpen) ?? false
    }
}

enum LinkOutcome: Equatable {
    case handled
    case switchInstance(Instance)
    case unknownInstance(URL)
    case openTaskPage(URL)
    case switchInstanceThenOpenTaskPage(Instance, URL)
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

    /// The instance this window shows; a thread of another instance is never kept.
    private(set) var activeInstance: UUID?
    /// What the thread layout shows now; nil while no thread screen is on screen.
    private(set) var plan: SlotPlan?
    /// Whether Settings is presented in this window. Not restored.
    var isSettingsOpen = false

    init(state: SceneState) {
        self.state = state
    }

    /// A window with an optional request. A requested or blank window never runs the cold start; a restored one keeps
    /// its thread only for the active instance.
    init(state: SceneState, request: WindowRequest?, activeInstance: UUID) {
        self.state = state
        self.activeInstance = activeInstance
        if let request {
            hasAppliedColdStart = true
            let id = request.threadToOpen(activeInstance: activeInstance)
            self.state.threadID = id
            self.state.instanceID = activeInstance
            self.state.isNewThreadOpen = false
            self.state.focusedPane = .chat
            self.state.secondPane = nil
            self.state.behindPane = nil
            self.state.isDrawerOpen = id == nil
        } else {
            applyInstanceRule(to: activeInstance)
        }
    }

    /// The active instance changed while the window is open.
    func instanceChanged(to instance: UUID) {
        activeInstance = instance
        applyInstanceRule(to: instance)
    }

    private func applyInstanceRule(to instance: UUID) {
        defer { activeInstance = instance }
        guard state.threadID != nil else { return }
        if !WindowRequest.keepsRestoredThread(storedInstance: state.instanceID, activeInstance: instance) {
            state.threadID = nil
            state.secondPane = nil
            state.behindPane = nil
            state.isOverviewPanelOpen = false
            state.isDrawerOpen = true
        }
    }

    /// The value this window carries in `WindowGroup(for:)`: its thread, or nil with none.
    var windowValue: WindowRequest? {
        guard let id = state.threadID, let activeInstance else { return nil }
        return .thread(instance: activeInstance, id: id)
    }

    /// A stored blob over this size is not decoded.
    static let maximumStoredBytes = 4096

    /// Reads a restored state; data that does not decode, or is too large, gives the default state.
    static func restoredState(from data: Data?) -> SceneState {
        guard let data, data.count <= maximumStoredBytes else { return SceneState() }
        return (try? JSONDecoder().decode(SceneState.self, from: data)) ?? SceneState()
    }

    // MARK: Panes

    /// The pane record the layout rules work on, built from the state. A second pane equal to the focused one
    /// (a push that focused it directly) is dropped.
    var paneState: PaneState {
        PaneState(
            focused: state.focusedPane, second: state.secondPane == state.focusedPane ? nil : state.secondPane,
            secondLeads: state.secondPaneLeads, behind: state.behindPane, stackedFraction: state.stackedFraction)
    }

    /// The only writer of the pane fields.
    func apply(_ paneState: PaneState) {
        state.focusedPane = paneState.focused
        state.secondPane = paneState.second
        state.secondPaneLeads = paneState.secondLeads
        state.behindPane = paneState.behind
        state.stackedFraction = PaneLayoutRules.clampedFraction(paneState.stackedFraction)
        if let second = paneState.second, second != .overview { state.lastSecondPane = second }
    }

    /// A pane picked in a bar, the rail, the row or by Command 1 to 5. `slot` is the bar whose menu was used.
    func select(_ pane: PaneID, from slot: SlotPosition? = nil) {
        guard let plan else {
            focus(pane: pane)
            return
        }
        apply(PaneLayoutRules.select(pane, from: slot, state: paneState, plan: plan))
    }

    /// A tap in a slot that is not focused.
    func focusSlot(_ position: SlotPosition) {
        guard let plan else { return }
        apply(PaneLayoutRules.focus(position, state: paneState, plan: plan))
    }

    func updatePlan(_ plan: SlotPlan?) {
        if self.plan != plan { self.plan = plan }
    }

    /// The pane in the focused slot, or the focused pane when no layout has reported yet.
    func shownFocusedPane(among panes: [PaneID]?) -> PaneID {
        if let plan, case .pane(let pane)? = plan.slots.first(where: { $0.position == plan.focusedPosition })?.content {
            return pane
        }
        return shownPane(among: panes)
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
        if let activeInstance { state.instanceID = activeInstance }
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
    func isShown(_ pane: PaneID, among panes: [PaneID]? = nil) -> Bool {
        if let plan {
            return plan.shownPanes.contains(pane)
                || (pane == .overview && plan.slots.contains { $0.content == .overviewPanel })
        }
        return shownPane(among: panes) == pane
    }
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
            guard let target = Self.instance(at: address, active: active, instances: instances) else {
                return .unknownInstance(address)
            }
            let page = target.url.appending(path: "tasks").appending(path: id)
            if target.id == active.id { return .openTaskPage(page) }
            hasAppliedColdStart = true
            state.instanceID = target.id
            return .switchInstanceThenOpenTaskPage(target, page)
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
