import SwiftUI
import UIKit
import YakKit

/// The thread screen: the top row, the focused pane and the bottom bar, in the placement the size classes and the
/// system's vertical strip choose. It opens the thread's engine while it is on screen and closes it when it goes.
struct ThreadScreen: View {
    let session: InstanceSession
    let scene: SceneModel
    let threadID: String
    /// The title of the thread's row in the drawer, shown until the thread's own arrives.
    let initialTitle: String?
    let instanceURL: URL
    let needsYouCount: Int
    let isSessionActive: Bool
    /// The drawer covers the thread on compact width; the thread then does not count as seen.
    let isCoveredByDrawer: Bool
    let isCompactWidth: Bool
    let openWeb: (URL) -> Void
    /// Push stays quiet for the thread while it shows.
    var push: PushController?

    @State private var model: ThreadModel?
    @State private var copiedLink = 0
    @State private var slots = PaneSlots()
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.verticalSizeClass) private var verticalSizeClass

    private var isVisible: Bool { scenePhase == .active && !isCoveredByDrawer }
    private var webURL: URL { instanceURL.appending(path: "threads").appending(path: threadID) }

    private struct Opening: Hashable {
        let threadID: String
        let isSessionActive: Bool
    }

    var body: some View {
        ToolbarStripReader { hasStrip in
            let placement = PaneControlPlacement.choose(
                isCompactWidth: isCompactWidth, isCompactHeight: verticalSizeClass == .compact,
                hasVerticalToolbarEdge: hasStrip)
            Group {
                if let model {
                    content(model, placement: placement)
                } else {
                    Color(.systemBackground)
                }
            }
        }
        .task(id: Opening(threadID: threadID, isSessionActive: isSessionActive)) { await run() }
        .onChange(of: isVisible) { Task { await model?.setVisible(isVisible) } }
    }

    /// Keeps one model per thread while the screen shows it, and stops it when the thread changes or the screen goes.
    private func run() async {
        let next = ThreadModel(
            threadID: threadID, session: session,
            onVisibilityChange: { [push, instanceURL, threadID] isVisible in
                push?.setVisible(thread: threadID, instance: instanceURL, isVisible: isVisible)
            },
            onSnapshot: { [push, instanceURL, threadID] snapshot in
                // The start may wait on the network, so the snapshot loop does not wait for it.
                Task { await push?.observe(thread: threadID, instanceURL: instanceURL, snapshot: snapshot) }
            })
        model = next
        guard isSessionActive else { return }
        await next.start(initialTitle: initialTitle)
        await next.setVisible(isVisible)
        // Waits until the task is cancelled, which is how the screen leaves or changes thread.
        while !Task.isCancelled { try? await Task.sleep(for: .seconds(3600)) }
        // The stop runs in a task of its own, so the cancellation of this one cannot cut it short.
        await Task { await next.stop() }.value
    }

    // MARK: Layout

    private func content(_ thread: ThreadModel, placement: PaneControlPlacement) -> some View {
        let focused = focusedBinding(thread)
        return NavigationStack {
            HStack(spacing: 0) {
                VStack(spacing: 0) {
                    if placement.hidesTitle, let line = thread.connectionLine { ConnectionBanner(line: line) }
                    PaneHost(thread: thread, focused: focused.wrappedValue).collectingPaneSlots(into: slots)
                }
                .safeAreaInset(edge: .bottom, spacing: 0) {
                    BottomBar(thread: thread, slots: slots, placement: placement, focused: focused)
                }
                if placement == .trailingStrip { trailingStrip(thread, focused: focused) }
            }
            .background(Color(.systemBackground))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar(placement.hidesTitle ? .hidden : .automatic, for: .navigationBar)
            .toolbar {
                if !placement.hidesTitle {
                    if isCompactWidth {
                        ToolbarItem(placement: .topBarLeading) { threadsButton }
                    }
                    ToolbarItem(placement: .principal) {
                        ThreadTitle(title: thread.title, dot: thread.statusDot, connectionLine: thread.connectionLine)
                    }
                    ToolbarItem(placement: .topBarTrailing) { threadMenu(focused.wrappedValue) }
                }
                if #available(iOS 27.1, *), placement.declaresBottomToolbarItems {
                    PaneStripToolbar(panes: thread.panes, focused: focused, thread: thread)
                }
            }
            .overlay(alignment: .top) { copiedToast }
            .background { shortcuts(focused) }
        }
        .environment(scene)
        .sensoryFeedback(.success, trigger: copiedLink)
    }

    private func focusedBinding(_ thread: ThreadModel) -> Binding<PaneID> {
        Binding(
            get: { scene.shownPane(among: thread.panes) },
            set: { scene.focus(pane: $0) })
    }

    /// The app's own strip on the trailing edge: the threads button, the pane button and the menu, top to bottom.
    private func trailingStrip(_ thread: ThreadModel, focused: Binding<PaneID>) -> some View {
        VStack(spacing: 8) {
            if isCompactWidth { threadsButton.background(Color.primary.opacity(0.08), in: .circle) }
            PaneMenuButton(panes: thread.panes, focused: focused)
            Spacer(minLength: 0)
            threadMenu(focused.wrappedValue).background(Color.primary.opacity(0.08), in: .circle)
        }
        .tint(.primary)
        .padding(.vertical, 8).padding(.horizontal, 6)
        .frame(maxHeight: .infinity)
        .background { BarSurface() }
        .padding(.vertical, 8).padding(.trailing, 8)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("thread.strip")
    }

    // MARK: Controls

    private var threadsButton: some View {
        Button {
            scene.setDrawerOpen(true)
        } label: {
            Image(systemName: "sidebar.leading")
                .frame(minWidth: 44, minHeight: 44)
                .overlay(alignment: .topTrailing) {
                    if needsYouCount > 0 {
                        Text(needsYouCount, format: .number).font(.caption2.weight(.bold)).foregroundStyle(.white)
                            .padding(.horizontal, 5).frame(minWidth: 16, minHeight: 16)
                            .background(Color.red, in: .capsule).offset(x: -2, y: 4)
                            .accessibilityHidden(true)
                    }
                }
                .contentShape(.rect)
        }
        .accessibilityLabel(
            needsYouCount > 0 ? Text("threads.button.needsYou \(needsYouCount)") : Text("threads.button")
        )
        .accessibilityIdentifier("threads.button")
    }

    private func threadMenu(_ shownPane: PaneID) -> some View {
        Menu {
            PaneMenuItems(slots: slots, focused: shownPane)
            Button {
                openWeb(webURL)
            } label: {
                Label("thread.openWeb", systemImage: "safari")
            }
            Button {
                UIPasteboard.general.url = webURL
                copiedLink += 1
            } label: {
                Label("thread.copyLink", systemImage: "link")
            }
        } label: {
            Image(systemName: "ellipsis").frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .accessibilityLabel("thread.menu")
        .accessibilityIdentifier("thread.menu")
    }

    @ViewBuilder private var copiedToast: some View {
        if copiedLink > 0 {
            CopiedToast().id(copiedLink).padding(.top, 8)
        }
    }

    /// Command 1 focuses Chat.
    private func shortcuts(_ focused: Binding<PaneID>) -> some View {
        Button("pane.chat") { focused.wrappedValue = .chat }.yakShortcut(.focusChat)
            .frame(width: 0, height: 0).opacity(0).accessibilityHidden(true)
    }
}

/// A brief confirmation that the link is on the clipboard. It leaves by itself.
private struct CopiedToast: View {
    @State private var isShown = true

    var body: some View {
        Group {
            if isShown {
                Text("thread.linkCopied").font(.subheadline.weight(.medium))
                    .padding(.horizontal, 14).padding(.vertical, 8)
                    .background(.regularMaterial, in: .capsule)
                    .transition(.opacity.combined(with: .move(edge: .top)))
                    .accessibilityIdentifier("thread.linkCopied")
            }
        }
        .task {
            UIAccessibility.post(notification: .announcement, argument: String(localized: "thread.linkCopied"))
            do { try await Task.sleep(for: .seconds(1.6)) } catch { return }
            withAnimation { isShown = false }
        }
    }
}

/// The connection line for the placement that hides the title row, which would otherwise carry it.
private struct ConnectionBanner: View {
    let line: ConnectionLine

    var body: some View {
        Text(line.text).font(.caption.weight(.medium)).foregroundStyle(line.color).lineLimit(1)
            .frame(maxWidth: .infinity).padding(.vertical, 4)
            .accessibilityIdentifier("thread.connection")
    }
}
