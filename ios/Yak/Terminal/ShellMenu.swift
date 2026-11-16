import SwiftUI
import YakKit

/// The shell counter and the menu above it: one row per shell, a close action per row and New shell.
struct ShellMenu: View {
    let model: TerminalPaneModel
    @State private var closing: ShellEntry?

    var body: some View {
        Menu {
            ForEach(model.shells.shells) { entry in
                Button {
                    model.focus(entry.id)
                } label: {
                    if entry.id == model.focusedID {
                        Label(entry.title, systemImage: "checkmark")
                    } else {
                        Text(verbatim: entry.title)
                    }
                }
                .accessibilityIdentifier("terminal.shells.row.\(entry.title)")
            }
            Divider()
            ForEach(model.shells.shells) { entry in
                Button(role: .destructive) {
                    requestClose(entry)
                } label: {
                    Label("terminal.shells.close \(entry.title)", systemImage: "xmark")
                }
                .accessibilityIdentifier("terminal.shells.close.\(entry.title)")
            }
            Divider()
            Button {
                Task { await model.newShell() }
            } label: {
                Label("terminal.shell.new", systemImage: "plus")
                if !model.shells.canAdd { Text("terminal.shells.maximum") }
            }
            .disabled(!model.shells.canAdd)
            .accessibilityIdentifier("terminal.shells.new")
        } label: {
            Text(model.shells.shells.count, format: .number).font(.subheadline.weight(.semibold).monospacedDigit())
                .frame(minWidth: 44, minHeight: 44)
                .background(Color.primary.opacity(0.1), in: .rect(cornerRadius: 12))
                .contentShape(.rect)
        }
        .accessibilityLabel("terminal.shells.label \(model.shells.shells.count)")
        .accessibilityIdentifier("terminal.key.shells")
        .confirmationDialog(
            Text("terminal.shells.confirm \(closing?.title ?? "")"),
            isPresented: Binding(get: { closing != nil }, set: { if !$0 { closing = nil } }),
            titleVisibility: .visible
        ) {
            Button("terminal.shells.closeShell", role: .destructive) {
                if let closing { Task { await model.closeShell(closing.id) } }
                closing = nil
            }
        } message: {
            Text("terminal.shells.confirmDetail")
        }
    }

    /// A shell that is not connected closes without asking; a connected one stops what runs in it.
    private func requestClose(_ entry: ShellEntry) {
        if model.snapshots[entry.id]?.phase == .connected {
            closing = entry
        } else {
            Task { await model.closeShell(entry.id) }
        }
    }
}
