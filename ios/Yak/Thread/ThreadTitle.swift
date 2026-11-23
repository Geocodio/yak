import SwiftUI
import UIKit
import YakKit

/// The title of the thread's header: a status dot and the title on one line, and the repository and branch under it.
struct ThreadTitle: View {
    let title: String
    let dot: ThreadStatusDot
    /// The repository and branch, in one line; nil while the thread has neither.
    let meta: String?
    /// The request's status tone; it picks between waiting and blocked for the attention dot.
    var tone: Tone?
    /// Opens the Overview, or returns to the pane before it.
    var action: (() -> Void)?

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        if let action {
            Button(action: action) { content }
                .buttonStyle(.ghost)
                .accessibilityLabel(Text("overview.title.label \(title)"))
                .accessibilityHint(Text("overview.title.hint"))
                .accessibilityIdentifier("thread.title")
        } else {
            content.accessibilityIdentifier("thread.title")
        }
    }

    private var content: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 8) {
                statusMark.accessibilityHidden(true)
                Text(title).font(.yakBarTitle).foregroundStyle(Color.yakText)
                    .lineLimit(dynamicTypeSize.isAccessibilitySize ? 3 : 1).truncationMode(.tail)
                    .fixedSize(horizontal: false, vertical: true)
                    .accessibilityAddTraits(.isHeader)
                    .accessibilityValue(statusText)
            }
            if let meta {
                Text(verbatim: meta).font(.yak(.caption)).foregroundStyle(Color.yakMuted)
                    .lineLimit(dynamicTypeSize.isAccessibilitySize ? 3 : 1).truncationMode(.middle)
                    .fixedSize(horizontal: false, vertical: true)
                    .padding(.leading, markSize + 8)
                    .accessibilityIdentifier("thread.meta")
            }
        }
        .frame(maxWidth: .infinity, minHeight: Design.Size.control, alignment: .leading)
        .padding(.horizontal, Design.Space.compact)
        .contentShape(.rect)
    }

    private let markSize: CGFloat = 8

    /// Running is the brand color filled, waiting is the warning color, blocked the failure color, and every other
    /// state is a hollow ring.
    @ViewBuilder private var statusMark: some View {
        switch dot {
        case .running: Circle().fill(Color.yakSlate).frame(width: markSize, height: markSize)
        case .attention:
            Circle().fill(tone == .bad ? Color.yakFailure : Color.yakWarningDot).frame(
                width: markSize, height: markSize)
        case .neutral:
            Circle().strokeBorder(Color.yakFaint, lineWidth: 1.5).frame(width: markSize, height: markSize)
        }
    }

    private var statusText: Text {
        switch dot {
        case .running: Text("thread.status.running")
        case .attention: Text("thread.status.attention")
        case .neutral: Text("")
        }
    }
}

/// The flat bar at the top of a thread: the threads button, the title and the menu, on the page color with a rule
/// under it.
struct ThreadHeader<Title: View, Leading: View, Trailing: View>: View {
    let title: Title
    @ViewBuilder let leading: Leading
    @ViewBuilder let trailing: Trailing

    var body: some View {
        HStack(spacing: 0) {
            leading
            title
            trailing
        }
        .foregroundStyle(Color.yakText).tint(Color.yakText)
        .dynamicTypeSize(...DynamicTypeSize.accessibility2)
        .padding(.horizontal, Design.Space.tight)
        .frame(minHeight: 56)
        .background(Color.yakBackground.ignoresSafeArea(edges: .top))
        .overlay(alignment: .bottom) { Rectangle().fill(Color.yakLine).frame(height: Design.Space.line) }
    }
}

extension ConnectionLine {
    var text: String {
        switch self {
        case .slow: String(localized: "connection.slow")
        case .offlineSavedCopy: String(localized: "connection.offlineSavedCopy")
        case .offline: String(localized: "connection.offline")
        case .reconnecting: String(localized: "connection.reconnecting")
        }
    }
}
