import SwiftUI
import YakKit

extension PaneDescriptor {
    static let overview = PaneDescriptor(
        id: .overview, name: "pane.overview", symbol: "list.bullet.rectangle",
        content: { thread in AnyView(OverviewPane(thread: thread)) },
        barRow: { _ in AnyView(EmptyView()) })
}

/// The Overview pane: the groups of one thread, in the order the contract names them.
struct OverviewPane: View {
    let thread: ThreadModel
    /// Set when the Overview is the side panel beside the conversation: the header and its close button show, and
    /// the panel is visible for as long as it exists.
    var onClosePanel: (() -> Void)?

    @State private var model: OverviewModel?
    @Environment(\.isPaneShown) private var isPaneShown
    @Environment(\.scenePhase) private var scenePhase
    @Environment(SceneModel.self) private var scene
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass

    private var isVisible: Bool { (onClosePanel != nil || isPaneShown) && scenePhase == .active }

    var body: some View {
        Group {
            if let model, thread.snapshot?.show != nil {
                VStack(spacing: 0) {
                    if let onClosePanel, let content = model.content {
                        OverviewPanelHeader(content: content, close: onClosePanel)
                    }
                    OverviewScroll(model: model, thread: thread, bottomInset: onClosePanel == nil ? 96 : 0)
                }
            } else {
                ThreadLoadView(
                    presentation: thread.loadPresentation, retry: { Task { await thread.retry() } },
                    openThreads: {
                        // A wide window already lists the threads, so the open thread is left to show the list's choice.
                        if horizontalSizeClass != .compact { scene.closeThread() }
                        scene.setDrawerOpen(true)
                    })
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemBackground))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier(onClosePanel == nil ? "overview" : "overview.panel")
        .task(id: thread.threadID) { await run() }
        .onChange(of: isVisible) { Task { await model?.setVisible(isVisible) } }
    }

    /// One model per thread while the pane exists; it stops when the thread changes or the pane goes.
    private func run() async {
        let next = OverviewModel(
            threadID: thread.threadID, session: thread.session, thread: thread, clock: SystemClock())
        model = next
        await next.setVisible(isVisible)
        await next.start()
        // Waits until the task is cancelled, which is how the pane leaves or changes thread.
        while !Task.isCancelled { try? await Task.sleep(for: .seconds(3600)) }
        await Task { await next.stop() }.value
    }
}

private struct OverviewScroll: View {
    let model: OverviewModel
    let thread: ThreadModel
    /// The height of the floating pane bar under the page; the side panel has none.
    let bottomInset: CGFloat

    @Environment(SceneModel.self) private var scene
    @State private var browserTarget: BrowserTarget?
    @State private var outputRow: RepositoryRow?
    @State private var isAddOpen = false
    @State private var isShareOpen = false
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass

    var body: some View {
        let content = model.content
        let isCompact = horizontalSizeClass == .compact
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 20) {
                topLines
                if let content {
                    if content.subThreads != nil || shouldShowSubThreadsPlaceholder {
                        SubThreadsGroupView(
                            group: content.subThreads, load: stackLoad, model: model, retry: retry,
                            openThreads: openThreads,
                            onAdd: thread.snapshot?.show?.canDrive == true ? { isAddOpen = true } : nil)
                    }
                    StatusGroupView(status: content.status)
                    LinksGroupView(
                        links: content.links, load: environmentLoad, model: model,
                        openURL: { browserTarget = BrowserTarget(url: $0) }, showOutput: { outputRow = $0 },
                        retry: retry,
                        openThreads: openThreads)
                    AccessGroupView(access: content.access, model: model)
                    PeopleGroupView(people: content.people, now: Date()) { isShareOpen = true }
                }
            }
            .padding(.horizontal, 16).padding(.top, 12).padding(.bottom, 16)
            .frame(maxWidth: 640).frame(maxWidth: .infinity)
        }
        // The page stops above the floating bar, so no row's text sits under its glass or on the screen edge.
        .padding(.bottom, bottomInset)
        .paneBarRow(key: isOwner) {
            if isOwner {
                Button {
                    isShareOpen = true
                } label: {
                    Label("thread.menu.share", systemImage: "person.2").font(.body.weight(.medium))
                        .frame(minHeight: 44).padding(.horizontal, 16)
                }
                .buttonStyle(.plain).foregroundStyle(.primary)
                .background(Color(.secondarySystemBackground), in: .capsule)
                .accessibilityIdentifier("overview.shareButton")
            }
        }
        .onAppear { model.onOpenThread = { scene.open(thread: $0, isCompactWidth: isCompact) } }
        .sheet(item: $browserTarget) { BrowserSheet(url: $0.url).ignoresSafeArea() }
        .sheet(isPresented: $isAddOpen) {
            AddSubThreadSheet(session: thread.session, parentID: thread.threadID) {
                scene.open(thread: $0, isCompactWidth: isCompact)
            }
        }
        .sheet(isPresented: $isShareOpen) {
            ShareSheet(session: thread.session, threadID: thread.threadID) { await thread.engine?.refreshShow() }
        }
        .sheet(item: $outputRow) { SetupOutputSheet(row: $0).presentationDetents([.medium, .large]) }
    }

    private var isOwner: Bool { thread.snapshot?.show?.thread.isOwner == true }

    private var stackLoad: GroupLoad { .of(model.state?.stack, couldNotOpen: model.couldNotOpen) }
    private var environmentLoad: GroupLoad { .of(model.state?.environment, couldNotOpen: model.couldNotOpen) }

    /// Before the stack arrives the thread may or may not have sub-threads, so the group shows its waiting state
    /// (after 300 ms) or the problem, and goes away if the stack turns out empty.
    private var shouldShowSubThreadsPlaceholder: Bool {
        switch stackLoad {
        case .ready: false
        case .loading: model.showsSkeleton
        case .slow, .failed: true
        }
    }

    /// The offline line, the "could not refresh" line and the last action's sentence, above the groups.
    @ViewBuilder private var topLines: some View {
        if model.isOffline {
            line(Text("overview.offline.top"), identifier: "overview.offline", retry: false)
        } else if model.hasStaleValues {
            line(Text("overview.stale"), identifier: "overview.stale", retry: true)
        }
        if let text = model.actionLine {
            line(Text(text), identifier: "overview.actionLine", retry: false)
        }
    }

    private func line(_ text: Text, identifier: String, retry showsRetry: Bool) -> some View {
        HStack(spacing: 12) {
            text.font(.subheadline.weight(.medium)).foregroundStyle(Color.yakWarning)
                .fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
            if showsRetry {
                Button(action: retry) { Text("common.retry") }
                    .buttonStyle(.bordered).tint(.primary).accessibilityIdentifier("overview.retry")
            }
        }
        .padding(12).frame(maxWidth: .infinity, alignment: .leading)
        .background(Color(.secondarySystemBackground), in: .rect(cornerRadius: 12))
        .accessibilityElement(children: showsRetry ? .contain : .combine).accessibilityIdentifier(identifier)
    }

    private func retry() { Task { await model.retry() } }

    private func openThreads() {
        // A wide window already lists the threads, so the open thread is left to show the list's choice.
        if horizontalSizeClass != .compact { scene.closeThread() }
        scene.setDrawerOpen(true)
    }
}

private struct BrowserTarget: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}
