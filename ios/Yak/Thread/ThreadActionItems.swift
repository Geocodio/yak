import SwiftUI
import YakKit

/// The entries of a thread's menu, drawn from `ThreadActionMenu`, so the header's More menu and a drawer row's long
/// press show the same items in the same words and cannot disagree. Entries that need a view of their own (the
/// panes, "Open in new window") come from `custom`.
struct ThreadActionItems: View {
    let menu: ThreadActionMenu
    /// Prefix of the accessibility identifiers: "thread.menu" or "drawer.menu".
    let identifierPrefix: String
    let perform: (ThreadActionItem) -> Void
    var custom: (ThreadActionKind) -> AnyView? = { _ in nil }

    var body: some View {
        ForEach(Array(menu.groups.enumerated()), id: \.offset) { index, group in
            if index > 0 { Divider() }
            ForEach(group) { item in
                if let view = custom(item.kind) {
                    view
                } else {
                    entry(item)
                }
            }
        }
    }

    private func entry(_ item: ThreadActionItem) -> some View {
        Button(role: item.isDestructive ? .destructive : nil) {
            perform(item)
        } label: {
            Text(Self.title(of: item.kind))
            if let reason = item.disabledReason { Text(Self.reason(reason)) }
            if let icon = Self.icon(of: item.kind) { Image(systemName: icon) }
        }
        .disabled(!item.isEnabled)
        .accessibilityIdentifier(Self.identifier(of: item, prefix: identifierPrefix))
    }

    static func identifier(of item: ThreadActionItem, prefix: String) -> String {
        switch item.kind {
        case .work: "\(prefix).work.\(item.id)"
        case .cancelRequest: "\(prefix).work.cancel"
        case .addSubThread: "\(prefix).addSubThread"
        default: "\(prefix).\(item.id)"
        }
    }

    static func title(of kind: ThreadActionKind) -> LocalizedStringKey {
        switch kind {
        case .open: "overview.child.open"
        case .openInNewWindow: "row.openInNewWindow"
        case .star: "thread.menu.star"
        case .unstar: "thread.menu.unstar"
        case .work(let action): title(of: action)
        case .addSubThread: "thread.menu.addSubThread"
        case .share: "thread.menu.share"
        case .panes: "thread.menu"
        case .openOnWeb: "thread.openWeb"
        case .copyLink: "thread.menu.copyThreadLink"
        case .openSource(let name, _): "thread.menu.openSource \(name)"
        case .cancelRequest: "thread.menu.cancelRequest"
        case .close: "thread.menu.close"
        case .delete: "thread.menu.delete"
        }
    }

    static func title(of action: ThreadWorkAction) -> LocalizedStringKey {
        switch action {
        case .retry: "common.retry"
        case .setUpAndContinue: "thread.card.stopped.setUp"
        case .requestReview: "thread.menu.requestReview"
        case .retryRender: "thread.menu.retryRender"
        case .move(let repo): "thread.card.move \(repo)"
        default: "thread.menu"
        }
    }

    static func reason(_ reason: ThreadActionDisabledReason) -> LocalizedStringKey {
        switch reason {
        case .offline: "overview.notOffline"
        case .busy: "thread.menu.busy"
        }
    }

    static func icon(of kind: ThreadActionKind) -> String? {
        switch kind {
        case .open: "chevron.right"
        case .star: "star"
        case .unstar: "star.slash"
        case .addSubThread: "arrow.triangle.branch"
        case .share: "person.2"
        case .openOnWeb: "safari"
        case .copyLink: "link"
        case .openSource: "arrow.up.right.square"
        case .cancelRequest: "nosign"
        case .close: "xmark.circle"
        case .delete: "trash"
        default: nil
        }
    }
}
