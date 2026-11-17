import SwiftUI
import YakKit

/// The shell counter and the menu above it: one row per shell, a close action per row and New shell.
struct ShellMenu: View {
    let model: TerminalPaneModel

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
                    model.requestClose(entry)
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
                .background(Color(.secondarySystemBackground), in: .rect(cornerRadius: 12))
                .contentShape(.rect)
        }
        .accessibilityLabel("terminal.shells.label \(model.shells.shells.count)")
        .accessibilityIdentifier("terminal.key.shells")
    }
}
