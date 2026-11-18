import SwiftUI
import YakKit

/// The open file: its header, its text with line numbers and colors, or the state that stands in for it. While the
/// person edits, the editor takes its place.
struct FileViewer: View {
    let model: FilesEverythingModel
    let document: FileDocumentModel

    var body: some View {
        if document.isEditing {
            FileEditor(model: model, document: document)
        } else {
            VStack(spacing: 0) {
                FileHeader(path: document.path, mark: document.mark)
                if model.tree?.isOffline == true, document.viewedText != nil {
                    FileLine(text: Text("files.viewer.offline"), identifier: "files.viewer.offlineLine")
                }
                if document.showsChangedNotice {
                    FileLine(text: Text("files.viewer.changed"), identifier: "files.viewer.changedLine")
                }
                content
            }
            .background(Color(.systemBackground))
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("files.viewerScreen")
        }
    }

    @ViewBuilder private var content: some View {
        switch document.snapshot.phase {
        case .loading(let isSlow):
            VStack(spacing: 12) {
                FileSkeletonRows(count: 12)
                if isSlow {
                    Text("files.slow").font(.body).foregroundStyle(Color.yakMuted)
                    Button("files.retry") { Task { await document.reload() } }.frame(minHeight: 44)
                        .accessibilityIdentifier("files.state.action")
                }
                Spacer(minLength: 0)
            }
            .accessibilityIdentifier("files.viewer.loading")
        case .text(let content):
            VStack(spacing: 0) {
                CodeTextView(
                    text: content.content ?? "",
                    tokens: document.highlightedSource == content.content ? document.tokens : [],
                    wrapsLines: document.wrapsLines)
                if document.colorsStop {
                    Text("files.viewer.colorsStop").font(.footnote).foregroundStyle(Color.yakMuted)
                        .frame(maxWidth: .infinity, alignment: .leading).padding(12)
                }
            }
        case .binary(let content):
            WorkspaceStateView(
                content: WorkspaceStateContent(
                    title: "files.binary.title", detail: Text("files.binary.detail \(content.size)")))
        case .tooLarge(let content):
            WorkspaceStateView(
                content: WorkspaceStateContent(
                    title: "files.tooLarge.title",
                    detail: Text(
                        "files.tooLarge.detail \(ByteCountFormatter.string(fromByteCount: Int64(content.size), countStyle: .file))"
                    )))
        case .notFound:
            WorkspaceStateView(
                content: WorkspaceStateContent(
                    title: "files.notFound.title", detail: Text("files.notFound.detail"),
                    action: ("files.notFound.back", "files.viewer.backToFiles", { Task { await model.closeFile() } })))
        case .unavailable(let availability):
            WorkspaceStateView(
                content: .availability(
                    availability, retry: { Task { await document.reload() } },
                    restore: { Task { await model.restore() } }),
                isBusy: model.isRestoring, message: model.restoreMessage)
        case .failed(let problem):
            WorkspaceStateView(content: .problem(problem, retry: { Task { await document.reload() } }))
        }
    }
}

/// The file name in a monospaced semibold font, the folder below it and, for a marked file, what happened to it.
struct FileHeader: View {
    let path: String
    let mark: FileMark?

    var body: some View {
        let name = (path as NSString).lastPathComponent
        let folder = (path as NSString).deletingLastPathComponent
        VStack(alignment: .leading, spacing: 2) {
            Text(verbatim: name).font(.headline.monospaced())
            folderLine(folder).font(.subheadline).foregroundStyle(Color.yakMuted)
        }
        .fixedSize(horizontal: false, vertical: true)
        .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 16).padding(.vertical, 10)
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(.isHeader)
        .accessibilityIdentifier("files.viewer.header")
    }

    private func folderLine(_ folder: String) -> Text {
        switch (mark, folder.isEmpty) {
        case (.modified, false): Text("files.viewer.folderChanged \(folder)")
        case (.added, false): Text("files.viewer.folderAdded \(folder)")
        case (.modified, true): Text("files.viewer.rootChanged")
        case (.added, true): Text("files.viewer.rootAdded")
        case (_, false): Text(verbatim: folder)
        default: Text("files.viewer.rootFolder")
        }
    }
}

/// A line of information over the text on a tinted surface.
struct FileLine: View {
    let text: Text
    var identifier: String
    var actions: [(title: LocalizedStringKey, identifier: String, run: () -> Void)] = []

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            text.font(.subheadline.weight(.medium))
            if !actions.isEmpty {
                HStack(spacing: 16) {
                    ForEach(actions.indices, id: \.self) { index in
                        Button(actions[index].title, action: actions[index].run)
                            .font(.subheadline.weight(.semibold)).foregroundStyle(Color.primary).underline().frame(
                                minHeight: 44
                            ).contentShape(.rect)
                            .accessibilityIdentifier(actions[index].identifier)
                    }
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 16).padding(.vertical, 8)
        .background(Color.primary.opacity(0.07))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier(identifier)
    }
}

/// The bar row while a file is open: back to the tree, View diff where the Changed mode exists, Edit and the menu.
struct FileViewerBarRow: View {
    let model: FilesEverythingModel
    let document: FileDocumentModel

    var body: some View {
        HStack(spacing: 8) {
            Button {
                Task { await model.closeFile() }
            } label: {
                Label("files.viewer.back", systemImage: "chevron.left").font(.subheadline.weight(.semibold))
                    .padding(.horizontal, 10).frame(minHeight: 44).contentShape(.rect)
            }
            .buttonStyle(.plain).accessibilityIdentifier("files.viewer.back")
            Spacer(minLength: 0)
            if document.canViewDiff {
                Button {
                    model.thread.files.requestedDiffPath = document.path
                } label: {
                    Text("files.viewer.diff").font(.subheadline.weight(.semibold)).foregroundStyle(Color.primary)
                        .underline()
                        .padding(.horizontal, 10)
                        .frame(minHeight: 44).contentShape(.rect)
                }
                .accessibilityIdentifier("files.viewer.diff")
            }
            if document.canEdit, model.canDrive {
                Button {
                    Task { _ = await document.edit() }
                } label: {
                    Text("files.viewer.edit").font(.subheadline.weight(.semibold)).padding(.horizontal, 16)
                        .frame(minHeight: 44).background(Color.primary.opacity(0.12), in: .capsule)
                }
                .buttonStyle(.plain).accessibilityIdentifier("files.viewer.edit")
            }
            Menu {
                Button {
                    document.copyFile()
                } label: {
                    Label("files.viewer.copy", systemImage: "doc.on.doc")
                }
                .disabled(document.viewedText == nil)
                Toggle(
                    "files.viewer.wrap", isOn: Binding(get: { document.wrapsLines }, set: { document.wrapsLines = $0 }))
            } label: {
                Image(systemName: "ellipsis.circle").foregroundStyle(Color.primary).frame(width: 44, height: 44)
                    .contentShape(.rect)
            }
            .accessibilityLabel("files.viewer.menu").accessibilityIdentifier("files.viewer.menu")
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("files.viewer.bar")
    }
}
