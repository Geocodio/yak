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
    /// The request's status tone: a thread with one shows a filled dot in its hue.
    var tone: Tone?
    /// The request's status in words, for VoiceOver.
    var statusLabel: String?
    /// Opens the Overview, or returns to the pane before it.
    var action: (() -> Void)?
    /// A small star after the title. VoiceOver reads "starred" as part of the title.
    var isStarred = false
    /// A neutral "Closed" pill starts the second line.
    var isClosed = false

    /// The title as VoiceOver reads it.
    private var spokenTitle: String { isStarred ? String(localized: "thread.title.starred \(title)") : title }

    var body: some View {
        if let action {
            Button(action: action) { content }
                .buttonStyle(.ghost)
                .accessibilityLabel(Text("overview.title.label \(spokenTitle)"))
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
                    .accessibilityLabel(spokenTitle)
                    .accessibilityAddTraits(.isHeader)
                    .accessibilityValue(statusText)
                if isStarred {
                    Image(systemName: "star.fill").font(.yak(.caption)).foregroundStyle(Color.yakWarningDot)
                        .accessibilityHidden(true).accessibilityIdentifier("thread.starMark")
                }
            }
            if let connectionLine {
                Text(verbatim: connectionLine.text).font(.yak(.caption, .medium)).foregroundStyle(Color.yakWarning)
                    .lineLimit(1)
                    .padding(.leading, markSize + 8)
                    .accessibilityIdentifier("thread.connection")
            } else if isClosed || meta != nil {
                HStack(spacing: Design.Space.small) {
                    if isClosed {
                        Text("thread.closed").font(.yakBadge).foregroundStyle(Color.yakText).lineLimit(1)
                            .padding(.horizontal, Design.Space.small).padding(.vertical, Design.Space.line)
                            .background(Color.yakBubble, in: .rect(cornerRadius: Design.Radius.badge))
                            .layoutPriority(1)
                            .accessibilityIdentifier("thread.closedMark")
                    }
                    if let meta {
                        Text(verbatim: meta).font(.yak(.caption)).foregroundStyle(Color.yakMuted)
                            .lineLimit(1).truncationMode(.middle)
                            .accessibilityIdentifier("thread.meta")
                    }
                }
                .padding(.leading, markSize + 8)
            }
        }
        .frame(maxWidth: .infinity, minHeight: Design.Size.control, alignment: .leading)
        .padding(.horizontal, Design.Space.compact)
        .contentShape(.rect)
    }

    private let markSize: CGFloat = 8

    @ViewBuilder private var statusMark: some View {
        switch HeaderMark.make(dot: dot, tone: tone) {
        case .running: Circle().fill(Color.yakSlate).frame(width: markSize, height: markSize)
        case .ok: Circle().fill(Color.yakOK).frame(width: markSize, height: markSize)
        case .warn: Circle().fill(Color.yakWarningDot).frame(width: markSize, height: markSize)
        case .bad: Circle().fill(Color.yakFailure).frame(width: markSize, height: markSize)
        case .info: Circle().fill(Color.yakInfo).frame(width: markSize, height: markSize)
        case .ring: Circle().strokeBorder(Color.yakFaint, lineWidth: 1.5).frame(width: markSize, height: markSize)
        }
    }

    private var statusText: Text {
        if let statusLabel, !statusLabel.isEmpty { return Text(verbatim: statusLabel) }
        return switch dot {
        case .running: Text("thread.status.running")
        case .attention: Text("thread.status.attention")
        case .neutral: Text("")
        }
    }
}

/// The dot of the header: running is the brand color; a request status with a tone is a filled dot in the tone's hue;
/// waiting without a tone is the warning color; every other state is a hollow ring.
enum HeaderMark: Equatable {
    case running, ok, warn, bad, info, ring

    static func make(dot: ThreadStatusDot, tone: Tone?) -> HeaderMark {
        if dot == .running { return .running }
        switch tone {
        case .some(.ok): return .ok
        case .some(.warn): return .warn
        case .some(.bad): return .bad
        case .some(.info): return .info
        default: return dot == .attention ? .warn : .ring
        }
    }
}

/// Whether the header says the connection is down. The Chat pane draws its own line (the saved copy or the offline
/// line) while it shows, so the header leaves it out then and only then.
enum HeaderConnection {
    static func line(
        _ line: ConnectionLine?, chatIsVisible: Bool, chatShowsOwnLine: Bool
    ) -> ConnectionLine? {
        guard let line else { return nil }
        return chatIsVisible && chatShowsOwnLine ? nil : line
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
