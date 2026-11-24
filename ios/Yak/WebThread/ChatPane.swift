import SwiftUI
import YakKit

/// The Chat pane: the thread's conversation as the server's web page, with the native composer in its bar row. A server
/// too old to offer the page gets a short state with a button that opens the thread in the browser sheet.
extension PaneDescriptor {
    static let chat = PaneDescriptor(
        id: .chat, name: "pane.chat", symbol: "bubble.left",
        content: { thread in AnyView(ChatPane(thread: thread)) },
        barRow: { thread in AnyView(ComposerBar(thread: thread)) }, hasBadge: { $0.hasOpenQuestion })
}

private final class RequestOwner {}

private struct FileViewerItem: Identifiable {
    let model: FileViewerModel
    var id: String { model.url.absoluteString }
}

private struct ChatPane: View {
    let thread: ThreadModel
    @Environment(SceneModel.self) private var scene
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass
    @Environment(\.webConversation) private var web
    @Environment(\.previewDefaults) private var defaults
    @Environment(\.inputBlocked) private var inputBlocked
    @Environment(\.scenePhase) private var scenePhase
    @Environment(SystemFeatures.self) private var system
    @State private var browserURL: BrowserLink?
    @State private var fileViewer: FileViewerItem?
    /// Names this pane to the window's web conversation, which hands the page's requests to one pane at a time.
    @State private var requestOwner = RequestOwner()

    /// The page shows the conversation and its cards; a server that has no page gets the update state instead.
    private var usesWeb: Bool { web?.mode == .web }
    /// The app is locked or covered: the web view is hidden, so a field of the page cannot keep focus under the cover.
    private var isCovered: Bool {
        inputBlocked || system.appLock.coversContent(sceneIsActive: scenePhase == .active)
    }
    private var canChooseConversation: Bool { thread.engine != nil || thread.loadPresentation != .loading }

    var body: some View {
        VStack(spacing: 0) {
            conversation
            if usesWeb || thread.loadPresentation == .conversation, thread.composer.bar.line != nil {
                legacyLine
            }
        }
        .task(id: canChooseConversation) { await followConversationChoice() }
        .task(id: usesWeb) { await followOutbox() }
        .onAppear {
            web?.takeRequests(owner: requestOwner) { [thread, scene] request in
                handle(request, thread: thread, scene: scene)
            }
            web?.show(thread: thread.threadID)
            takeFocusRequest()
        }
        .onChange(of: scene.focusRequest) { takeFocusRequest() }
        .onChange(of: thread.announcementSerial) {
            if let text = thread.announcement { UIAccessibility.post(notification: .announcement, argument: text) }
        }
        .onChange(of: thread.unsentItems, initial: true) { sendOutbox() }
        .onChange(of: thread.uploadSummary.failed) { sendOutbox() }
        .onDisappear {
            web?.paneLeft()
            web?.releaseRequests(owner: requestOwner)
        }
        .onChange(of: thread.isOffline, initial: true) { web?.connectivityChanged(isOnline: !thread.isOffline) }
        .onChange(of: isCovered, initial: true) { web?.setPageHidden(isCovered) }
        .onChange(of: scenePhase) { if scenePhase != .active { web?.appLeftForeground() } }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(.yakPage)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("conversation")
        .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
        .sheet(item: $fileViewer) { item in
            FileViewerSheet(
                model: item.model,
                dataStore: PreviewWebData.store(for: thread.session.instance.id, purpose: .embed, defaults: defaults),
                version: thread.session.appVersion, isOffline: thread.isOffline, isCovered: isCovered,
                openThread: { openThread($0) })
        }
    }

    /// A push or link named a message or card of the shown thread: the web conversation scrolls to it once its page is
    /// ready. A request for another thread stays for the pane of that thread.
    private func takeFocusRequest() {
        guard let request = scene.takeFocusRequest(forThread: thread.threadID) else { return }
        web?.focus(request)
    }

