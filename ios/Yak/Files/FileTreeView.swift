import SwiftUI
import YakKit

/// The mark at the trailing side of a row: a letter on a tinted pill, or a dot for a folder, so the marks are told apart without color.
struct FileMarkView: View {
    let mark: FileMark

    var body: some View {
        HStack(spacing: 4) {
            if let letter {
                Text(verbatim: letter).font(.yak(.caption, .bold, isMonospaced: true)).foregroundStyle(Color.primary)
                    .padding(.horizontal, 5).background(color.opacity(0.25), in: .capsule)
            } else {
                Circle().fill(color).frame(width: 6, height: 6)
            }
        }
        .accessibilityHidden(true)
    }

    private var letter: String? {
        switch mark {
        case .modified: "M"
        case .added: "A"
        case .containsChanges: nil
        }
    }

    private var color: Color {
        switch mark {
        case .modified: Color(.systemOrange)
        case .added: Color(.systemGreen)
        case .containsChanges: Color.primary.opacity(0.45)
        }
    }
}

/// The tree: folders that expand in place, files that open the viewer.
struct FileTreeView: View {
    let model: FilesEverythingModel
    let snapshot: FileTreeSnapshot
    @ScaledMetric private var indentation = 22
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        let items = FileTreeDisplay.make(rows: snapshot.rows, rootState: snapshot.root, isOffline: snapshot.isOffline)
        List {
            ForEach(items) { item in
                view(for: item)
                    .listRowInsets(EdgeInsets()).listRowSeparator(.hidden)
            }
        }
        .listStyle(.plain)
        .id(dynamicTypeSize)
        .refreshableWithHaptic { await model.turnFinished() }
        .accessibilityIdentifier("files.tree")
    }

    @ViewBuilder private func view(for item: FileTreeDisplayItem) -> some View {
        switch item {
        case .row(let row): rowView(row)
        case .loading(_, let depth):
            FileSkeletonRows(count: 2).padding(.leading, CGFloat(depth) * indentation)
        case .empty(_, let depth): note("files.folder.empty", depth: depth)
        case .failed(_, let depth):
            HStack {
                Text("files.folder.failed").font(.yak(.subheadline)).foregroundStyle(Color.yakMuted)
                Button {
                    Task { await model.retry() }
                } label: {
                    Text("files.retry").font(.yak(.subheadline, .semibold)).frame(minHeight: 44).contentShape(.rect)
                }
                .accessibilityIdentifier("files.folder.retry")
            }
            .padding(.leading, CGFloat(depth) * indentation + 16).padding(.trailing, 16)
        case .notLoadedOffline(_, let depth): note("files.folder.offline", depth: depth)
        case .truncated(_, let depth): note("files.folder.truncated", depth: depth)
        }
    }

    private func note(_ key: LocalizedStringKey, depth: Int) -> some View {
        Text(key).font(.yak(.subheadline)).foregroundStyle(Color.yakMuted)
            .padding(.leading, CGFloat(depth) * indentation + 16).padding(.trailing, 16).padding(.vertical, 10)
            .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func rowView(_ row: FileTreeRow) -> some View {
        Button {
            Task {
                if row.isDirectory { await model.toggle(row.id) } else { await model.open(path: row.id, edit: false) }
            }
        } label: {
            HStack(spacing: 8) {
                if row.isDirectory {
                    Image(systemName: "chevron.right").font(.yak(.caption, .semibold))
                        .rotationEffect(.degrees(row.isExpanded ? 90 : 0)).frame(width: 16)
                } else {
                    Color.clear.frame(width: 16, height: 1)
                }
                Image(systemName: row.isDirectory ? "folder.fill" : "doc.text")
                    .foregroundStyle(row.isDirectory ? Color(.systemIndigo) : Color.yakMuted).frame(width: 24)
                Text(verbatim: row.name).font(.yak(.body, isMonospaced: true)).multilineTextAlignment(.leading)
                Spacer(minLength: 8)
                if let mark = row.mark { FileMarkView(mark: mark) }
            }
            .padding(.leading, CGFloat(row.depth) * indentation + 12).padding(.trailing, 16)
            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            .contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Self.label(of: row))
        .accessibilityAddTraits(.isButton)
        .accessibilityIdentifier("files.row.\(row.id)")
    }

    static func label(of row: FileTreeRow) -> Text {
        if row.isDirectory {
            return row.isExpanded
                ? Text("files.row.folderExpanded \(row.name)") : Text("files.row.folderCollapsed \(row.name)")
        }
        switch row.mark {
        case .modified: return Text("files.row.modified \(row.name)")
        case .added: return Text("files.row.addedFile \(row.name)")
        case .containsChanges: return Text("files.row.containsChanges \(row.name)")
        case nil: return Text("files.row.file \(row.name)")
        }
    }
}
