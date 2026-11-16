import SwiftUI
import YakKit

/// The Chat pane: the conversation once the thread has one to show, the load states before, and a placeholder for
/// the composer in the bar.
extension PaneDescriptor {
    static let chat = PaneDescriptor(
        id: .chat, name: "pane.chat", symbol: "bubble.left",
        content: { thread in AnyView(ChatPane(thread: thread)) },
        barRow: { _ in AnyView(ComposerPlaceholder()) }, hasBadge: { $0.hasOpenQuestion })
}

private struct ChatPane: View {
    let thread: ThreadModel
    @Environment(SceneModel.self) private var scene
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass

    var body: some View {
        ZStack {
            if case .conversation = thread.loadPresentation {
                ChatView(thread: thread, openThread: openThread)
            }
            ThreadLoadView(
                presentation: thread.loadPresentation, retry: { Task { await thread.retry() } },
                openThreads: {
                    // A wide window already lists the threads, so the open thread is left to show the list's choice.
                    if horizontalSizeClass != .compact { scene.closeThread() }
                    scene.setDrawerOpen(true)
                })
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemBackground))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("conversation")
    }

    private func openThread(_ id: String) {
        scene.open(thread: id, isCompactWidth: horizontalSizeClass == .compact)
    }
}

/// A stand-in for the message field: it keeps the bar's height and place until the composer replaces it.
private struct ComposerPlaceholder: View {
    var body: some View {
        Text("chat.composerPlaceholder").foregroundStyle(Color.yakMuted)
            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading).padding(.horizontal, 12)
            .background(Color(.secondarySystemBackground), in: .capsule)
            .accessibilityIdentifier("composer.placeholder")
    }
}
