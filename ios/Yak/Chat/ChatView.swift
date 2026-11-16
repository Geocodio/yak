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
    /// The history row came into view and has not asked for a page yet. A page is asked for once per arrival: the
    /// row's disappearance can reach this view late, after a page has pushed it far above, and that would ask for
    /// every page in a row.
    @State private var isHistoryVisible = false
    /// The rows when a page of earlier messages started loading. When there are more and the last one is the same,
    /// the page has arrived above, and the offset moves by the growth of the content. The lazy stack estimates the
    /// height of rows it has not drawn, so the place can be off by that estimate's error.
    @State private var rowsWhenLoadingStarted: (count: Int, lastID: String?)?
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private static let stickDistance: CGFloat = 80

    private struct Metrics: Equatable {
        var contentHeight: CGFloat
        var contentOffset: CGFloat
        var distanceFromBottom: CGFloat
    }

    private struct HistoryTrigger: Equatable {
        var isVisible: Bool
        var state: HistoryState
    }

    var body: some View { list }

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
            .padding(.horizontal, 16).padding(.vertical, 12)
            .frame(maxWidth: 720).frame(maxWidth: .infinity)
            .animation(reduceMotion ? nil : .smooth(duration: 0.2), value: thread.rows.count)
        }
        .accessibilityIdentifier("chat.list")
        .scrollPosition($position)
        .defaultScrollAnchor(.bottom, for: .initialOffset)
        .scrollEdgeEffectStyle(.hard, for: [.top, .bottom])
        .onScrollGeometryChange(for: Metrics.self) { geometry in
            Metrics(
                contentHeight: geometry.contentSize.height, contentOffset: geometry.contentOffset.y,
                distanceFromBottom: max(0, geometry.contentSize.height - geometry.visibleRect.maxY))
        } action: { old, new in
            follow(from: old, to: new)
        }
        .onChange(of: HistoryTrigger(isVisible: isHistoryVisible, state: thread.historyState)) { _, trigger in
            guard trigger.isVisible, trigger.state == .available else { return }
            isHistoryVisible = false
            Task { await thread.loadOlder() }
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
        if let started = rowsWhenLoadingStarted, thread.rows.count > started.count,
            thread.rows.last?.id == started.lastID
        {
            rowsWhenLoadingStarted = nil
            position.scrollTo(y: old.contentOffset + (new.contentHeight - old.contentHeight))
            return
        }
        if old.distanceFromBottom <= Self.stickDistance {
            position.scrollTo(edge: .bottom)
            isNearBottom = true
        }
    }

    private func keepPlace(from old: HistoryState, to new: HistoryState) {
        if new == .loading {
            rowsWhenLoadingStarted = (thread.rows.count, thread.rows.last?.id)
        } else if old == .loading, thread.rows.count == rowsWhenLoadingStarted?.count {
            rowsWhenLoadingStarted = nil
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
