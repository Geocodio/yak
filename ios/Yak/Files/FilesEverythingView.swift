import SwiftUI
import YakKit

/// Everything mode of the Files pane: the tree of the sandbox's files with find, the open file and every sandbox
/// state. The model lives with this view, so a mode or pane switch keeps the open file and its text.
struct FilesEverythingView: FilesModeView {
    static let mode = FilesMode.everything

    let thread: ThreadModel
    @State private var model: FilesEverythingModel
    @State private var openHandler: FileOpenHandler
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.isNetworkOnline) private var isOnline

    init(thread: ThreadModel, scene: SceneModel) {
        self.thread = thread
        let model = FilesEverythingModel(thread: thread, timings: .forThisRun)
        _model = State(initialValue: model)
        _openHandler = State(initialValue: FileOpenHandler { path, edit in await model.open(path: path, edit: edit) })
    }

    var body: some View {
        content
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color(.systemBackground))
            .paneBarRow(key: barKey) { barRow }
            .task { await model.start() }
            .task(id: thread.fileOpenRequest) {
                await openHandler.take(
                    thread.fileOpenRequest, current: { thread.fileOpenRequest }, clear: { thread.fileOpenRequest = nil }
                )
            }
            .onChange(of: thread.snapshot?.turn?.state) { _, state in
                if let state, !state.isActive { Task { await model.turnFinished() } }
            }
            .onChange(of: isOnline, initial: true) { _, online in Task { await model.networkChanged(isOnline: online) }
            }
            .onDisappear { Task { await model.stop() } }
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("files.everything")
    }

    private var barKey: String {
        guard let document = model.document else { return "find" }
        return document.isEditing ? "editor" : "viewer"
    }

    @ViewBuilder private var barRow: some View {
        if let document = model.document {
            if !document.isEditing { FileViewerBarRow(model: model, document: document) }
        } else {
            FindField(model: model)
        }
    }

    @ViewBuilder private var content: some View {
        if let document = model.document {
            FileViewer(model: model, document: document)
        } else if let snapshot = model.tree {
            treeContent(snapshot)
        } else {
            WorkspaceStateView(content: WorkspaceStateContent(title: "files.loading.title", showsSkeleton: true))
                .accessibilityIdentifier("files.loading")
        }
    }

    @ViewBuilder private func treeContent(_ snapshot: FileTreeSnapshot) -> some View {
        if let state = Self.stateContent(snapshot, model: model) {
            WorkspaceStateView(content: state, isBusy: model.isRestoring, message: model.restoreMessage)
        } else {
            VStack(spacing: 0) {
                if snapshot.isOffline { FileLine(text: Text("files.offline.line"), identifier: "files.offlineLine") }
                if model.isFinding {
                    FileFindView(model: model)
                } else {
                    FileTreeView(model: model, snapshot: snapshot)
                }
            }
        }
    }

    /// The full-pane state for the tree's root and the sandbox, or nil when the tree shows.
    static func stateContent(_ snapshot: FileTreeSnapshot, model: FilesEverythingModel) -> WorkspaceStateContent? {
        let retry: () -> Void = { Task { await model.retry() } }
        let restore: () -> Void = { Task { await model.restore() } }
        if let availability = snapshot.availability {
            return .availability(availability, retry: retry, restore: restore)
        }
        switch snapshot.root {
        case .notLoaded, .loading:
            if snapshot.isSlow {
                return WorkspaceStateContent(
                    title: "files.slow", action: ("files.retry", "files.state.action", retry), showsSkeleton: true)
            }
            return WorkspaceStateContent(title: "files.loading.title", showsSkeleton: true)
        case .failed(let problem):
            if snapshot.rows.isEmpty { return .problem(problem, retry: retry) }
            return nil
        case .loaded:
            if snapshot.rows.isEmpty { return WorkspaceStateContent(title: "files.everything.empty.title") }
            return nil
        }
    }
}
