import SwiftUI
import UIKit
import YakKit

/// The conversation: its rows in a lazy stack that starts at the bottom and follows new rows while the person is
/// reading the end. Someone who has scrolled up keeps their place, and a button takes them back.
struct ChatView: View {
    let thread: ThreadModel
    let openThread: (String) -> Void

    @State private var position = ScrollPosition(edge: .bottom)
    @State private var isNearBottom = true
    @State private var isHistoryVisible = false
    @State private var visibleRowIDs: [String] = []
    /// The row at the top of the screen when earlier messages started loading; it stays there when they arrive.
    @State private var anchorRowID: String?
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private static let stickDistance: CGFloat = 80

    private struct Metrics: Equatable {
        var contentHeight: CGFloat
        var distanceFromBottom: CGFloat
    }

    private struct HistoryTrigger: Equatable {
        var isVisible: Bool
        var state: HistoryState
    }

    /// A one point strip above and below the list keeps it from reaching under the bars, so no line of text is
    /// ever half hidden behind their glass.
    var body: some View {
        VStack(spacing: 0) {
            Color.clear.frame(height: 1).accessibilityHidden(true)
            list
            Color.clear.frame(height: 1).accessibilityHidden(true)
        }
    }

    private var list: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 14) {
                if thread.historyState != .hidden {
                    HistoryRow(state: thread.historyState, retry: { Task { await thread.loadOlder() } })
                        .onAppear { isHistoryVisible = true }
                        .onDisappear { isHistoryVisible = false }
                }
                ForEach(thread.rows) { row in
                    ChatRowView(row: row, thread: thread, openThread: openThread).id(row.id)
                }
                if let notice = thread.notice {
                    NoticeCard(notice: notice, thread: thread)
                }
            }
            .scrollTargetLayout()
            .padding(.horizontal, 16).padding(.vertical, 12)
            .frame(maxWidth: 720).frame(maxWidth: .infinity)
            .animation(reduceMotion ? nil : .smooth(duration: 0.2), value: thread.rows.count)
        }
        .accessibilityIdentifier("chat.list")
        .scrollPosition($position)
        .defaultScrollAnchor(.bottom)
        .onScrollTargetVisibilityChange(idType: String.self, threshold: 0.1) { visibleRowIDs = $0 }
        .onScrollGeometryChange(for: Metrics.self) { geometry in
            Metrics(
                contentHeight: geometry.contentSize.height,
                distanceFromBottom: max(0, geometry.contentSize.height - geometry.visibleRect.maxY))
        } action: { old, new in
            follow(from: old, to: new)
        }
        .task(id: HistoryTrigger(isVisible: isHistoryVisible, state: thread.historyState)) {
            if isHistoryVisible, thread.historyState == .available { await thread.loadOlder() }
        }
        .onChange(of: thread.historyState) { old, new in keepPlace(from: old, to: new) }
        .overlay { if thread.rows.isEmpty, thread.notice == nil, thread.snapshot?.load == .ready { EmptyChat() } }
        .overlay(alignment: .bottom) { floatingControls }
        .onChange(of: thread.announcementSerial) {
            if let text = thread.announcement { UIAccessibility.post(notification: .announcement, argument: text) }
        }
        .sensoryFeedback(.warning, trigger: thread.actionLine) { _, new in new != nil }
    }

    // MARK: Scrolling

    /// New content keeps the end in view for someone who is at the end; for anyone else nothing moves.
    private func follow(from old: Metrics, to new: Metrics) {
        guard new.contentHeight != old.contentHeight else {
            isNearBottom = new.distanceFromBottom <= Self.stickDistance
            return
        }
        guard anchorRowID == nil else { return }
        if old.distanceFromBottom <= Self.stickDistance {
            position.scrollTo(edge: .bottom)
            isNearBottom = true
        }
    }

    private func keepPlace(from old: HistoryState, to new: HistoryState) {
        if new == .loading {
            let order = Dictionary(uniqueKeysWithValues: thread.rows.enumerated().map { ($1.id, $0) })
            anchorRowID = visibleRowIDs.min { (order[$0] ?? .max) < (order[$1] ?? .max) }
        } else if old == .loading, let anchor = anchorRowID {
            anchorRowID = nil
            position.scrollTo(id: anchor, anchor: .top)
        }
    }

    private func jumpToLatest() {
        withAnimation(reduceMotion ? nil : .smooth) { position.scrollTo(edge: .bottom) }
        isNearBottom = true
    }

    // MARK: Floating controls

    @ViewBuilder private var floatingControls: some View {
        VStack(spacing: 8) {
            if let line = thread.actionLine {
                Text(line)
                    .font(.subheadline).multilineTextAlignment(.center)
                    .padding(.horizontal, 14).padding(.vertical, 10)
                    .background(Color(.secondarySystemBackground), in: .rect(cornerRadius: 16))
                    .overlay(RoundedRectangle(cornerRadius: 16).stroke(Color.primary.opacity(0.12), lineWidth: 0.5))
                    .transition(.opacity)
                    .accessibilityAddTraits(.updatesFrequently)
                    .accessibilityIdentifier("chat.actionLine")
                    .task(id: line) {
                        UIAccessibility.post(notification: .announcement, argument: line)
                    }
            }
            if !isNearBottom {
                Button(action: jumpToLatest) {
                    Label("chat.jumpToLatest", systemImage: "arrow.down")
                        .font(.subheadline.weight(.semibold))
                        .padding(.horizontal, 16).frame(minHeight: 44)
                        .background(Color(.secondarySystemBackground), in: .capsule)
                        .overlay(Capsule().stroke(Color.primary.opacity(0.12), lineWidth: 0.5))
                        .foregroundStyle(.primary)
                }
                .buttonStyle(.plain)
                .accessibilityIdentifier("chat.jumpToLatest")
            }
        }
        .padding(.horizontal, 16).padding(.bottom, 8)
        .animation(reduceMotion ? nil : .smooth(duration: 0.2), value: isNearBottom)
    }
}

/// The top of the list while there are earlier messages: a line that says they are loading, or that they could not be.
private struct HistoryRow: View {
    let state: HistoryState
    let retry: () -> Void

    var body: some View {
        Group {
            if state == .failed {
                VStack(spacing: 8) {
                    Text("chat.history.failed").font(.subheadline).foregroundStyle(Color.yakMuted)
                        .multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
                    Button(action: retry) { Text("common.retry").fixedSize(horizontal: false, vertical: true) }
                        .buttonStyle(.bordered).tint(.primary).controlSize(.large)
                        .accessibilityIdentifier("chat.history.retry")
                }
            } else {
                HStack(spacing: 8) {
                    ProgressView().controlSize(.small).accessibilityHidden(true)
                    Text("chat.history.loading").font(.subheadline).foregroundStyle(Color.yakMuted)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
        }
        .frame(maxWidth: .infinity).padding(.vertical, 8)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("chat.history")
    }
}

/// A ready thread with nothing said yet.
private struct EmptyChat: View {
    var body: some View {
        VStack(spacing: 6) {
            Text("chat.empty.title").font(.headline)
            Text("chat.empty.body").font(.subheadline).foregroundStyle(Color.yakMuted)
        }
        .multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
        .padding(24).frame(maxWidth: 420)
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("chat.empty")
    }
}
