import Foundation

/// What one entry of a thread's menu does. The header menu and a drawer row's long press are built from the same
/// kinds, so they cannot disagree about who may do what.
public enum ThreadActionKind: Equatable, Sendable {
    case open, openInNewWindow
    case star, unstar
    /// Retry, request review, move and the other work actions of `ThreadMenu`.
    case work(ThreadWorkAction)
    case addSubThread, share, panes, openOnWeb, copyLink
    /// Opens the page the thread started from (a Slack message, a Linear issue). `name` is the source's label.
    case openSource(name: String, url: URL)
    case cancelRequest, close, delete
}

public enum ThreadActionDisabledReason: Equatable, Sendable {
    /// Needs the server, and the device is offline.
    case offline
    /// A request for this thread is running (a delete above all), so nothing else starts on it.
    case busy
}

public struct ThreadActionItem: Equatable, Sendable, Identifiable {
    /// A stable word for tests and accessibility identifiers: "star", "close", "delete", a work action's own id.
    public let id: String
    public let kind: ThreadActionKind
    /// Shown in the destructive color. Only Cancel request and Delete are.
    public let isDestructive: Bool
    public let disabledReason: ThreadActionDisabledReason?

    public var isEnabled: Bool { disabledReason == nil }
}

/// The facts about one thread and one person that decide the menu. Build it with `header` or `row`.
public struct ThreadActionSubject: Equatable, Sendable {
    public enum Surface: Equatable, Sendable { case header, row }

    /// The page the thread started from.
    public struct Source: Equatable, Sendable {
        public let name: String
        public let url: URL
    }

    public var surface: Surface
    public var isStarred: Bool
    public var isClosed: Bool
    public var isSubThread: Bool
    /// Star is offered: a root thread whose page or row is known. A sub-thread is never starred from the app.
    public var canStar: Bool
    /// The person may drive the thread: close needs it.
    public var canDrive: Bool
    /// Delete needs it: the person may drive a root thread.
    public var canDelete: Bool
    /// The work actions the server allows, the red Cancel request among them.
    public var workItems: [ThreadMenuItem] = []
    public var canAddSubThread = false
    public var canShare = false
    public var source: Source?
    public var isOffline = false
    /// A request for this thread is running.
    public var isBusy = false

    /// The thread's own page. Before the page has loaded the menu offers only what needs no page: the panes, the web
    /// page and the link.
    public static func header(
        show: ThreadShow?, isStarred: Bool? = nil, isForbidden: Bool = false, isOffline: Bool, isBusy: Bool = false
    ) -> ThreadActionSubject {
        guard let show else {
            return ThreadActionSubject(
                surface: .header, isStarred: false, isClosed: false, isSubThread: false, canStar: false,
                canDrive: false, canDelete: false, isOffline: isOffline, isBusy: isBusy)
        }
        let canDrive = show.canDrive && !isForbidden
        let isSubThread = show.thread.parentThreadId != nil
        var subject = ThreadActionSubject(
            surface: .header, isStarred: isStarred ?? show.thread.isStarred, isClosed: show.thread.status == .closed,
            isSubThread: isSubThread, canStar: !isSubThread, canDrive: canDrive,
            canDelete: canDrive && !isSubThread)
        subject.workItems = ThreadMenu.items(show: show, isForbidden: isForbidden)
        subject.canAddSubThread = canDrive
        subject.canShare = show.thread.isOwner
        subject.isOffline = isOffline
        subject.isBusy = isBusy
        return subject
    }

    /// A drawer row. A search result that is not a loaded row carries no star and no right to drive, so it offers
    /// neither. The list says nothing about driving, so a root row's `canDelete` (the person may drive it) stands
    /// for it, and a sub-thread row offers neither Close nor Delete: its own menu keeps the sub-thread actions.
    public static func row(_ row: DrawerRow, isOffline: Bool, isBusy: Bool = false) -> ThreadActionSubject {
        let isSubThread = row.depth > 0
        var subject = ThreadActionSubject(
            surface: .row, isStarred: row.isStarred, isClosed: row.indicator == .closed, isSubThread: isSubThread,
            canStar: !isSubThread && !row.isUnloadedSearchResult, canDrive: !isSubThread && row.canDelete,
            canDelete: !isSubThread && row.canDelete)
        if let url = row.sourceLink, let name = row.source { subject.source = Source(name: name, url: url) }
        subject.isOffline = isOffline
        subject.isBusy = isBusy
        return subject
    }
}

/// The items of a thread's menu, in order, in groups the view separates. Nothing here knows SwiftUI.
public struct ThreadActionMenu: Equatable, Sendable {
    public let groups: [[ThreadActionItem]]

    public init(groups: [[ThreadActionItem]]) { self.groups = groups }

    public var items: [ThreadActionItem] { groups.flatMap { $0 } }

    public func item(_ kind: ThreadActionKind) -> ThreadActionItem? { items.first { $0.kind == kind } }

    /// Header, as board D1 draws it: Star (not on a sub-thread), Share and Add a sub-thread; then the work actions (Request
    /// review, Retry walkthrough render and the rest); then the panes, the web page and the link; then Cancel request,
    /// Close and Delete. Row: Open, new window, Star, link and the
    /// source's page, then Close and Delete. Star needs only reading; Close needs driving; Delete needs a drivable root.
    public static func make(_ subject: ThreadActionSubject) -> ThreadActionMenu {
        func item(
            _ kind: ThreadActionKind, _ id: String, destructive: Bool = false, needsServer: Bool = false
        ) -> ThreadActionItem {
            let reason: ThreadActionDisabledReason? =
                needsServer ? (subject.isBusy ? .busy : (subject.isOffline ? .offline : nil)) : nil
            return ThreadActionItem(id: id, kind: kind, isDestructive: destructive, disabledReason: reason)
        }
        let star =
            !subject.canStar
            ? [] : [item(subject.isStarred ? .unstar : .star, subject.isStarred ? "unstar" : "star", needsServer: true)]
        let ends: [ThreadActionItem] =
            (subject.canDrive && !subject.isClosed ? [item(.close, "close", needsServer: true)] : [])
            + (subject.canDelete ? [item(.delete, "delete", destructive: true, needsServer: true)] : [])
        switch subject.surface {
        case .header:
            let work = subject.workItems.filter { !$0.isDestructive }.map {
                item(.work($0.action), $0.id, needsServer: true)
            }
            let cancel = subject.workItems.contains(where: \.isDestructive)
            let people =
                star + (subject.canShare ? [item(.share, "share")] : [])
                + (subject.canAddSubThread ? [item(.addSubThread, "add-sub-thread")] : [])
            let around = [item(.panes, "panes"), item(.openOnWeb, "open-web"), item(.copyLink, "copy-link")]
            let last = (cancel ? [item(.cancelRequest, "cancel", destructive: true, needsServer: true)] : []) + ends
            return ThreadActionMenu(groups: [people, work, around, last].filter { !$0.isEmpty })
        case .row:
            let source = subject.source.map { [item(.openSource(name: $0.name, url: $0.url), "open-source")] } ?? []
            let first =
                [item(.open, "open"), item(.openInNewWindow, "new-window")] + star + [item(.copyLink, "copy-link")]
                + source + ends
            return ThreadActionMenu(groups: [first])
        }
    }
}
