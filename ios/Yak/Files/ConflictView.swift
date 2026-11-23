import SwiftUI
import YakKit

/// The compare after a save met a newer version: the person's text and the other one, with the lines that differ
/// tinted, and what to do about it.
struct ConflictView: View {
    let model: FileDocumentModel
    let theirs: FileBody?
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var showsTheirs = false
    @State private var confirmsTheirs = false

    private var theirsText: String { theirs?.content ?? "" }

    var body: some View {
        VStack(spacing: 12) {
            Text(theirs == nil ? "files.conflict.deleted" : "files.conflict.title").font(.yak(.headline))
                .multilineTextAlignment(.center).padding(.horizontal, 16).padding(.top, 16)
            if theirs != nil { compare }
            actions
        }
        .background(.yakPage)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("files.conflict")
        .confirmationDialog("files.conflict.theirsConfirm", isPresented: $confirmsTheirs, titleVisibility: .visible) {
            Button("files.conflict.useTheirs", role: .destructive) { Task { await model.useTheirs() } }
        } message: {
            Text("files.conflict.theirsDetail")
        }
    }

    @ViewBuilder private var compare: some View {
        if sizeClass == .regular {
            HStack(spacing: 8) {
                pane(title: "files.conflict.yours", text: model.text, other: theirsText)
                pane(title: "files.conflict.theirs", text: theirsText, other: model.text)
            }
        } else {
            Picker("files.conflict.pick", selection: $showsTheirs) {
                Text("files.conflict.yours").tag(false)
                Text("files.conflict.theirs").tag(true)
            }
            .pickerStyle(.segmented).padding(.horizontal, 16).accessibilityIdentifier("files.conflict.pick")
            if showsTheirs {
                pane(title: nil, text: theirsText, other: model.text)
            } else {
                pane(title: nil, text: model.text, other: theirsText)
            }
        }
    }

    private func pane(title: LocalizedStringKey?, text: String, other: String) -> some View {
        VStack(spacing: 4) {
            if let title { Text(title).font(.yak(.subheadline, .semibold)) }
            CodeTextView(
                text: text, tintedLines: ChangedLines.indexes(in: text, comparedWith: other),
                accessibilityIdentifier: "files.conflict.text", accessibilityLabel: "files.conflict.text.label")
        }
    }

    private var actions: some View {
        VStack(spacing: 8) {
            Button {
                Task { await model.keepMine() }
            } label: {
                label(theirs == nil ? "files.conflict.createAgain" : "files.conflict.keepMine")
            }
            .accessibilityIdentifier("files.conflict.keepMine")
            Button {
                if theirs == nil { Task { await model.useTheirs() } } else { confirmsTheirs = true }
            } label: {
                label("files.conflict.useTheirs")
            }
            .accessibilityIdentifier("files.conflict.useTheirs")
            Button("files.conflict.copy") { model.copyText() }
                .frame(minHeight: 44).accessibilityIdentifier("files.conflict.copy")
        }
        .buttonStyle(.plain).padding(.horizontal, 16).padding(.bottom, 12)
    }

    private func label(_ key: LocalizedStringKey) -> some View {
        Text(key).font(.yak(.headline)).frame(maxWidth: .infinity, minHeight: 44)
            .background(Color.primary.opacity(0.12), in: .rect(cornerRadius: 12))
    }
}

/// Which lines of a text are not in another one.
enum ChangedLines {
    /// Zero-based line numbers of `text` that `other` does not have at the same place in a line diff.
    static func indexes(in text: String, comparedWith other: String) -> Set<Int> {
        let mine = text.components(separatedBy: "\n")
        let theirs = other.components(separatedBy: "\n")
        var result = Set<Int>()
        for change in mine.difference(from: theirs) {
            if case .insert(let offset, _, _) = change { result.insert(offset) }
        }
        return result
    }
}
