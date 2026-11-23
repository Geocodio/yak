import SwiftUI
import WebKit
import YakKit

extension PaneDescriptor {
    static let preview = PaneDescriptor(
        id: .preview, name: "pane.preview", symbol: "safari",
        content: { thread in AnyView(PreviewPane(thread: thread)) },
        barRow: { _ in AnyView(EmptyView()) })
}

private struct PreviewDefaultsKey: EnvironmentKey { static let defaultValue: any KeyValueStore = UserDefaults.standard }

extension EnvironmentValues {
    /// Where the preview data stores are recorded; set by `appRoot`.
    var previewDefaults: any KeyValueStore {
        get { self[PreviewDefaultsKey.self] }
        set { self[PreviewDefaultsKey.self] = newValue }
    }
}

/// The Preview pane: the thread's app in a web view, or the card for the state the preview is in.
struct PreviewPane: View {
    let thread: ThreadModel

    @State private var model: PreviewModel?
    @State private var dataStore: WKWebsiteDataStore?
    @State private var hasShownPage = false
    @State private var couldNotOpen = false
    @Environment(SceneModel.self) private var scene
    @Environment(\.isPaneShown) private var isPaneShown
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.previewDefaults) private var defaults

    private var isShown: Bool { isPaneShown && scenePhase == .active }

    var body: some View {
        ZStack {
            Color.yakBackground.accessibilityElement().accessibilityLabel("pane.preview")
                .accessibilityIdentifier("preview.pane")
            if let model {
                if hasShownPage, let dataStore {
                    PreviewWebView(model: model, dataStore: dataStore)
                        .accessibilityHidden(model.card?.kind != .page || !isPaneShown)
                }
                // A card or page change fades; the fade is opacity only, so it also suits Reduce Motion.
                Group { layer(model) }
                    .animation(.easeInOut(duration: 0.2), value: model.card)
                    .animation(.easeInOut(duration: 0.2), value: model.page)
            } else if couldNotOpen {
                PreviewMessageCard(
                    symbol: "wifi.exclamationmark", title: "preview.notOpen.title",
                    message: Text("preview.notOpen.body"),
                    identifier: "preview.card.problem"
                ) {
                    Button {
                        Task { await run() }
                    } label: {
                        Text("common.retry")
                    }
                    .buttonStyle(.bordered).tint(.primary).controlSize(.large)
                }
            } else {
                AfterDelay(.milliseconds(300)) { PreviewSkeleton(isSlow: false, retry: {}) }
            }
        }
        .safeAreaInset(edge: .bottom, spacing: 0) { if let model { PreviewLines(model: model) } }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .task(id: thread.threadID) { await run() }
        .onChange(of: isShown) { Task { await model?.setVisible(isShown) } }
        .onChange(of: thread.snapshot) { Task { await model?.update(thread: thread.snapshot) } }
        .onChange(of: thread.fileOpenRequest) { model?.fileOpenRequestChanged() }
        .onChange(of: thread.fileOpenFailure) { model?.fileOpenFailed() }
        .onChange(of: Self.canOpenFiles(thread)) { _, can in model?.update(canOpenFiles: can) }
        .onChange(of: model?.card?.kind == .page) { _, isPage in
            if isPage, !hasShownPage, let model { showPage(model) }
        }
        .modifier(PreviewBarSlots(model: model))
    }

    static func canOpenFiles(_ thread: ThreadModel) -> Bool {
        FilesMode.built.contains(.everything) && thread.panes.contains(.files)
    }

    private func showPage(_ model: PreviewModel) {
        dataStore = PreviewWebData.store(for: thread.session.instance.id, defaults: defaults)
        hasShownPage = true
    }

    /// Opens a preview session for the pane and keeps it until the pane's task ends.
    private func run() async {
        couldNotOpen = false
        guard let preview = await thread.session.openPreview(thread.threadID) else {
            couldNotOpen = true
            return
        }
        let backend = LivePreviewBackend(
            preview: preview, session: thread.session, engine: thread.engine, defaults: defaults)
        let hasTerminal = Panes.built.contains(.terminal) && (thread.snapshot?.show?.panes.terminal ?? false)
        let next = PreviewModel(
            threadID: thread.threadID,
            instanceHost: PreviewNavigation.authority(of: thread.session.instance.url) ?? "",
            backend: backend, clock: SystemClock(), terminalAvailable: hasTerminal,
            canOpenFiles: Self.canOpenFiles(thread),
            focus: { scene.select($0) }, fileOpenRequest: { thread.fileOpenRequest },
            setFileOpenRequest: { thread.fileOpenRequest = $0 })
        model = next
        await next.start()
        await next.setVisible(isShown)
        await next.update(thread: thread.snapshot)
        // The status may have arrived while the page was being prepared.
        if next.card?.kind == .page { showPage(next) }
        // Waits until the task is cancelled, which is how the pane leaves or changes thread.
        while !Task.isCancelled {
            do { try await Task.sleep(for: .seconds(3600)) } catch { break }
        }
        await Task { await next.stop() }.value
    }

    // MARK: Layers over the page

    @ViewBuilder private func layer(_ model: PreviewModel) -> some View {
        if model.isStoreBlocked {
            PreviewMessageCard(
                symbol: "exclamationmark.triangle", title: "preview.store.title", message: Text("preview.store.body"),
                identifier: "preview.card.problem"
            ) { EmptyView() }
        } else if model.snapshot.hasEnded {
            Color.clear
        } else if let card = model.card {
            if card.kind == .page {
                pageLayer(model).transition(.opacity)
            } else {
                PreviewCardView(model: model, card: card).transition(.opacity)
            }
        } else {
            statusLayer(model)
        }
    }

    @ViewBuilder private func statusLayer(_ model: PreviewModel) -> some View {
        switch model.snapshot.load {
        case .loading: AfterDelay(.milliseconds(300)) { PreviewSkeleton(isSlow: false, retry: {}) }
        case .slow: PreviewSkeleton(isSlow: true) { Task { await model.refreshStatus() } }
        case .ready: Color.clear
        case .failed(let problem): failure(problem, model)
        }
    }

    @ViewBuilder private func failure(_ problem: ThreadProblem, _ model: PreviewModel) -> some View {
        switch problem {
        case .offline:
            PreviewMessageCard(
                symbol: "wifi.slash", title: "preview.offline.title", message: Text("preview.offline.body"),
                identifier: "preview.card.problem"
            ) { EmptyView() }
        case .notFound:
            PreviewMessageCard(
                symbol: "questionmark.folder", title: "preview.notFound.title", message: Text("preview.notFound.body"),
                identifier: "preview.card.problem"
            ) { EmptyView() }
        case .server(_, let message):
            PreviewMessageCard(
                symbol: "exclamationmark.triangle", title: "preview.serverError.title",
                message: Text(message.isEmpty ? String(localized: "preview.serverError.body") : message),
                identifier: "preview.card.problem"
            ) { retryButton(model) }
        case .needsPairing, .appTooOld, .serverTooOld:
            Color.clear
        }
    }

    private func retryButton(_ model: PreviewModel) -> some View {
        Button {
            Task { await model.refreshStatus() }
        } label: {
            Text("common.retry")
        }
        .buttonStyle(.bordered).tint(.primary).controlSize(.large).accessibilityIdentifier("preview.retry")
    }

    @ViewBuilder private func pageLayer(_ model: PreviewModel) -> some View {
        switch model.page {
        case .signingIn:
            AfterDelay(.milliseconds(300)) {
                VStack(spacing: 12) {
                    ProgressView()
                    Text("preview.signingIn").font(.yak(.subheadline, .medium)).foregroundStyle(Color.yakMuted)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity).background(.yakPage)
                .accessibilityElement(children: .combine).accessibilityIdentifier("preview.signingIn")
            }
        case .problem(let problem): problemCard(problem, model)
        case .signInFailed:
            PreviewMessageCard(
                symbol: "key.slash", title: "preview.signInFailed.title", message: Text("preview.signInFailed.body"),
                identifier: "preview.card.sign-in-failed"
            ) {
                Button {
                    Task { await model.perform(.tryAgain) }
                } label: {
                    Text("preview.action.tryAgain")
                }
                .buttonStyle(.bordered).tint(.primary).controlSize(.large)
                .accessibilityIdentifier("preview.signInAgain")
            }
        case .refused(let message):
            PreviewMessageCard(
                symbol: "nosign", title: "preview.refused.title", message: Text(message),
                identifier: "preview.card.refused"
            ) { EmptyView() }
        case .crashed:
            PreviewMessageCard(
                symbol: "exclamationmark.triangle", title: "preview.crashed.title",
                message: Text("preview.crashed.body"),
                identifier: "preview.card.problem"
            ) { reloadButton(model) }
        case .idle, .loading, .slow, .loaded, .cannotShowFile: Color.clear
        }
    }

    @ViewBuilder private func problemCard(_ problem: PreviewPageProblem, _ model: PreviewModel) -> some View {
        let host = model.previewHost
        switch problem {
        case .offline:
            PreviewMessageCard(
                symbol: "wifi.slash", title: "preview.offline.title", message: Text("preview.pageOffline.body"),
                identifier: "preview.card.problem"
            ) { reloadButton(model) }
        case .timedOut:
            PreviewMessageCard(
                symbol: "clock.badge.exclamationmark", title: "preview.timedOut.title",
                message: Text("preview.timedOut.body"), identifier: "preview.card.problem"
            ) { reloadButton(model) }
        case .untrustedCertificate:
            PreviewMessageCard(
                symbol: "lock.trianglebadge.exclamationmark", title: "preview.certificate.title",
                message: Text("preview.certificate.body \(host)"), identifier: "preview.card.problem"
            ) { reloadButton(model) }
        case .cannotReach:
            PreviewMessageCard(
                symbol: "wifi.exclamationmark", title: "preview.cannotReach.title",
                message: Text("preview.cannotReach.body \(host)"), identifier: "preview.card.problem"
            ) { reloadButton(model) }
        case .other:
            PreviewMessageCard(
                symbol: "exclamationmark.triangle", title: "preview.other.title", message: Text("preview.other.body"),
                identifier: "preview.card.problem"
            ) { reloadButton(model) }
        }
    }

    private func reloadButton(_ model: PreviewModel) -> some View {
        Button {
            Task { await model.reload() }
        } label: {
            Text("preview.reload")
        }
        .buttonStyle(.bordered).tint(.primary).controlSize(.large).accessibilityIdentifier("preview.card.reload")
    }
}

