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

private final class RequestOwner {}

private struct ChatPane: View {
    let thread: ThreadModel
    @Environment(SceneModel.self) private var scene
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass
    @Environment(\.webConversation) private var web
    @Environment(\.previewDefaults) private var defaults
    @Environment(\.inputBlocked) private var inputBlocked
    @Environment(\.scenePhase) private var scenePhase
    @Environment(SystemFeatures.self) private var system
    @State private var paneHeight: CGFloat = 600
    @State private var browserURL: BrowserLink?
    /// Names this pane to the window's web conversation, which hands the page's requests to one pane at a time.
    @State private var requestOwner = RequestOwner()

    /// The page shows the conversation and its cards; the native chat shows them when the server has no page.
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
            if !usesWeb, web?.mode != .undecided, case .conversation = thread.loadPresentation {
                ThreadCardHost(
                    thread: thread, paneHeight: paneHeight, openWeb: { browserURL = BrowserLink(url: $0) },
                    openFiles: thread.panes.contains(.files) ? { scene.select(.files) } : nil)
            }
        }
        .task(id: canChooseConversation) { await followConversationChoice() }
        .task(id: usesWeb) { await followOutbox() }
        .onAppear {
            web?.takeRequests(owner: requestOwner) { [thread, scene] request in
                handle(request, thread: thread, scene: scene)
            }
            web?.show(thread: thread.threadID)
        }
        .onDisappear { web?.releaseRequests(owner: requestOwner) }
        .onChange(of: thread.isOffline, initial: true) { web?.connectivityChanged(isOnline: !thread.isOffline) }
        .onChange(of: isCovered, initial: true) { web?.setPageHidden(isCovered) }
        .sensoryFeedback(.success, trigger: thread.question.sentCount)
        .sensoryFeedback(.error, trigger: thread.question.failureCount)
        .onGeometryChange(for: CGFloat.self) {
            $0.size.height
        } action: {
            paneHeight = $0
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(.yakPage)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("conversation")
        .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
    }

    private func openThread(_ id: String) {
        scene.open(thread: id, isCompactWidth: horizontalSizeClass == .compact)
    }

    private var legacyLine: some View {
        Text("composer.legacy.line").font(.footnote.weight(.medium)).foregroundStyle(.primary)
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
            ZStack {
                if web?.mode != .undecided, case .conversation = thread.loadPresentation {
                    ChatView(thread: thread, openThread: openThread)
                }
                ThreadLoadView(
                    presentation: nativePresentation, retry: { Task { await thread.retry() } },
                    openThreads: openThreads)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .motion(Motion.crossFade, value: thread.loadPresentation)
        }
    }

    /// Until the page or the native chat is chosen, a ready thread still shows the loading state.
    private var nativePresentation: ThreadLoadPresentation {
        web?.mode == .undecided && thread.loadPresentation == .conversation ? .loading : thread.loadPresentation
    }

    private func webConversation(_ web: WebConversationModel) -> some View {
        let presentation = web.loadPresentation
        let isShown = presentation == .conversation && !isCovered
        return VStack(spacing: 0) {
            if let notice = web.noticeLine {
                FileLine(text: Text(verbatim: notice), identifier: "thread.web.notice")
            }
            if web.showsOfflineLine {
                FileLine(text: Text("web.offline.line"), identifier: "thread.web.offlineLine")
            }
            ZStack {
                WebConversationView(
                    model: web,
                    dataStore: {
                        PreviewWebData.store(for: thread.session.instance.id, purpose: .embed, defaults: defaults)
                    }, version: thread.session.appVersion
                )
                .accessibilityHidden(!isShown)
                ThreadLoadView(
                    presentation: presentation, retry: { web.retry() }, openThreads: openThreads
                )
                .background(presentation == .conversation ? Color.clear : Color.yakBackground)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .motion(Motion.crossFade, value: presentation)
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
            if let me = await thread.session.me() { web.configure(supportsEmbeddedThread: me.supportsEmbeddedThread) }
            let wait = web.mode == .undecided ? Self.choicePollInterval : Self.choiceRecheckInterval
            do { try await thread.session.clock.sleep(for: wait) } catch { return }
        }
    }

    // MARK: From the page

    /// Sends the unsent messages of the thread to the page, and tells it when the server took one.
    private func followOutbox() async {
        guard usesWeb, let web, let outbox = await thread.session.outbox() else { return }
        let threadID = thread.threadID
        for await update in await outbox.updates() {
            switch update {
            case .items(let items): web.outboxChanged(threadID: threadID, items: items)
            case .delivery(let delivery): if delivery.threadID == threadID { web.messageDelivered() }
            }
        }
    }

    private func handle(_ request: WebConversationRequest, thread: ThreadModel, scene: SceneModel) {
        switch request {
        case .link(let url): browserURL = BrowserLink(url: url)
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
