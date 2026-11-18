import SwiftUI
import YakKit

/// The Chat pane. Until the chat itself arrives it shows the load states and an empty conversation area, and its
/// bar row is the composer.
extension PaneDescriptor {
    static let chat = PaneDescriptor(
        id: .chat, name: "pane.chat", symbol: "bubble.left",
        content: { thread in AnyView(ChatPane(thread: thread)) },
        barRow: { thread in AnyView(ComposerBar(thread: thread)) }, hasBadge: { $0.hasOpenQuestion })
}

private struct ChatPane: View {
    let thread: ThreadModel
    @Environment(SceneModel.self) private var scene
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass
    @State private var paneHeight: CGFloat = 600
    @State private var browserURL: BrowserLink?

    var body: some View {
        VStack(spacing: 0) {
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
            if case .conversation = thread.loadPresentation {
                if thread.composer.bar.line != nil {
                    Text("composer.legacy.line").font(.footnote.weight(.medium)).foregroundStyle(.primary)
                        .fixedSize(horizontal: false, vertical: true)
                        .padding(.vertical, WorkStyle.notePaddingVertical)
                        .padding(.horizontal, WorkStyle.notePaddingHorizontal)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Tone.neutral.surface, in: .rect(cornerRadius: WorkStyle.noteRadius))
                        .padding(.horizontal, WorkStyle.cardSideMargin).padding(.bottom, WorkStyle.cardSideMargin)
                        .accessibilityIdentifier("composer.legacyLine")
                }
                ThreadCardHost(thread: thread, paneHeight: paneHeight, openWeb: { browserURL = BrowserLink(url: $0) })
            }
        }
        .sensoryFeedback(.success, trigger: thread.question.sentCount)
        .sensoryFeedback(.error, trigger: thread.question.failureCount)
        .onGeometryChange(for: CGFloat.self) {
            $0.size.height
        } action: {
            paneHeight = $0
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemBackground))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("conversation")
        .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
    }

    private func openThread(_ id: String) {
        scene.open(thread: id, isCompactWidth: horizontalSizeClass == .compact)
    }
}
