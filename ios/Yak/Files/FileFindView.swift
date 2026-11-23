import SwiftUI
import YakKit

/// The find field in the bar. Typing shows results in place of the tree; Return opens the first one.
struct FindField: View {
    let model: FilesEverythingModel
    @FocusState private var isFocused: Bool

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: "magnifyingglass").foregroundStyle(Color.yakMuted).accessibilityHidden(true)
            TextField(
                "files.find.placeholder",
                text: Binding(get: { model.findText }, set: { model.setFindText($0) })
            )
            .textInputAutocapitalization(.never).autocorrectionDisabled().submitLabel(.go)
            .focused($isFocused)
            .onSubmit {
                if let path = model.firstResultPath { Task { await model.open(path: path, edit: false) } }
            }
            .accessibilityIdentifier("files.find")
            if !model.findText.isEmpty {
                Button {
                    model.setFindText("")
                } label: {
                    Image(systemName: "xmark.circle.fill").foregroundStyle(Color.yakMuted)
                        .frame(width: 44, height: 44).contentShape(.rect)
                }
                .buttonStyle(.plain).accessibilityLabel("files.find.clear").accessibilityIdentifier("files.find.clear")
            }
        }
        .padding(.leading, 12).frame(minHeight: 44)
        .background(Color.primary.opacity(0.08), in: .rect(cornerRadius: 12))
        .background {
            Button("shortcut.findFile") { isFocused = true }
                .yakShortcut(.findFile).frame(width: 0, height: 0).opacity(0).accessibilityHidden(true)
        }
    }
}

/// Results in place of the tree.
struct FileFindView: View {
    let model: FilesEverythingModel

    var body: some View {
        Group {
            switch model.find {
            case .idle, .searching:
                VStack { ProgressView().padding(.top, 40) }.frame(
                    maxWidth: .infinity, maxHeight: .infinity, alignment: .top
                )
                .accessibilityIdentifier("files.find.searching")
            case .results(let results, let truncated, let isPartial):
                List {
                    ForEach(results) { result in resultRow(result) }.listRowInsets(EdgeInsets())
                    if truncated { Text("files.find.truncated").font(.yak(.footnote)).foregroundStyle(Color.yakMuted) }
                    if isPartial { Text("files.find.partial").font(.yak(.footnote)).foregroundStyle(Color.yakMuted) }
                }
                .listStyle(.plain)
            case .noMatch(let query, let isPartial):
                VStack(spacing: 8) {
                    Text("files.find.none \(query)").font(.yak(.body)).multilineTextAlignment(.center)
                    if isPartial { Text("files.find.partial").font(.yak(.footnote)).foregroundStyle(Color.yakMuted) }
                }
                .padding(24).frame(maxWidth: .infinity, maxHeight: .infinity)
            case .failed:
                VStack(spacing: 8) {
                    Text("files.find.failed").multilineTextAlignment(.center)
                    Button("files.retry") { model.retryFind() }.frame(minHeight: 44)
                }
                .padding(24).frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .accessibilityIdentifier("files.find.results")
    }

    private func resultRow(_ result: FileFindResult) -> some View {
        let folder = (result.path as NSString).deletingLastPathComponent
        return Button {
            Task { await model.open(path: result.path, edit: false) }
        } label: {
            HStack(spacing: 8) {
                Image(systemName: "doc.text").foregroundStyle(Color.yakMuted).frame(width: 24)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: result.name).font(.yak(.body, isMonospaced: true))
                    if !folder.isEmpty { Text(verbatim: folder).font(.yak(.caption)).foregroundStyle(Color.yakMuted) }
                }
                Spacer(minLength: 8)
                if let mark = result.mark { FileMarkView(mark: mark) }
            }
            .padding(.horizontal, 16).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            .contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text(verbatim: "\(result.name), \(folder)"))
        .accessibilityAddTraits(.isButton)
        .accessibilityIdentifier("files.result.\(result.path)")
    }
}
