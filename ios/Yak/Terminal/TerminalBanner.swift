import SwiftUI
import YakKit

/// A line over the bottom of the output for the phases in which the output stays visible.
struct TerminalBanner: View {
    let model: TerminalPaneModel
    let snapshot: TerminalSnapshot

    private struct Line {
        var text: Text
        var action: (title: LocalizedStringKey, run: () -> Void)?
    }

    var body: some View {
        if let line = self.line {
            VStack(alignment: .leading, spacing: 8) {
                line.text.font(.subheadline.weight(.medium))
                if snapshot.droppedKeys, snapshot.phase == .offline {
                    Text("terminal.banner.droppedKeys").font(.subheadline.weight(.medium))
                        .foregroundStyle(Color.terminalMuted)
                }
                if let action = line.action {
                    Button(action.title, action: action.run).buttonStyle(TerminalActionStyle())
                        .accessibilityIdentifier("terminal.banner.action")
                }
            }
            .foregroundStyle(Color.terminalText)
            .frame(maxWidth: .infinity, alignment: .leading).padding(14)
            .background(Color(red: 0.17, green: 0.17, blue: 0.19), in: .rect(cornerRadius: 16))
            .padding(.horizontal, 12).padding(.bottom, 8)
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("terminal.banner")
        }
    }

    private func newShell() { Task { await model.newShell() } }

    private var line: Line? {
        switch snapshot.phase {
        case .lost(let attempt, let retryAt):
            return Line(
                text: Text("terminal.banner.lost \(Int(max(0, retryAt.timeIntervalSinceNow.rounded(.up)))) \(attempt)"),
                action: ("terminal.banner.reconnect", { Task { await model.retry() } }))
        case .couldNotReconnect:
            return Line(text: Text("terminal.banner.couldNotReconnect"), action: ("terminal.shell.new", newShell))
        case .offline:
            return Line(text: Text("terminal.banner.offline"))
        case .ended(let code):
            return Line(
                text: code.map { Text("terminal.banner.endedCode \($0)") } ?? Text("terminal.banner.ended"),
                action: ("terminal.shell.new", newShell))
        case .idleClosed:
            return Line(text: Text("terminal.banner.idle"), action: ("terminal.shell.new", newShell))
        case .tooMany:
            return Line(
                text: Text("terminal.banner.tooMany"),
                action: ("terminal.banner.showShells", { model.isShowingShells = true }))
        case .denied:
            return Line(text: Text("terminal.banner.denied"), action: ("terminal.shell.new", newShell))
        case .broken:
            return Line(text: Text("terminal.banner.broken"), action: ("terminal.shell.new", newShell))
        case .opening, .waitingForSlot, .restoring:
            return model.hasConnectedOnce ? Line(text: Text("terminal.banner.reconnecting")) : nil
        default: return nil
        }
    }
}
