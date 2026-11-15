import SwiftUI
import YakKit

/// The Chat pane. Until the chat itself arrives it shows the load states and an empty conversation area, and its
/// bar row is a placeholder for the composer.
extension PaneDescriptor {
    static let chat = PaneDescriptor(
        id: .chat, name: "pane.chat", symbol: "bubble.left",
        content: { thread in AnyView(ChatPane(thread: thread)) },
        barRow: { _ in AnyView(ComposerPlaceholder()) })
}

private struct ChatPane: View {
    let thread: ThreadModel
    @Environment(SceneModel.self) private var scene

    var body: some View {
        ZStack {
            ThreadLoadView(
                presentation: thread.loadPresentation, retry: { Task { await thread.retry() } },
                openThreads: { scene.setDrawerOpen(true) })
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemBackground))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("conversation")
    }
}

/// A stand-in for the message field: it keeps the bar's height and place until the composer replaces it.
private struct ComposerPlaceholder: View {
    var body: some View {
        Text("chat.composerPlaceholder").foregroundStyle(Color.yakMuted)
            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading).padding(.horizontal, 12)
            .background(Color.primary.opacity(0.06), in: .capsule)
            .accessibilityIdentifier("composer.placeholder")
    }
}