/// Shows its content only after `delay`, so a quick load never flashes it.
struct AfterDelay<Content: View>: View {
    let delay: Duration
    @ViewBuilder let content: Content
    @State private var isShown = false

    init(_ delay: Duration, @ViewBuilder content: () -> Content) {
        self.delay = delay
        self.content = content()
    }

    var body: some View {
        ZStack { if isShown { content.transition(.opacity) } }
            .task {
                do { try await Task.sleep(for: delay) } catch { return }
                withAnimation { isShown = true }
            }
    }
}

/// The lines above the bar: a failed action, a slow load, no connection, a file that cannot open.
private struct PreviewLines: View {
    let model: PreviewModel

    var body: some View {
        VStack(spacing: 6) {
            if let line = model.actionLine {
                row(Text(line), identifier: "preview.actionLine") {
                    if let retry = model.actionLineRetry {
                        Button {
                            Task { await model.perform(retry) }
                        } label: {
                            Text("common.retry")
                        }
                        .accessibilityIdentifier("preview.actionLine.retry")
                    }
                }
            }
            if model.page == .slow {
                row(Text("preview.line.slow"), identifier: "preview.slow") {
                    Button {
                        Task { await model.reload() }
                    } label: {
                        Text("preview.reload")
                    }
                    .accessibilityIdentifier("preview.slow.reload")
                }
            }
            if model.page == .cannotShowFile {
                row(Text("preview.line.cannotShowFile"), identifier: "preview.fileLine") {
                    Button {
                        Task { await model.perform(.openInBrowser) }
                    } label: {
                        Text("preview.action.openInSafari")
                    }
                }
            }
            if model.snapshot.isOffline {
                row(Text("preview.line.offline"), identifier: "preview.offline")
            }
        }
        .padding(.horizontal, 12).padding(.vertical, model.actionLine == nil && !model.snapshot.isOffline ? 0 : 4)
        .animation(.default, value: model.actionLine)
    }

