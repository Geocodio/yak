import SwiftUI
import YakKit

/// One sub-thread: a glyph, the title and its detail, and a menu when it offers more than Open.
struct SubThreadRowView: View {
    let row: SubThreadRow
    let model: OverviewModel
    let askToDrop: (SubThreadRow) -> Void

    @Environment(\.hasSeveralWindows) private var supportsMultipleWindows
    @Environment(SceneModel.self) private var scene

    /// "Open in new window" is part of the menu whenever the system has several windows.
    private var hasMenu: Bool { row.actions.count > 1 || (supportsMultipleWindows && scene.activeInstance != nil) }

    var body: some View {
        HStack(spacing: 0) {
            Button {
                model.open(row.id)
            } label: {
                HStack(alignment: .top, spacing: 12) {
                    glyph.frame(width: 24, height: 24).padding(.top, 2).accessibilityHidden(true)
                    VStack(alignment: .leading, spacing: 2) {
                        titleLine
                        detailLine.font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(.leading, 16).padding(.vertical, 10).frame(minHeight: 44).contentShape(.rect)
            }
            .buttonStyle(.plain)
            .accessibilityElement(children: .combine)
            .accessibilityIdentifier("overview.child.\(row.id)")
            if hasMenu { menu }
        }
        .padding(.trailing, hasMenu ? 0 : 16)
    }

    private var titleLine: some View {
        VStack(alignment: .leading, spacing: 0) {
            if row.kind == .research {
                Text("overview.child.research").font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
            }
            Text(row.title).font(.yak(.body)).fixedSize(horizontal: false, vertical: true)
        }
    }

    @ViewBuilder private var glyph: some View {
        switch row.state {
        case .working: ProgressView().controlSize(.small)
        case .waiting: Image(systemName: "clock").foregroundStyle(Color.yakMuted)
        case .queued: Image(systemName: "circle.dotted").foregroundStyle(Color.yakMuted)
        case .done: Image(systemName: "checkmark.circle").foregroundStyle(Color.yakMuted)
        case .failed: Image(systemName: "exclamationmark.circle").foregroundStyle(Color.yakWarning)
        case .closed: Image(systemName: "slash.circle").foregroundStyle(Color.yakMuted)
        default: Circle().fill(Color(.systemGray2)).frame(width: 8, height: 8)
        }
    }

    private var detailLine: Text {
        let base: Text
        switch row.detail {
        case .step(let text), .result(let text): base = Text(text)
        case .queued: base = Text("overview.child.waitingSlot")
        case .messagesPaused: base = Text("overview.child.paused")
        case .takenOver: base = Text("overview.child.takenOver")
        case .state(let state):
            switch state {
            case .working: base = Text("overview.child.working")
            case .waiting: base = Text("overview.child.waiting")
            case .queued: base = Text("overview.child.queued")
            case .done: base = Text("overview.child.done")
            case .failed: base = Text("overview.child.failed")
            case .closed: base = Text("overview.child.closed")
            default: base = Text("overview.child.other \(state.rawValue)")
            }
        }
        guard let files = row.filesChanged, files > 0 else { return base }
        return Text("overview.child.detailWithFiles \(base) \(Text("overview.child.files \(files)"))")
    }

    private var menu: some View {
        let isRunning = model.runningAction == .override(row.id)
        return Menu {
            if row.actions.contains(.takeOver) {
                Button {
                    Task { await model.perform(.takeOver, on: row.id) }
                } label: {
                    Text("overview.child.takeOver")
                    Text("overview.child.takeOver.subtitle")
                }
            }
            if row.actions.contains(.split) {
                Button {
                    Task { await model.perform(.split, on: row.id) }
                } label: {
                    Text("overview.child.split")
                }
            }
            if let instance = scene.activeInstance {
                OpenInNewWindowButton(threadID: row.id, instanceID: instance)
            }
            if row.actions.contains(.drop) {
                Button(role: .destructive) {
                    askToDrop(row)
                } label: {
                    Text("overview.child.drop")
                }
            }
        } label: {
            Group {
                if isRunning { ProgressView() } else { Image(systemName: "ellipsis.circle") }
            }
            .frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .tint(.primary)
        .disabled(model.isOffline || model.runningAction != nil)
        .accessibilityLabel(Text("overview.child.menu \(row.title)"))
        .accessibilityHint(model.isOffline ? Text("overview.notOffline") : Text(""))
        .accessibilityIdentifier("overview.child.\(row.id).menu")
    }
}
