import SwiftUI
import YakKit

/// A view for one way of listing files. Each mode is built by its own feature: a view file of its own, its mode in
/// `FilesMode.built` and one line in `FilesModeRegistry.views`.
@MainActor
protocol FilesModeView: View {
    static var mode: FilesMode { get }
    init(thread: ThreadModel, scene: SceneModel)
}

@MainActor
enum FilesModeRegistry {
    /// One line per mode view.
    static let views: [any FilesModeView.Type] = [
        ChangedFilesView.self,
        FilesEverythingView.self,
    ]

    static func view(for mode: FilesMode) -> (any FilesModeView.Type)? { views.first { $0.mode == mode } }
}

extension PaneDescriptor {
    static let files = PaneDescriptor(
        id: .files, name: "pane.files", symbol: "doc.text",
        content: { thread in AnyView(FilesPane(thread: thread)) },
        barRow: { thread in AnyView(FilesBar(thread: thread)) },
        badgeCount: { thread in
            let count = thread.snapshot?.show?.diffTotals?.filesCount ?? 0
            return count > 0 ? count : nil
        })
}

/// The Files pane: the switch between the built modes and the view of the selected one. Every mode that was
/// selected stays alive, so switching back keeps its place.
struct FilesPane: View {
    let thread: ThreadModel
    @Environment(SceneModel.self) private var scene
    @Environment(\.isPaneShown) private var isPaneShown
    @State private var selected: FilesMode?
    @State private var opened: [FilesMode] = []

    private var modes: [FilesMode] { FilesMode.built }
    private var current: FilesMode {
        selected ?? FilesMode.initial(hasChanges: thread.snapshot?.show?.panes.changes ?? false)
    }

    var body: some View {
        VStack(spacing: 0) {
            if modes.isEmpty {
                Text("files.noMode").foregroundStyle(Color.yakMuted).frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                modeSwitch
                ZStack {
                    ForEach(opened, id: \.self) { mode in
                        if let type = FilesModeRegistry.view(for: mode) {
                            AnyView(type.init(thread: thread, scene: scene))
                                // A hidden mode keeps its state but hands nothing to the bar, the strip or the menu,
                                // and reacts to no shortcut or visibility change.
                                .environment(\.isPaneShown, isPaneShown && mode == current)
                                .transformPreference(PaneBarRowKey.self) { if mode != current { $0 = [:] } }
                                .transformPreference(PaneStripRowKey.self) { if mode != current { $0 = [:] } }
                                .transformPreference(PaneMenuItemsKey.self) { if mode != current { $0 = [:] } }
                                .opacity(mode == current ? 1 : 0)
                                .allowsHitTesting(mode == current)
                                .accessibilityHidden(mode != current || !isPaneShown)
                        }
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .background(Color.yakBackground)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("files")
        .accessibilityHidden(!isPaneShown)
        .onAppear {
            pinModeOnceKnown()
            routeFileOpenRequest()
            open(current)
        }
        .onChange(of: thread.fileOpenRequest) { routeFileOpenRequest() }
        .onChange(of: thread.snapshot?.show != nil) { pinModeOnceKnown() }
        .onChange(of: current) { open(current) }
        .onChange(of: thread.files.requestedDiffPath) { routeDiffRequest() }
        .onChange(of: thread.files.files) { routeDiffRequest() }
    }

    /// The first mode is kept for as long as the pane lives, so a turn that makes the first change does not move the
    /// person from the Everything editor to Changed.
    private func pinModeOnceKnown() {
        if selected == nil, thread.snapshot?.show != nil { selected = current }
    }

    /// A file another pane wants open: Everything shows it, and that mode's view takes the request itself.
    private func routeFileOpenRequest() {
        guard thread.fileOpenRequest != nil else { return }
        selected = .everything
        open(.everything)
    }

    /// "View diff" in the Everything viewer: show Changed on that file once its list has it.
    private func routeDiffRequest() {
        guard let path = thread.files.requestedDiffPath else { return }
        selected = .changed
        guard let key = Self.diffKey(for: path, in: thread.files.files) else {
            // A loaded list without the file has nothing to show for it; Changed stays on its own file.
            if thread.files.snapshot?.load == .ready { thread.files.requestedDiffPath = nil }
            return
        }
        thread.files.requestedDiffPath = nil
        Task { await thread.files.show(key) }
    }

    static func diffKey(for path: String, in files: [DiffFileSummary]) -> DiffFileKey? {
        files.first { $0.path == path }?.key
    }

    /// One built mode is a selected label that does nothing; two or more are a segmented switch.
    @ViewBuilder private var modeSwitch: some View {
        if modes.count > 1 {
            Picker(
                selection: Binding(get: { current }, set: { selected = $0 }),
                content: {
                    ForEach(modes, id: \.self) { Text(Self.title(of: $0)).tag($0) }
                },
                label: { Text("files.mode.switch") }
            )
            .pickerStyle(.segmented).padding(.horizontal, Design.Space.pageInset).padding(
                .vertical, Design.Space.compact
            )
            .accessibilityIdentifier("files.mode")
        } else if let only = modes.first {
            Text(Self.title(of: only)).font(.subheadline.weight(.semibold))
                .frame(maxWidth: .infinity, minHeight: 44).accessibilityAddTraits(.isHeader)
                .accessibilityIdentifier("files.mode")
        }
    }

    private func open(_ mode: FilesMode) {
        if !opened.contains(mode) { opened.append(mode) }
    }

    static func title(of mode: FilesMode) -> LocalizedStringResource {
        switch mode {
        case .changed: "files.mode.changed"
        case .everything: "files.mode.everything"
        }
    }
}
