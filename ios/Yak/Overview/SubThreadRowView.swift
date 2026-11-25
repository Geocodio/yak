import SwiftUI
import YakKit

/// One sub-thread: a mark and its name, `Kind · repository · State` under it, why it waits or what it did, and a menu
/// when it offers more than Open. A waiting sub-thread sits on a tinted row and may offer "Start now".
struct SubThreadRowView: View {
    let row: SubThreadRow
    let model: OverviewModel
    let askToDrop: (SubThreadRow) -> Void

    @Environment(\.hasSeveralWindows) private var supportsMultipleWindows
    @Environment(SceneModel.self) private var scene
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    /// "Open in new window" is part of the menu whenever the system has several windows.
    private var hasMenu: Bool { row.actions.count > 1 || (supportsMultipleWindows && scene.activeInstance != nil) }
    private var isWaiting: Bool { row.style.word == .waiting }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                Button {
                    model.open(row.id)
                } label: {
                    HStack(alignment: .top, spacing: Design.Space.compact) {
                        SubThreadMark(style: row.style).padding(.top, Design.Space.small)
                        VStack(alignment: .leading, spacing: Design.Space.nudge) {
                            Text(row.name).font(.yak(.body, .medium)).fixedSize(horizontal: false, vertical: true)
                            Text(line).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                                .fixedSize(horizontal: false, vertical: true)
                            detailText
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .id(dynamicTypeSize)
                    .padding(.leading, Design.Space.rowInset).padding(.vertical, Design.Space.snug)
                    .frame(minHeight: Design.Size.control).contentShape(.rect)
                }
                .buttonStyle(.plain)
                .accessibilityElement(children: .combine)
                .accessibilityIdentifier("overview.child.\(row.id)")
                if hasMenu { menu }
            }
            if row.startNowLimit != nil, let limit = model.startNowLimit { startNow(limit) }
        }
        .padding(.trailing, hasMenu ? 0 : Design.Space.rowInset)
        .background(isWaiting ? Color.yakBubble : Color.clear)
    }

    /// `Kind · repository · State`, with "messages paused" after it.
    private var line: String {
        var parts = [row.kind.text]
        if let repo = row.repo { parts.append(repo) }
        parts.append(row.style.word.text)
        if row.messagesPaused { parts.append(String(localized: "subThread.messagesPaused")) }
        return parts.joined(separator: " \u{00B7} ")
    }

    @ViewBuilder private var detailText: some View {
        switch row.detail {
        case .step(let text), .result(let text):
            Text(text).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
        case .waitingForSpot(let running):
            Text(waitingReason(running: running)).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
        case nil:
            EmptyView()
        }
    }

    private func waitingReason(running: Int) -> String {
        switch running {
        case ..<1: String(localized: "subThread.waiting.moment")
        case 1: String(localized: "subThread.waiting.one")
        default: String(localized: "subThread.waiting.many \(running)")
        }
    }

    /// "Start now and allow 3 at a time": raises the limit by one, as the web's button does.
    private func startNow(_ limit: Int) -> some View {
        HStack(spacing: 0) {
            Button {
                Task { await model.startNow() }
            } label: {
                Text("subThread.startNow \(limit)").font(.yak(.subheadline, .medium))
                    .fixedSize(horizontal: false, vertical: true)
                    .padding(.horizontal, Design.Space.medium).padding(.vertical, Design.Space.snug)
                    .frame(minHeight: Design.Size.control)
                    .background(Color.yakCard, in: .rect(cornerRadius: Design.Radius.control))
                    .overlay(
                        RoundedRectangle(cornerRadius: Design.Radius.control)
                            .strokeBorder(Color.yakBorderStrong, lineWidth: Design.Space.line)
                    )
                    .contentShape(.rect)
            }
            .buttonStyle(.plain).foregroundStyle(.primary)
            .disabled(model.isOffline || model.runningAction != nil)
            .accessibilityHint(model.isOffline ? Text("overview.notOffline") : Text(""))
            .accessibilityIdentifier("overview.child.\(row.id).startNow")
            Spacer(minLength: 0)
        }
        .padding(.leading, Design.Space.rowInset + Design.Size.statusDot + Design.Space.compact)
        .padding(.trailing, Design.Space.rowInset).padding(.bottom, Design.Space.snug)
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
                if isRunning { ProgressView() } else { Image(systemName: "ellipsis") }
            }
            .frame(minWidth: Design.Size.control, minHeight: Design.Size.control).contentShape(.rect)
        }
        .tint(.primary)
        .disabled(model.isOffline || model.runningAction != nil)
        .accessibilityLabel(Text("overview.child.menu \(row.name)"))
        .accessibilityHint(model.isOffline ? Text("overview.notOffline") : Text(""))
        .accessibilityIdentifier("overview.child.\(row.id).menu")
    }
}
