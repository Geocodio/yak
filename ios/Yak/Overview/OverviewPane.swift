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

    @State private var model: OverviewModel?
    @Environment(\.isPaneShown) private var isPaneShown
    @Environment(\.scenePhase) private var scenePhase

    private var isVisible: Bool { isPaneShown && scenePhase == .active }

    var body: some View {
        Group {
            if let model, thread.snapshot?.show != nil {
                OverviewScroll(model: model, thread: thread)
            } else {
                ThreadLoadView(
                    presentation: thread.loadPresentation, retry: { Task { await thread.retry() } },
                    openThreads: {})
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemBackground))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("overview")
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

    @Environment(SceneModel.self) private var scene
    @State private var browserTarget: BrowserTarget?
    @State private var outputRow: RepositoryRow?
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass

    var body: some View {
        let content = model.content
        let isCompact = horizontalSizeClass == .compact
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                topLines
                if let content {
                    if content.subThreads != nil || shouldShowSubThreadsPlaceholder {
                        SubThreadsGroupView(
                            group: content.subThreads, load: stackLoad, model: model, retry: retry,
                            openThreads: openThreads)
                    }
                    StatusGroupView(status: content.status)
                    LinksGroupView(
                        links: content.links, load: environmentLoad, model: model,
                        openURL: { browserTarget = BrowserTarget(url: $0) }, showOutput: { outputRow = $0 },
                        retry: retry,
                        openThreads: openThreads)
                    AccessGroupView(access: content.access, model: model)
                    PeopleGroupView(people: content.people, now: Date())
                }
            }
            .padding(.horizontal, 16).padding(.top, 12).padding(.bottom, 96)
            .frame(maxWidth: 640).frame(maxWidth: .infinity)
        }
        .onAppear { model.onOpenThread = { scene.open(thread: $0, isCompactWidth: isCompact) } }
        .sheet(item: $browserTarget) { BrowserSheet(url: $0.url).ignoresSafeArea() }
        .sheet(item: $outputRow) { SetupOutputSheet(row: $0).presentationDetents([.medium, .large]) }
    }

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

    private func openThreads() { scene.setDrawerOpen(true) }
}

private struct BrowserTarget: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}
