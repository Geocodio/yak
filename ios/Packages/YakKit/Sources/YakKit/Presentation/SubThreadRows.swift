import Foundation

public enum SubThreadAction: Equatable, Sendable { case open, takeOver, split, drop }

public enum SubThreadDetail: Equatable, Sendable {
    case step(String)
    case result(String)
    case queued, messagesPaused, takenOver
    case state(StackChildState)
}

public struct SubThreadRow: Equatable, Sendable, Identifiable {
    public let id: String
    public let title: String
    public let kind: StackChildKind
    public let state: StackChildState
    public let detail: SubThreadDetail
    public let filesChanged: Int?
    public let pullRequestURL: URL?
    public let actions: [SubThreadAction]

    public init(
        id: String, title: String, kind: StackChildKind, state: StackChildState, detail: SubThreadDetail,
        filesChanged: Int?, pullRequestURL: URL?, actions: [SubThreadAction]
    ) {
        self.id = id
        self.title = title
        self.kind = kind
        self.state = state
        self.detail = detail
        self.filesChanged = filesChanged
        self.pullRequestURL = pullRequestURL
        self.actions = actions
    }
}

public enum DrawerChildMenu: Equatable, Sendable {
    /// Open only, and a disabled "Loading actions" row.
    case loading
    case ready([SubThreadAction])
}

public enum SubThreadActions {
    /// A stack fetched this long ago or longer no longer decides which actions a child offers.
    public static let freshFor: Duration = .seconds(10)

    public static func forChild(_ child: StackChild, canDrive: Bool) -> [SubThreadAction] {
        canDrive && child.canOverride ? [.open, .takeOver, .split, .drop] : [.open]
    }

    public static func forDrawerRow(childID: String, stack: ThreadStack?, fetchedAt: Date?, now: Date)
        -> DrawerChildMenu
    {
        guard let stack, let fetchedAt, now.timeIntervalSince(fetchedAt) < OverviewActions.seconds(freshFor) else {
            return .loading
        }
        guard let child = stack.children.first(where: { $0.id == childID }) else { return .ready([.open]) }
        return .ready(forChild(child, canDrive: stack.canDrive))
    }
}

public enum SubThreadRows {
    public static func make(_ stack: ThreadStack) -> [SubThreadRow] {
        stack.children.map { child in
            SubThreadRow(
                id: child.id,
                title: isBlank(child.title) ? child.name : child.title,
                kind: child.kind,
                state: child.state,
                detail: detail(child),
                filesChanged: child.filesChanged,
                pullRequestURL: secureURL(child.pullRequestUrl),
                actions: SubThreadActions.forChild(child, canDrive: stack.canDrive))
        }
    }

    private static func detail(_ child: StackChild) -> SubThreadDetail {
        if child.takenOver { return .takenOver }
        if child.messagesPaused { return .messagesPaused }
        if child.state == .done, let result = child.resultSummary, !isBlank(result) { return .result(result) }
        if child.state == .working || child.state == .waiting, let step = child.step, !isBlank(step) {
            return .step(step)
        }
        if child.queued { return .queued }
        return .state(child.state)
    }

    private static func isBlank(_ text: String) -> Bool {
        text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    private static func secureURL(_ text: String?) -> URL? {
        guard let text, let url = URL(string: text), url.scheme?.lowercased() == "https" else { return nil }
        return url
    }
}
