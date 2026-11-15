import Foundation
import Observation
import YakKit

/// What one window restores after a relaunch.
struct SceneState: Codable, Equatable {
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

    func open(thread id: String, isCompactWidth: Bool) {
        state.threadID = id
        state.focusedPane = .chat
        state.isNewThreadOpen = false
        if isCompactWidth { state.isDrawerOpen = false }
        justPairedUserName = nil
    }

    func openNewThread(repo: String?, text: String?) {
        newThreadPrefill = (repo, text)
        state.isNewThreadOpen = true
        state.isDrawerOpen = false
        justPairedUserName = nil
    }

    func setDrawerOpen(_ open: Bool) { state.isDrawerOpen = open }
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
            open(thread: id, isCompactWidth: true)
            return .switchInstance(target)
        case .newThread(let address, let repo, let text):
            guard let target = Self.instance(at: address, active: active, instances: instances) else {
                return .unknownInstance(address)
            }
            openNewThread(repo: repo, text: text)
            if target.id == active.id { return .handled }
            hasAppliedColdStart = true
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