    /// A shared file's page opens in the viewer, which is signed in through the conversation's own session.
    private func openFile(_ url: URL) {
        guard let web,
            let policy = FileViewerPolicy(
                fileURL: url, instanceURL: web.instanceURL, allowsLoopbackHTTP: web.allowsLoopbackHTTP)
        else {
            browserURL = BrowserLink(url: url)
            return
        }
        fileViewer = FileViewerItem(
            model: FileViewerModel(
                url: url, policy: policy, isOnline: !thread.isOffline, renewSession: { await web.renewSession() }))
    }

    private func openThread(_ id: String) {
        scene.open(thread: id, isCompactWidth: horizontalSizeClass == .compact)
    }

    private var legacyLine: some View {
        Text("composer.legacy.line").font(.yak(.footnote, .medium)).foregroundStyle(.primary)
            .fixedSize(horizontal: false, vertical: true)
            .padding(.vertical, Design.Space.medium)
            .padding(.horizontal, Design.Space.groupInset)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Tone.neutral.surface, in: .rect(cornerRadius: Design.Radius.row))
            .padding(.horizontal, Design.Space.compact).padding(.bottom, Design.Space.compact)
            .accessibilityIdentifier("composer.legacyLine")
    }

    private func openThreads() {
        // A wide window already lists the threads, so the open thread is left to show the list's choice.
        if horizontalSizeClass != .compact { scene.closeThread() }
        scene.setDrawerOpen(true)
    }

    // MARK: The conversation

    @ViewBuilder private var conversation: some View {
        if let web, web.mode == .web {
            webConversation(web)
        } else {
            ThreadLoadView(
                presentation: web?.mode == .unsupported ? .serverNeedsUpdate : undecidedPresentation,
                retry: { Task { await thread.retry() } }, openThreads: openThreads,
                openInBrowser: { browserURL = BrowserLink(url: serverPageURL) }
            )
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .motion(Motion.crossFade, value: web?.mode)
        }
    }

    /// The thread's own page on the instance, for a server that cannot show it in the app.
    private var serverPageURL: URL { thread.instanceURL.appending(path: "threads").appending(path: thread.threadID) }

    /// Until the server has said whether it offers the page, a ready thread still shows the loading state.
    private var undecidedPresentation: ThreadLoadPresentation {
        thread.loadPresentation == .conversation ? .loading : thread.loadPresentation
    }

    private func webConversation(_ web: WebConversationModel) -> some View {
        let presentation = web.loadPresentation
        let isShown = presentation == .conversation && !isCovered
        return VStack(spacing: 0) {
            if let line = thread.actionLine { FileLine(text: Text(verbatim: line), identifier: "thread.actionLine") }
            if let notice = web.noticeLine {
                FileLine(text: Text(verbatim: notice), identifier: "thread.web.notice")
            }
            if let copy = web.savedCopy, let state = web.savedCopyState {
                SavedCopyLine(state: state, savedAt: copy.savedAt, retry: { web.retry() })
            } else if web.showsOfflineLine {
                FileLine(text: Text("web.offline.line"), identifier: "thread.web.offlineLine")
            }
            ZStack {
                WebConversationView(
                    model: web,
                    dataStore: {
                        PreviewWebData.store(for: thread.session.instance.id, purpose: .embed, defaults: defaults)
                    }, version: thread.session.appVersion
                )
                .accessibilityHidden(!isShown || web.savedCopyState != nil)
                if let copy = web.savedCopy, web.savedCopyState != nil {
                    SavedCopyView(
                        copy: copy, instanceURL: web.instanceURL, isHidden: isCovered, focus: web.copyFocus,
                        focusHandled: { web.copyFocusHandled(serial: $0) },
                        blockedTap: { web.showNotice(String(localized: "chat.action.offline")) },
                        failed: { web.savedCopyFailed() }
                    )
                    .accessibilityHidden(isCovered)
                    .transition(.opacity)
                }
                ThreadLoadView(
                    presentation: presentation, retry: { web.retry() }, openThreads: openThreads
                )
                .background(presentation == .conversation ? Color.clear : Color.yakBackground)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .motion(Motion.crossFade, value: presentation)
        .motion(Motion.crossFade, value: web.savedCopyState != nil)
    }

    /// How often a window that has chosen asks again whether the server turned the page on or off.
    private static let choiceRecheckInterval: Duration = .seconds(5)
    /// How often a window that has not chosen yet asks whether the server's answer has arrived.
    private static let choicePollInterval: Duration = .seconds(1)

    /// The choice waits for the server's answer (`me`); until then the window shows its loading state. A later
    /// change of the answer is followed.
    private func followConversationChoice() async {
        guard canChooseConversation, let web else { return }
        while !Task.isCancelled {
            if let me = await thread.session.me() {
                web.configure(supportsEmbeddedThread: me.supportsEmbeddedThread)
            } else if web.mode == .undecided, await web.hasSavedCopy(of: thread.threadID) {
                // Offline at launch the server's answer is not known; a saved copy exists only where the page did.
                web.configure(supportsEmbeddedThread: true)
            }
            let wait = web.mode == .undecided ? Self.choicePollInterval : Self.choiceRecheckInterval
            do { try await thread.session.clock.sleep(for: wait) } catch { return }
        }
    }

    // MARK: From the page

    /// Tells the page when the server took a message of the thread.
    private func followOutbox() async {
        guard usesWeb, let web, let outbox = await thread.session.outbox() else { return }
        let threadID = thread.threadID
        for await update in await outbox.updates() {
            if case .delivery(let delivery) = update, delivery.threadID == threadID { web.messageDelivered() }
        }
    }

    /// The unsent messages of the thread, and the uploads that failed, go to the page.
    private func sendOutbox() {
        web?.outboxChanged(
            threadID: thread.threadID, items: thread.unsentItems, failedUploads: thread.uploadSummary.failed)
    }

    private func handle(_ request: WebConversationRequest, thread: ThreadModel, scene: SceneModel) {
        switch request {
        case .link(let url): browserURL = BrowserLink(url: url)
        case .file(let url): openFile(url)
        case .openThread(let id): openThread(id)
        case .openPane(let pane): open(pane, thread: thread, scene: scene)
        case .threadChanged(let id):
            scene.openReplacement(id, sentFrom: thread.threadID, isCompactWidth: horizontalSizeClass == .compact)
        case .cardAnswered(_, _, let isOK):
            Haptic.playCardAnswered(isOK: isOK)
            Task { await thread.engine?.refreshShow() }
        case .unsent(let key, let action): Task { await perform(action, on: key, thread: thread, scene: scene) }
        }
    }

    private func open(_ pane: EmbedPane, thread: ThreadModel, scene: SceneModel) {
        let target: PaneID
        switch pane {
        case .files(let mode, let path):
            target = .files
            guard thread.panes.contains(target) else { return }
            if let path {
                if mode == .changed {
                    thread.files.requestedDiffPath = path
                } else {
                    thread.fileOpenRequest = FileOpenRequest(path: path, edit: false)
                }
            }
        case .preview: target = .preview
        case .terminal: target = .terminal
        case .overview: target = .overview
        }
        guard thread.panes.contains(target) else { return }
        scene.select(target)
    }

    private func perform(_ action: EmbedUnsentAction, on key: UUID, thread: ThreadModel, scene: SceneModel) async {
        switch action {
        case .retry: await thread.retryUnsent(key)
        case .discard: _ = await thread.removeUnsent(key)
        case .edit:
            guard let item = await thread.session.outbox()?.items().first(where: { $0.id == key }),
                await thread.edit(item)
            else { return }
            scene.focus(pane: .chat)
            scene.requestComposerFocus()
        }
    }
}
