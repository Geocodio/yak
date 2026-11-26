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
    /// Every sub-thread below the thread, for the words of the close and delete questions; 0 when the list does not
    /// hold the thread.
    let subThreadCount: Int
    let actions: ThreadActionsModel
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
    @State private var isThreadSheetOpen = false
    @State private var isConfirmingCancel = false
    @Environment(\.scenePhase) private var scenePhase
    @Environment(SystemFeatures.self) private var system
    @Environment(\.verticalSizeClass) private var verticalSizeClass
    @Environment(\.webConversation) private var web

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
                    content(model, placement: placement, hasStrip: hasStrip)
                } else {
                    Color.yakBackground
                }
            }
        }
        .threadHandoff(
            webURL: handoffURL,
            isActive: ThreadHandoff.isActive(
                webURL: handoffURL, isVisible: isVisible, isLocked: system.appLock.isLocked)
        )
        .task(id: Opening(threadID: threadID, isSessionActive: isSessionActive)) { await run() }
        .onChange(of: isVisible) { Task { await model?.setVisible(isVisible) } }
        .onChange(of: isAddSubThreadOpen || isShareOpen) { _, isOpen in scene.isThreadSheetOpen = isOpen }
        .onDisappear { scene.isThreadSheetOpen = false }
        // A `/clear` starts a new thread; the window follows it.
        .onChange(of: model?.replacementThreadID) { _, replacement in
            if let replacement, let sentFrom = model?.threadID {
                scene.openReplacement(replacement, sentFrom: sentFrom, isCompactWidth: isCompactWidth)
            }
        }
        .onChange(of: scenePhase) { if scenePhase != .active { Task { await model?.flushDraft() } } }
        // The thread was deleted on another device: the page and the engine say 404. The window leaves it.
        .onChange(of: model?.isGone) { _, isGone in if isGone == true { actions.threadIsGone(threadID) } }
        // A close or a star changed the thread's page; it is read again, and then the person's own star is dropped.
        .onChange(of: actions.version(of: threadID)) {
            Task {
                await model?.engine?.refreshShow()
                actions.clearStarOverride(threadID)
            }
        }
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

    private func content(_ thread: ThreadModel, placement: PaneControlPlacement, hasStrip: Bool) -> some View {
        let focused = focusedBinding(thread)
        return NavigationStack {
            VStack(spacing: 0) {
                // A rule under the bar, which is flat on the page color.
                if !placement.hidesTitle {
                    Rectangle().fill(Color.yakLine).frame(height: Design.Space.line)
                }
                HStack(spacing: 0) {
                    // The bar sits below the pane, not over it, on the page color that runs to the bottom of the
                    // screen: no row scrolls behind the glass or below it, where it would be hidden yet still
                    // reachable.
                    ThreadLayoutView(
                        thread: thread, scene: scene, slots: slots, singlePlacement: placement, hasStrip: hasStrip,
                        isCompactWidth: isCompactWidth,
                        activity: { activityLine(thread, focused: $0) }, openChat: { openChat(focusComposer: $0) }
                    )
                    .collectingPaneSlots(into: slots)
                    // The layout reaches the screen's bottom edge, so the bar sits its own distance from the edge on
                    // every device; the keyboard still lifts it.
                    .ignoresSafeArea(.container, edges: .bottom)
                    if placement == .trailingStrip { trailingStrip(thread, focused: focused) }
                }
            }
            .background(.yakPage)
            .environment(\.openAddSubThread, { isAddSubThreadOpen = true })
            .navigationBarTitleDisplayMode(.inline)
            .navigationTitle(WindowTitle.make(thread.snapshot?.show?.thread).text)
            .toolbar(placement.hidesTitle ? .hidden : .automatic, for: .navigationBar)
            .toolbarBackgroundVisibility(.visible, for: .navigationBar)
            .toolbarBackground(.yakPage, for: .navigationBar)
            .toolbar {
                if !placement.hidesTitle {
                    if isCompactWidth {
                        ToolbarItem(placement: .topBarLeading) { threadsButton }
                            .sharedBackgroundVisibility(.hidden)
                    }
                    ToolbarItem(placement: .principal) {
                        ThreadTitle(
                            title: thread.title, dot: thread.statusDot, meta: Self.meta(of: thread),
                            connectionLine: headerConnectionLine(thread, focused: focused.wrappedValue),
                            tone: thread.statusTone, statusLabel: thread.snapshot?.show?.request?.status.label,
                            action: thread.panes.contains(.overview) ? { scene.toggleOverview() } : nil,
                            isStarred: isStarred(thread), isClosed: thread.snapshot?.show?.thread.status == .closed
                        )
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .sharedBackgroundVisibility(.hidden)
                    ToolbarItem(placement: .topBarTrailing) { threadMenu(thread, shownPane: focused.wrappedValue) }
                        .sharedBackgroundVisibility(.hidden)
                }
                if #available(iOS 27.1, *), placement.declaresBottomToolbarItems {
                    PaneStripToolbar(
                        panes: thread.panes, focused: focused, thread: thread, marks: stripMarks,
                        priorities: stripPriorities(thread))
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
            .overlay(alignment: .top) {
                VStack(spacing: Design.Space.small) {
                    copiedToast
                    if let notice = actions.notice, notice.surface == .header {
                        ThreadActionBanner(notice: notice).id(notice.id)
                    }
                }
                .padding(.top, Design.Space.compact)
            }
        }
        .environment(scene)
        .threadActionDialogs(actions, surface: .header)
        .environment(\.hasSystemStrip, hasStrip)
        .sensoryFeedback(.success, trigger: copiedLink)
    }

    /// The connection problem the header names: all of them, except while the Chat pane shows its own saved copy or
    /// offline line.
    private func headerConnectionLine(_ thread: ThreadModel, focused: PaneID) -> ConnectionLine? {
        let slots = scene.plan?.slots ?? []
        let isChatVisible = focused == .chat || slots.contains { $0.content == .pane(.chat) }
        let showsOwnLine = web.map { ($0.savedCopy != nil && $0.savedCopyState != nil) || $0.showsOfflineLine } ?? false
        return HeaderConnection.line(
            thread.connectionLine, chatIsVisible: isChatVisible,
            chatShowsOwnLine: showsOwnLine)
    }

    /// The repository and branch under the title.
    private static func meta(of thread: ThreadModel) -> String? {
        let detail = thread.snapshot?.show?.thread
        let parts = [detail?.shownRepo, detail?.branch].compactMap { $0 }.filter { !$0.isEmpty }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }

    /// The strip's marks while two panes show.
    private var stripMarks: [PaneID: PaneMark] {
        guard let plan = scene.plan, plan.slots.count == 2 else { return [:] }
        return PaneLayoutRules.marks(state: scene.paneState, plan: plan)
    }

    private func stripPriorities(_ thread: ThreadModel) -> [PaneID: Int] {
        guard let plan = scene.plan else { return [:] }
        return PaneLayoutRules.stripPriorities(available: thread.panes, state: scene.paneState, plan: plan)
    }

    /// The focused pane, and a pick that goes through the layout rules.
    private func focusedBinding(_ thread: ThreadModel) -> Binding<PaneID> {
        Binding(
            get: { scene.shownFocusedPane(among: thread.panes) },
            set: { scene.select($0) })
    }

    /// The app's own strip on the trailing edge: the threads button, the pane button and the menu, top to bottom.
    private func trailingStrip(_ thread: ThreadModel, focused: Binding<PaneID>) -> some View {
        VStack(spacing: 8) {
            if isCompactWidth {
                threadsButton.background(Color.primary.opacity(0.08), in: .rect(cornerRadius: Design.Radius.control))
            }
            PaneMenuButton(panes: thread.panes, current: focused.wrappedValue, select: { scene.select($0) })
            PaneStripRow(slots: slots, focused: focused.wrappedValue)
            Spacer(minLength: 0)
            if let line = activityLine(thread, focused: focused.wrappedValue) {
                ActivityStripButton(line: line) { openChat(focusComposer: $0) }
            }
            threadMenu(thread, shownPane: focused.wrappedValue)
                .background(Color.primary.opacity(0.08), in: .rect(cornerRadius: Design.Radius.control))
        }
        .tint(.primary)
        .padding(.vertical, 8).padding(.horizontal, 6)
        .frame(maxHeight: .infinity)
        .background { BarSurface() }
        .padding(.vertical, 8).padding(.trailing, 8)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("thread.strip")
    }

    /// The line above the bar of every pane but Chat.
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
        ThreadsButton(needsYouCount: needsYouCount) { scene.setDrawerOpen(true) }.buttonStyle(.ghost)
    }

    /// The star the header shows: the person's own tap until the server has answered, the page's value after.
    private func isStarred(_ thread: ThreadModel) -> Bool {
        actions.isStarred(threadID, page: thread.snapshot?.show?.thread.isStarred ?? false)
    }

    private func threadMenu(_ thread: ThreadModel, shownPane: PaneID) -> some View {
        let show = thread.snapshot?.show
        let subject = ThreadActionSubject.header(
            show: show, isStarred: show == nil ? nil : isStarred(thread), isOffline: thread.isOffline,
            isBusy: actions.isBusy(threadID))
        return Menu {
            ThreadActionItems(
                menu: ThreadActionMenu.make(subject), identifierPrefix: "thread.menu",
                perform: { perform($0, thread: thread) },
                custom: { kind in kind == .panes ? AnyView(PaneMenuItems(slots: slots, focused: shownPane)) : nil })
        } label: {
            Image(systemName: "ellipsis").frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .accessibilityLabel("thread.menu")
        .accessibilityIdentifier("thread.menu")
        .confirmationDialog("task.cancel.title", isPresented: $isConfirmingCancel, titleVisibility: .visible) {
            Button("task.cancel.confirm", role: .destructive) { Task { await thread.performWork(.cancel) } }
            Button("task.cancel.keep", role: .cancel) {}
        } message: {
            Text("task.cancel.message")
        }
    }

    private func perform(_ item: ThreadActionItem, thread: ThreadModel) {
        switch item.kind {
        case .star: actions.setStar(threadID, to: true, from: .header)
        case .unstar: actions.setStar(threadID, to: false, from: .header)
        case .work(let action): Task { await thread.performWork(action) }
        case .addSubThread: isAddSubThreadOpen = true
        case .share: isShareOpen = true
        case .openOnWeb: openWeb(webURL)
        case .copyLink:
            UIPasteboard.general.url = webURL
            copiedLink += 1
        case .cancelRequest: isConfirmingCancel = true
        case .close, .delete:
            actions.ask(
                item.kind == .close ? .close : .delete, from: .header, threadID: threadID,
                subThreadCount: subThreadCount, show: thread.snapshot?.show)
        case .open, .openInNewWindow, .openSource, .panes: break
        }
    }

    @ViewBuilder private var copiedToast: some View {
        if copiedLink > 0 {
            CopiedToast().id(copiedLink)
        }
    }
}

/// A brief confirmation that the link is on the clipboard. It leaves by itself.
struct CopiedToast: View {
    @State private var isShown = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        Group {
            if isShown {
                Text("thread.linkCopied").font(.yak(.subheadline, .medium))
                    .padding(.horizontal, 14).padding(.vertical, 8)
                    .background(.regularMaterial, in: .capsule)
                    .transition(reduceMotion ? .opacity : .opacity.combined(with: .move(edge: .top)))
                    .accessibilityIdentifier("thread.linkCopied")
            }
        }
        .task {
            withMotion(Motion.panel, reduceMotion: reduceMotion) { isShown = true }
            UIAccessibility.post(notification: .announcement, argument: String(localized: "thread.linkCopied"))
            do { try await Task.sleep(for: .seconds(1.6)) } catch { return }
            withMotion(Motion.panel, reduceMotion: reduceMotion) { isShown = false }
        }
    }
}

/// The connection line for the placement that hides the title row, which would otherwise carry it.
struct ConnectionBanner: View {
    let line: ConnectionLine

    var body: some View {
        InsetStrip(lead: line.text).accessibilityIdentifier("thread.connection")
    }
}