    private func row(_ text: Text, identifier: String) -> some View {
        row(text, identifier: identifier) { EmptyView() }
    }

    private func row<Accessory: View>(
        _ text: Text, identifier: String, @ViewBuilder accessory: () -> Accessory
    ) -> some View {
        HStack(spacing: 12) {
            text.font(.yak(.subheadline, .medium)).fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
            accessory().font(.yak(.subheadline, .semibold)).tint(.primary).frame(minHeight: 44)
        }
        .padding(.horizontal, 14).padding(.vertical, 6)
        .yakCardSurface(in: .rect(cornerRadius: Design.Radius.card))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier(identifier)
    }
}

/// The pane's row in the bottom bar and its menu items, once its model exists.
private struct PreviewBarSlots: ViewModifier {
    let model: PreviewModel?

    func body(content: Content) -> some View {
        if let model {
            content
                .paneBarRow(key: "preview.row.\(model.card?.kind == .page)") {
                    PreviewBarRow(model: model, axis: .horizontal)
                }
                .paneStripRow(key: "preview.strip.\(model.card?.kind == .page)") {
                    PreviewBarRow(model: model, axis: .vertical)
                }
                .paneMenuItems(key: "preview.menu") { PreviewMenuItems(model: model) }
        } else {
            content
        }
    }
}
