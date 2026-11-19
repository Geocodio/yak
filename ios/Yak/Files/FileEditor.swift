import SwiftUI
import UIKit
import YakKit

/// The editor: Cancel, the name and Save on top, the lines that say what happened, the text and, over the keyboard,
/// the code keys. A save that met a newer version opens the compare.
struct FileEditor: View {
    let model: FilesEverythingModel
    let document: FileDocumentModel
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.isPaneShown) private var isShown
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var keyboard = HardwareKeyboardMonitor()
    @State private var confirmsDiscard = false
    @State private var isSoftwareKeyboardShown = false
    @State private var dismissedConflict = false

    private var edit: EditPhase { document.snapshot.edit }
    private var theirs: FileBody?? {
        if case .conflict(let theirs) = edit { return .some(theirs) }
        return nil
    }
    private var isSaved: Bool {
        if case .saved = edit { return true }
        return false
    }
    private var failure: SaveProblem? {
        if case .saveFailed(let problem) = edit { return problem }
        return nil
    }

    var body: some View {
        let isRegular = sizeClass == .regular
        HStack(spacing: 0) {
            VStack(spacing: 0) {
                topRow
                lines
                CodeTextView(
                    text: document.text, isEditable: true, wrapsLines: document.wrapsLines,
                    showsKeyRow: !keyboard.isAttached || isSoftwareKeyboardShown,
                    accessibilityIdentifier: "files.editor",
                    accessibilityLabel: "files.editor.label", onChange: { document.setText($0) })
            }
            if isRegular, let theirs {
                Divider()
                ConflictView(model: document, theirs: theirs).frame(maxWidth: 560)
            }
        }
        .background(.yakPage)
        .sheet(
            isPresented: Binding(
                get: { !isRegular && theirs != nil && !dismissedConflict }, set: { dismissedConflict = !$0 })
        ) {
            if let theirs { ConflictView(model: document, theirs: theirs).presentationDetents([.large]) }
        }
        .onChange(of: theirs != nil) { dismissedConflict = false }
        .confirmationDialog("files.editor.discardTitle", isPresented: $confirmsDiscard, titleVisibility: .visible) {
            Button("files.editor.discard", role: .destructive) { Task { await document.discard() } }
            Button("files.editor.keepEditing", role: .cancel) {}
        }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillShowNotification)) { note in
            let frame = (note.userInfo?[UIResponder.keyboardFrameEndUserInfoKey] as? CGRect) ?? .zero
            isSoftwareKeyboardShown = frame.height > 120
        }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillHideNotification)) { _ in
            isSoftwareKeyboardShown = false
        }
        .onChange(of: scenePhase) { _, phase in Task { await document.scenePhaseChanged(phase) } }
        .onChange(of: isShown) { _, shown in if !shown { Task { await document.flushDraft() } } }
        .task(id: isSaved) {
            guard isSaved else { return }
            do { try await Task.sleep(for: .seconds(2.5)) } catch { return }
            if case .saved = document.snapshot.edit { await document.discard() }
        }
        .sensoryFeedback(.impact(weight: .light), trigger: isSaved) { _, saved in saved }
        .sensoryFeedback(.error, trigger: failure != nil) { _, failed in failed }
        .background {
            if isShown {
                Button("shortcut.save") { Task { await document.save() } }
                    .yakShortcut(.save).frame(width: 0, height: 0).opacity(0).accessibilityHidden(true)
            }
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("files.editorScreen")
    }

    private var topRow: some View {
        HStack(spacing: 8) {
            Button("files.editor.cancel") {
                Task { if await !document.cancel() { confirmsDiscard = true } }
            }
            .font(.body.weight(.medium)).foregroundStyle(Color.yakAction).padding(.horizontal, 8).frame(minHeight: 44)
            .accessibilityIdentifier("files.editor.cancel")
            Text(verbatim: (document.path as NSString).lastPathComponent).font(.headline.monospaced()).lineLimit(1)
                .truncationMode(.middle).frame(maxWidth: .infinity).accessibilityLabel(
                    Text("files.editor.title \((document.path as NSString).lastPathComponent)")
                )
                .accessibilityAddTraits(.isHeader)
            Button {
                Task { await document.save() }
            } label: {
                HStack(spacing: 6) {
                    if document.isSaving { ProgressView() }
                    Text("files.editor.save").font(.body.weight(.semibold))
                }
                .padding(.horizontal, 16).frame(minHeight: 44)
                .background(Color.primary.opacity(canSave ? 0.12 : 0.05), in: .capsule)
            }
            .buttonStyle(.plain).disabled(!canSave && !document.isSaving).accessibilityIdentifier("files.editor.save")
        }
        .padding(.horizontal, 8)
    }

    private var canSave: Bool { document.isDirty && !document.isSaving && !document.isTooLargeToSave }

    @ViewBuilder private var lines: some View {
        if document.snapshot.restoredDraft {
            FileLine(text: Text("files.editor.restored"), identifier: "files.editor.restoredLine")
        }
        if document.snapshot.changedOnServer {
            FileLine(text: Text("files.editor.changedOnServer"), identifier: "files.editor.changedLine")
        }
        if document.isTooLargeToSave, failure != .tooLarge {
            FileLine(
                text: Text("files.save.tooLarge"), identifier: "files.editor.tooLargeLine",
                actions: [("files.save.copyText", "files.editor.copy", { document.copyText() })])
        }
        if case .saved(let isWorking) = edit {
            FileLine(
                text: isWorking ? Text("files.save.savedWorking") : Text("files.save.saved"),
                identifier: "files.editor.savedLine")
        }
        if let failure { failureLine(failure) }
    }

    private func failureLine(_ problem: SaveProblem) -> some View {
        let again: [(title: LocalizedStringKey, identifier: String, run: () -> Void)] = [
            ("files.save.again", "files.editor.again", { Task { await document.save() } })
        ]
        let copy: [(title: LocalizedStringKey, identifier: String, run: () -> Void)] = [
            ("files.save.copyText", "files.editor.copy", { document.copyText() })
        ]
        switch problem {
        case .offline:
            return FileLine(text: Text("files.save.offline"), identifier: "files.editor.failedLine", actions: again)
        case .threadClosed:
            return FileLine(text: Text("files.save.closed"), identifier: "files.editor.failedLine", actions: copy)
        case .forbidden:
            return FileLine(text: Text("files.save.forbidden"), identifier: "files.editor.failedLine")
        case .tooLarge:
            return FileLine(text: Text("files.save.tooLarge"), identifier: "files.editor.failedLine", actions: copy)
        case .unavailable(let availability):
            return FileLine(
                text: Text(WorkspaceStateContent.sentence(for: availability)), identifier: "files.editor.failedLine",
                actions: again)
        case .invalid(let message):
            return FileLine(text: Text(verbatim: message), identifier: "files.editor.failedLine")
        case .server(let message):
            return FileLine(
                text: message.isEmpty ? Text("files.save.failed") : Text(verbatim: message),
                identifier: "files.editor.failedLine", actions: again)
        }
    }
}
