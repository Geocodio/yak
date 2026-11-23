import SwiftUI
import UIKit
import YakKit

/// The title of the thread's header: a status dot and the title on one line, and the repository and branch under it.
struct ThreadTitle: View {
    let title: String
    let dot: ThreadStatusDot
    /// The repository and branch, in one line; nil while the thread has neither.
    let meta: String?
    /// A connection problem; its words take the place of the repository line while it lasts.
    var connectionLine: ConnectionLine?
    /// The request's status tone; it picks between waiting and blocked for the attention dot.
    var tone: Tone?
    /// Opens the Overview, or returns to the pane before it.
    var action: (() -> Void)?

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
                    .lineLimit(1).truncationMode(.tail)
                    .accessibilityAddTraits(.isHeader)
                    .accessibilityValue(statusText)
            }
            if let connectionLine {
                Text(verbatim: connectionLine.text).font(.yak(.caption, .medium)).foregroundStyle(Color.yakWarning)
                    .lineLimit(1)
                    .padding(.leading, markSize + 8)
                    .accessibilityIdentifier("thread.connection")
            } else if let meta {
                Text(verbatim: meta).font(.yak(.caption)).foregroundStyle(Color.yakMuted)
                    .lineLimit(1).truncationMode(.middle)
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
