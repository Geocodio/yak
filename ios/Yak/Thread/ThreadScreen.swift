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
    @State private var isAddSubThreadOpen = false
    @State private var isShareOpen = false
    @State private var slots = PaneSlots()
    /// The pane the title returns to when it leaves the Overview.
    @State private var paneBeforeOverview: PaneID = .chat
    /// The width of the thread screen, which decides between the Overview pane and the side panel.
    @State private var availableWidth: Double = 0
    @Environment(\.scenePhase) private var scenePhase
    @Environment(SystemFeatures.self) private var system
    @Environment(\.verticalSizeClass) private var verticalSizeClass
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private var overviewPlacement: OverviewPlacement {
        OverviewPlacement.choose(isCompactWidth: isCompactWidth, availableWidth: availableWidth)
    }

    private var isSidePanel: Bool {
        if case .sidePanel = overviewPlacement { true } else { false }
    }

    private var isVisible: Bool { scenePhase == .active && !isCoveredByDrawer }
    /// Plain http appears only against the stub of test mode.
    private static var allowsInsecure: Bool {
        #if DEBUG
            TestMode.current() != nil
        #else
            false
        #endif
    }

    private var handoffURL: URL? {
        HandoffLink.webURL(instance: instanceURL, threadID: threadID, allowsInsecure: Self.allowsInsecure)
    }

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
        .threadHandoff(
            webURL: handoffURL,
            isActive: ThreadHandoff.isActive(
                webURL: handoffURL, isVisible: isVisible, isLocked: system.appLock.isLocked)
        )
        .onGeometryChange(for: Double.self) {
            Double($0.size.width)
        } action: {
            availableWidth = $0
        }
        .onChange(of: isSidePanel) { moveOverviewBetweenPaneAndPanel() }
        .task(id: Opening(threadID: threadID, isSessionActive: isSessionActive)) { await run() }
        .onChange(of: isVisible) { Task { await model?.setVisible(isVisible) } }
        // A `/clear` starts a new thread; the window follows it.
        .onChange(of: model?.replacementThreadID) { _, replacement in
            if let replacement { scene.open(thread: replacement, isCompactWidth: isCompactWidth) }
        }
        .onChange(of: scenePhase) { if scenePhase != .active { Task { await model?.flushDraft() } } }
    }

    /// Keeps one model per thread while the screen shows it, and stops it when the thread changes or the screen goes.
    private func run() async {
        // Text typed in the last moments before the model is replaced is saved first, then read back by the next one.
        await model?.flushDraft()
        // A change of `isSessionActive` runs this again for the same thread: the composer, and any send in flight in
        // it, stays the only one for that thread.
        let sameThreadComposer = model?.threadID == threadID ? model?.composer : nil
        let next = ThreadModel(
            threadID: threadID, session: session, composer: sameThreadComposer,
            onVisibilityChange: { [push, instanceURL, threadID] isVisible in
                push?.setVisible(thread: threadID, instance: instanceURL, isVisible: isVisible)
            },
            onSnapshot: { [push, instanceURL, threadID] snapshot in
                // The start may wait on the network, so the snapshot loop does not wait for it.
                Task { await push?.observe(thread: threadID, instanceURL: instanceURL, snapshot: snapshot) }
            })
        model = next
        // Drafts are local files, so the field shows the saved text before the session is active.
        await next.composer.load()
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
        let activity = activityLine(thread, focused: focused.wrappedValue)
        return NavigationStack {
            HStack(spacing: 0) {
                // The bar sits below the pane, not over it, on the page color that runs to the bottom of the
                // screen: no row scrolls behind the glass or below it, where it would be hidden yet still reachable.
                VStack(spacing: 0) {
                    if placement.hidesTitle, let line = thread.connectionLine { ConnectionBanner(line: line) }
                    PaneHost(thread: thread, focused: focused.wrappedValue).collectingPaneSlots(into: slots)
                    VStack(spacing: 6) {
                        if let line = activity {
                            ActivityStrip(line: line) { openChat(focusComposer: $0) }
                        }
                        BottomBar(thread: thread, slots: slots, placement: placement, focused: focused)
                    }
                    .animation(.easeInOut(duration: 0.2), value: activity)
                }
                if case .sidePanel(let width) = overviewPlacement, scene.state.isOverviewPanelOpen,
                    thread.panes.contains(.overview)
                {
                    OverviewSidePanel(thread: thread, width: width) { scene.setOverviewPanelOpen(false) }
                        .transition(.move(edge: .trailing).combined(with: .opacity))
                }
                if placement == .trailingStrip { trailingStrip(thread, focused: focused) }
            }
            .background(Color(.systemBackground))
            .environment(\.openAddSubThread, { isAddSubThreadOpen = true })
            .animation(reduceMotion ? .easeInOut(duration: 0.2) : .smooth(duration: 0.3), value: isPanelShown)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar(placement.hidesTitle ? .hidden : .automatic, for: .navigationBar)
            .toolbarBackgroundVisibility(.visible, for: .navigationBar)
            .toolbarBackground(Color(.systemBackground), for: .navigationBar)
            .toolbar {
                if !placement.hidesTitle {
                    if isCompactWidth {
                        ToolbarItem(placement: .topBarLeading) { threadsButton }
                    }
                    ToolbarItem(placement: .principal) {
                        ThreadTitle(
                            title: thread.title, dot: thread.statusDot, connectionLine: thread.connectionLine,
                            action: thread.panes.contains(.overview) ? { toggleOverview(focused) } : nil)
                    }
                    ToolbarItem(placement: .topBarTrailing) { threadMenu(thread, shownPane: focused.wrappedValue) }
                }
                if #available(iOS 27.1, *), placement.declaresBottomToolbarItems {
                    PaneStripToolbar(panes: thread.panes, focused: focused, thread: thread)
                }
            }
            .sheet(isPresented: $isAddSubThreadOpen) {
                AddSubThreadSheet(session: session, parentID: threadID) {
                    scene.open(thread: $0, isCompactWidth: isCompactWidth)
                }
            }
            .sheet(isPresented: $isShareOpen) {
                ShareSheet(session: session, threadID: threadID) { await model?.engine?.refreshShow() }
            }
            .overlay(alignment: .top) { copiedToast }
            .background { shortcuts(focused, panes: thread.panes) }
        }
        .environment(scene)
        .sensoryFeedback(.success, trigger: copiedLink)
    }

    /// The title and Command I open the Overview; from the Overview they return to the pane before it.
    private func toggleOverview(_ focused: Binding<PaneID>) {
        if isSidePanel {
            scene.setOverviewPanelOpen(!scene.state.isOverviewPanelOpen)
        } else if focused.wrappedValue == .overview {
            focused.wrappedValue = paneBeforeOverview
        } else {
            paneBeforeOverview = focused.wrappedValue
            focused.wrappedValue = .overview
        }
    }

    /// With the side panel the Overview is never the focused pane: choosing it opens or closes the panel.
    private func focusedBinding(_ thread: ThreadModel) -> Binding<PaneID> {
        Binding(
            get: {
                let shown = scene.shownPane(among: thread.panes)
                return isSidePanel && shown == .overview ? paneBeforeOverview : shown
            },
            set: { next in
                if isSidePanel, next == .overview {
                    scene.setOverviewPanelOpen(!scene.state.isOverviewPanelOpen)
                } else {
                    scene.focus(pane: next)
                }
            })
    }

    private var isPanelShown: Bool { isSidePanel && scene.state.isOverviewPanelOpen }

    /// A window that grows or shrinks across the panel's width turns the open Overview into the other form.
    private func moveOverviewBetweenPaneAndPanel() {
        if isSidePanel {
            guard scene.isShown(.overview) else { return }
            scene.focus(pane: paneBeforeOverview == .overview ? .chat : paneBeforeOverview)
            scene.setOverviewPanelOpen(true)
        } else if scene.state.isOverviewPanelOpen {
            scene.setOverviewPanelOpen(false)
            scene.focus(pane: .overview)
        }
    }

    /// The app's own strip on the trailing edge: the threads button, the pane button and the menu, top to bottom.
    private func trailingStrip(_ thread: ThreadModel, focused: Binding<PaneID>) -> some View {
        VStack(spacing: 8) {
            if isCompactWidth { threadsButton.background(Color.primary.opacity(0.08), in: .circle) }
            PaneMenuButton(panes: thread.panes, focused: focused)
            PaneStripRow(slots: slots, focused: focused.wrappedValue)
            Spacer(minLength: 0)
            if let line = activityLine(thread, focused: focused.wrappedValue) {
                ActivityStripButton(line: line) { openChat(focusComposer: $0) }
            }
            threadMenu(thread, shownPane: focused.wrappedValue).background(Color.primary.opacity(0.08), in: .circle)
        }
        .tint(.primary)
        .padding(.vertical, 8).padding(.horizontal, 6)
        .frame(maxHeight: .infinity)
        .background { BarSurface() }
        .padding(.vertical, 8).padding(.trailing, 8)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("thread.strip")
    }

    /// The line above the bar in every pane but Chat.
    private func activityLine(_ thread: ThreadModel, focused: PaneID) -> ActivityLine? {
        focused == .chat ? nil : ActivityLine.make(thread.snapshot)
    }

    /// Shows Chat, and puts the cursor in the composer when asked.
    private func openChat(focusComposer: Bool) {
        scene.focus(pane: .chat)
        if focusComposer { scene.requestComposerFocus() }
    }

    // MARK: Controls

    private var threadsButton: some View {
        ThreadsButton(needsYouCount: needsYouCount) { scene.setDrawerOpen(true) }
    }

    private func threadMenu(_ thread: ThreadModel, shownPane: PaneID) -> some View {
        Menu {
            if thread.snapshot?.show?.canDrive == true {
                Button {
                    isAddSubThreadOpen = true
                } label: {
                    Label("thread.menu.addSubThread", systemImage: "arrow.triangle.branch")
                }
                .accessibilityIdentifier("thread.menu.addSubThread")
            }
            if thread.snapshot?.show?.thread.isOwner == true {
                Button {
                    isShareOpen = true
                } label: {
                    Label("thread.menu.share", systemImage: "person.2")
                }
                .accessibilityIdentifier("thread.menu.share")
            }
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

    /// Command 1 focuses Chat, Command 2 Preview when the thread has one; Command I and Command 5 open the Overview.
    private func shortcuts(_ focused: Binding<PaneID>, panes: [PaneID]) -> some View {
        ZStack {
            Button("pane.chat") { focused.wrappedValue = .chat }.yakShortcut(.focusChat)
            if panes.contains(.preview) {
                Button("pane.preview") { focused.wrappedValue = .preview }.yakShortcut(.focusPreview)
            }
            if panes.contains(.overview) {
                Button("pane.overview") { toggleOverview(focused) }.yakShortcut(.overview)
                Button("pane.overview") {
                    if focused.wrappedValue != .overview { paneBeforeOverview = focused.wrappedValue }
                    focused.wrappedValue = .overview
                }.yakShortcut(.focusOverview)
            }
        }
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
