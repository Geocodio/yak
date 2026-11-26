import Foundation

public enum SubThreadAction: Equatable, Sendable { case open, takeOver, split, drop }

public enum SubThreadDetail: Equatable, Sendable {
    /// What a working sub-thread is doing, as the server words it.
    case step(String)
    /// The result of a finished sub-thread.
    case result(String)
    /// Why a sub-thread waits for a free spot; `running` is how many sub-threads run now.
    case waitingForSpot(running: Int)
}

public struct SubThreadRow: Equatable, Sendable, Identifiable {
    public let id: String
    public let name: String
    public let kind: StackChildKind
    public let repo: String?
    public let style: SubThreadStateStyle
    public let messagesPaused: Bool
    public let detail: SubThreadDetail?
    public let pullRequestURL: URL?
    public let actions: [SubThreadAction]
    /// The limit "Start now" would save: one more than now. Set only on a waiting sub-thread that the person may
    /// start.
    public let startNowLimit: Int?

    public init(
        id: String, name: String, kind: StackChildKind, repo: String?, style: SubThreadStateStyle,
        messagesPaused: Bool, detail: SubThreadDetail?, pullRequestURL: URL?, actions: [SubThreadAction],
        startNowLimit: Int? = nil
    ) {
        self.id = id
        self.name = name
        self.kind = kind
        self.repo = repo
        self.style = style
        self.messagesPaused = messagesPaused
        self.detail = detail
        self.pullRequestURL = pullRequestURL
        self.actions = actions
        self.startNowLimit = startNowLimit
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
        let startNowLimit = canRaiseLimit(stack) ? stack.limit.current + 1 : nil
        return stack.children.map { stored in
            let child = stored.shown
            return SubThreadRow(
                id: child.id,
                name: isBlank(child.name) ? child.title : child.name,
                kind: child.kind,
                repo: isBlank(child.repo) ? nil : child.repo,
                style: .of(child.state, takenOver: child.takenOver),
                messagesPaused: child.messagesPaused,
                detail: detail(child, running: stack.limit.awake),
                pullRequestURL: secureURL(child.pullRequestUrl),
                actions: SubThreadActions.forChild(child, canDrive: stack.canDrive),
                startNowLimit: child.state == .queued ? startNowLimit : nil)
        }
    }

    /// The web offers "Start now" to a person who can drive the root thread, below the highest limit.
    static func canRaiseLimit(_ stack: ThreadStack) -> Bool {
        stack.canDrive && stack.isRoot && stack.limit.current < stack.limit.max
    }

    private static func detail(_ child: StackChild, running: Int) -> SubThreadDetail? {
        switch child.state {
        case .queued: .waitingForSpot(running: running)
        case .working:
            child.step.flatMap { isBlank($0) ? nil : .step($0) }
        case .done:
            child.resultSummary.flatMap { isBlank($0) ? nil : .result($0) }
        default: nil
        }
    }

    private static func isBlank(_ text: String?) -> Bool {
        text?.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ?? true
    }

    private static func secureURL(_ text: String?) -> URL? {
        guard let text, let url = URL(string: text), url.scheme?.lowercased() == "https" else { return nil }
        return url
    }
}

extension StackChild {
    /// The child as the web shows it: the server never reports a research sub-thread as waiting for a free spot (it
    /// shares the thread's sandbox), so a stored `queued` research sub-thread reads as starting.
    fileprivate var shown: StackChild {
        guard state == .queued, kind == .research else { return self }
        return StackChild(
            id: id, name: name, title: title, kind: kind, model: model, state: .starting, step: step,
            resultSummary: resultSummary, branch: branch, repo: repo, pullRequestUrl: pullRequestUrl,
            ciStatus: ciStatus, filesChanged: filesChanged, queued: queued, messagesPaused: messagesPaused,
            takenOver: takenOver, canOverride: canOverride)
    }
}
