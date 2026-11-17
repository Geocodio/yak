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
        ChangedFilesView.self
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
                                .opacity(mode == current ? 1 : 0)
                                .allowsHitTesting(mode == current)
                                .accessibilityHidden(mode != current)
                        }
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .background(Color(.systemBackground))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("files")
        .onAppear { open(current) }
        .onChange(of: current) { open(current) }
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
            .pickerStyle(.segmented).padding(.horizontal, 16).padding(.vertical, 8)
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
