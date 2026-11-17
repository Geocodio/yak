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
    /// A finger is on the list or its fling is running; the list does not follow new content then.
    @State private var isPersonScrolling = false
    /// The person has touched the list. Until then the list stays at its end through every change of its rows.
    @State private var hasPersonScrolled = false
    /// The history row came into view and has not asked for a page yet. A page is asked for once per arrival: the
    /// row's disappearance can reach this view late, after a page has pushed it far above, and that would ask for
    /// every page in a row.
    @State private var isHistoryVisible = false
    /// The row that holds the place when a page of earlier messages was asked for: where it was, how tall it
    /// was, how tall the viewport was, and how many rows there were. When the page has arrived above it, the row is
    /// scrolled back to the same place by its identity, so the estimated height of the new rows plays no part.
    @State private var rowCountWhenRequested: Int?
    /// That row as of the last layout before the page arrived; once the rows have changed, it is not updated.
    @State private var anchorBeforePage: PlaceAnchor?
    /// The row put back after a page of earlier messages, held there while the page's documents arrive.
    @State private var placeHeld: HeldPlace?
    @State private var rowFrames = RowFrames()
    @State private var viewportHeight: CGFloat = 0
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private static let stickDistance: CGFloat = 80
    /// Scrolling the app does by itself moves at once; only a person's tap animates.
    private static var still: Transaction {
        var transaction = Transaction(animation: nil)
        transaction.disablesAnimations = true
        return transaction
    }

    private struct Metrics: Equatable {
        var contentHeight: CGFloat
        var viewportHeight: CGFloat
        var distanceFromBottom: CGFloat
    }

    private struct PlaceAnchor {
        var id: String
        var top: CGFloat
        var height: CGFloat
        var viewportHeight: CGFloat
        var rowCount: Int
    }

    private struct HeldPlace {
        var id: String
        var fraction: CGFloat
        var until: Date
    }

    private struct HistoryTrigger: Equatable {
        var isVisible: Bool
        var state: HistoryState
        var isAsked: Bool
    }

    /// The jump button and the action line sit below the list, so no row hides behind them.
    var body: some View {
        VStack(spacing: 0) {
            list
            floatingControls
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
                        .onGeometryChange(for: CGRect.self) {
                            $0.frame(in: .scrollView)
                        } action: {
                            // A day divider moves when older messages of its day arrive above it, so only entries
                            // can hold the place.
                            guard case .entry = row else { return }
                            rowFrames.frames[row.id] = ($0, CACurrentMediaTime())
                            if let requested = rowCountWhenRequested, thread.rows.count == requested {
                                anchorBeforePage = anchorRow()
                            }
                        }
                        .onDisappear { rowFrames.frames[row.id] = nil }
                }
                if let notice = thread.notice {
                    NoticeCard(notice: notice, thread: thread)
                }
            }
            .scrollTargetLayout()
            .padding(.horizontal, 16).padding(.vertical, 12)
            .frame(maxWidth: 720).frame(maxWidth: .infinity)
        }
        .accessibilityIdentifier("chat.list")
        .scrollPosition($position)
        .defaultScrollAnchor(.bottom, for: .initialOffset)
        // The navigation bar is opaque and nothing sits over the list's foot, so no edge effect draws over rows.
        .scrollEdgeEffectHidden(true, for: [.top, .bottom])
        .onScrollGeometryChange(for: Metrics.self) { geometry in
            Metrics(
                contentHeight: geometry.contentSize.height, viewportHeight: geometry.containerSize.height,
                distanceFromBottom: max(0, geometry.contentSize.height - geometry.visibleRect.maxY))
        } action: { old, new in
            follow(from: old, to: new)
        }
        // Earlier messages load when the person has scrolled to the history row; a list that fits the screen still
        // moves under a finger. Rows that arrive late pass through the top on their way to the end, and that does
        // not ask for a page.
        .onChange(
            of: HistoryTrigger(isVisible: isHistoryVisible, state: thread.historyState, isAsked: hasPersonScrolled)
        ) { _, trigger in
            guard trigger.isVisible, trigger.isAsked, trigger.state == .available else { return }
            isHistoryVisible = false
            rowCountWhenRequested = thread.rows.count
            anchorBeforePage = anchorRow()
            Task { await thread.loadOlder() }
        }
        .onChange(of: thread.rows.count) { _, count in restorePlace(rowCount: count) }
        .onChange(of: thread.documents.count) { applyHeldPlace() }
        .onScrollPhaseChange { _, phase in
            isPersonScrolling = [.tracking, .interacting, .decelerating].contains(phase)
            if [.tracking, .interacting].contains(phase) {
                placeHeld = nil
                hasPersonScrolled = true
            }
        }
        .onChange(of: thread.historyState) { old, new in
            guard old == .loading, new != .loading, let requested = rowCountWhenRequested else { return }
            // The rows can reach the screen a moment after the state does.
            Task {
                try? await Task.sleep(for: .seconds(1))
                if thread.rows.count == requested { rowCountWhenRequested = nil }
            }
        }
        .overlay { if thread.rows.isEmpty, thread.notice == nil, thread.snapshot?.load == .ready { EmptyChat() } }
        .onChange(of: thread.announcementSerial) {
            if let text = thread.announcement { UIAccessibility.post(notification: .announcement, argument: text) }
        }
        .sensoryFeedback(.warning, trigger: thread.actionLine) { _, new in new != nil }
    }

    // MARK: Scrolling

    /// New content keeps the end in view for someone who is at the end; for anyone else nothing moves. The lazy stack
    /// refines its height estimates while someone scrolls, which is not new content and must not pull them back.
    private func follow(from old: Metrics, to new: Metrics) {
        viewportHeight = new.viewportHeight
        guard new.contentHeight != old.contentHeight else {
            isNearBottom = new.distanceFromBottom <= Self.stickDistance
            return
        }
        // Until the person touches the list, the end stays in view however late or in how many steps the rows and
        // their Markdown arrive; a distance read before the first rows existed decides nothing.
        if !isPersonScrolling, !hasPersonScrolled || old.distanceFromBottom <= Self.stickDistance {
            withTransaction(Self.still) { position.scrollTo(edge: .bottom) }
            isNearBottom = true
        }
    }

    /// The lowest row that is wholly on screen, with where it is; the highest one on screen when none is whole. Older
    /// messages arrive above, and a row near the top can move with them (a message that sorts before every event of
    /// the page), so the place is held by the row furthest from them. A row that has left the screen can be slow to
    /// be forgotten, so only rows that reported as recently as the newest one count.
    private func anchorRow() -> PlaceAnchor? {
        guard let newest = rowFrames.frames.values.map(\.time).max() else { return nil }
        let current = rowFrames.frames.filter { $0.value.time > newest - 0.15 && $0.value.rect.maxY > 0 }
        let whole = current.filter { $0.value.rect.minY >= 0 && $0.value.rect.maxY <= viewportHeight }
        guard
            let top = whole.max(by: { $0.value.rect.minY < $1.value.rect.minY })
                ?? current.min(by: { $0.value.rect.minY < $1.value.rect.minY })
        else { return nil }
        let frame = top.value.rect
        return PlaceAnchor(
            id: top.key, top: frame.minY, height: frame.height, viewportHeight: viewportHeight,
            rowCount: thread.rows.count)
    }

    /// When the asked-for page has been added, puts the anchor row back where it was. The rows are
    /// read before the new ones are laid out. The anchor lines the point `f * height` of the row up with the point
    /// `f * viewport` of the viewport, which puts its top at the old place for `f = top / (viewport - height)`.
    /// The new rows show as plain text until their Markdown is parsed, and the rendered text can be taller, so the
    /// same placement is applied again as their documents arrive, until the person scrolls or the time is up.
    private func restorePlace(rowCount: Int) {
        guard let requested = rowCountWhenRequested, rowCount > requested, let anchor = anchorBeforePage else { return }
        rowCountWhenRequested = nil
        let room = anchor.viewportHeight - anchor.height
        let fraction = room > 1 ? min(max(anchor.top / room, 0), 1) : 0
        placeHeld = HeldPlace(id: anchor.id, fraction: fraction, until: .now + 3)
        applyHeldPlace()
        Task {
            // The lazy stack can correct its estimates after the first pass.
            try? await Task.sleep(for: .milliseconds(60))
            applyHeldPlace()
        }
    }

    private func applyHeldPlace() {
        guard let held = placeHeld, !isPersonScrolling else { return }
        guard held.until > .now else {
            placeHeld = nil
            return
        }
        withTransaction(Self.still) { position.scrollTo(id: held.id, anchor: UnitPoint(x: 0, y: held.fraction)) }
    }

    private func jumpToLatest() {
        withAnimation(reduceMotion ? nil : .smooth) { position.scrollTo(edge: .bottom) }
        isNearBottom = true
    }

    // MARK: Floating controls

    /// Nothing at all while there is nothing to show, so a conversation at its end keeps the full height.
    @ViewBuilder private var floatingControls: some View {
        if thread.actionLine != nil || !isNearBottom { floatingStack }
    }

    private var floatingStack: some View {
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
        .padding(.horizontal, 16).padding(.vertical, 8)
        .animation(reduceMotion ? nil : .smooth(duration: 0.2), value: isNearBottom)
    }
}

/// Where the rows on screen are, kept out of the view's state so that scrolling does not redraw the list.
private final class RowFrames {
    var frames: [String: (rect: CGRect, time: Double)] = [:]
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
