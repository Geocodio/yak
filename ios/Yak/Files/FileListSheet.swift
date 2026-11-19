import SwiftUI
import YakKit

/// Every file of the changes, grouped by repository when there is more than one. A tap shows that file.
struct FileListSheet: View {
    let model: FilesModel
    let close: () -> Void

    private var files: [DiffFileSummary] { model.files }
    private var marks: ViewedMarks { model.snapshot?.marks ?? ViewedMarks() }
    private var repositories: [String] {
        var seen: [String] = []
        for file in files where !seen.contains(file.repo) { seen.append(file.repo) }
        return seen
    }

    var body: some View {
        NavigationStack {
            List {
                ForEach(repositories, id: \.self) { repo in
                    Section {
                        ForEach(files.filter { $0.repo == repo }) { row($0) }
                    } header: {
                        if repositories.count > 1 { Text(verbatim: repo) }
                    }
                }
            }
            .navigationTitle(Text("files.list.header \(files.count) \(marks.viewedCount(in: files))"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("common.done", action: close).accessibilityIdentifier("files.list.done")
                }
                .sharedBackgroundVisibility(.hidden)
            }
        }
        .presentationDetents([.medium, .large])
        .presentationBackground(Color.yakBackground)
        .accessibilityIdentifier("files.list.sheet")
    }

    private func row(_ file: DiffFileSummary) -> some View {
        Button {
            Task { await model.show(file.key) }
            close()
        } label: {
            HStack(alignment: .firstTextBaseline, spacing: 10) {
                Text(verbatim: letter(file.status)).font(.footnote.monospaced().weight(.semibold))
                    .foregroundStyle(Color.yakMuted).frame(width: 18).accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: (file.path as NSString).lastPathComponent).font(.body.monospaced())
                        .fixedSize(horizontal: false, vertical: true)
                    let folder = (file.path as NSString).deletingLastPathComponent
                    if !folder.isEmpty {
                        Text(verbatim: folder).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
                Spacer(minLength: 8)
                if let additions = file.additions, let deletions = file.deletions {
                    Text(verbatim: "+\(additions) \u{2212}\(deletions)").font(
                        .footnote.monospacedDigit().weight(.medium)
                    )
                    .foregroundStyle(Color.yakMuted).fixedSize()
                }
                if marks.isViewed(file) {
                    Image(systemName: "checkmark").font(.footnote.weight(.semibold)).accessibilityHidden(true)
                }
            }
            .frame(minHeight: 44)
        }
        .foregroundStyle(.primary)
        .listRowBackground(file.key == model.currentKey ? Color.primary.opacity(0.1) : nil)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text(verbatim: file.path))
        .accessibilityValue(Text(marks.isViewed(file) ? "files.viewed.on" : ""))
        .accessibilityIdentifier("files.list.row")
    }

    private func letter(_ status: DiffFileStatus) -> String {
        switch status {
        case .added: "A"
        case .deleted: "D"
        case .renamed: "R"
        default: "M"
        }
    }
}
