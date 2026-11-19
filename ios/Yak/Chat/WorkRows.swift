import SwiftUI
import YakKit

/// A row of wrapped pills with the board's gap in both directions.
struct WrappingRow: Layout {
    var spacing: CGFloat = Design.Space.small

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        arrange(width: proposal.width ?? .infinity, subviews: subviews).size
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let placed = arrange(width: bounds.width, subviews: subviews)
        for (index, origin) in placed.origins.enumerated() {
            subviews[index].place(
                at: CGPoint(x: bounds.minX + origin.x, y: bounds.minY + origin.y),
                proposal: ProposedViewSize(width: bounds.width, height: nil))
        }
    }

    private func arrange(width: CGFloat, subviews: Subviews) -> (size: CGSize, origins: [CGPoint]) {
        var origins: [CGPoint] = []
        var x: CGFloat = 0
        var y: CGFloat = 0
        var rowHeight: CGFloat = 0
        var widest: CGFloat = 0
        for subview in subviews {
            let size = subview.sizeThatFits(ProposedViewSize(width: width, height: nil))
            if x > 0, x + size.width > width {
                x = 0
                y += rowHeight + spacing
                rowHeight = 0
            }
            origins.append(CGPoint(x: x, y: y))
            x += size.width + spacing
            widest = max(widest, x - spacing)
            rowHeight = max(rowHeight, size.height)
        }
        return (CGSize(width: widest, height: y + rowHeight), origins)
    }
}

/// The milestones of one run, in one wrapping row. A milestone with a safe address opens it in the browser.
struct MilestoneGroupRow: View {
    let milestones: [ThreadMilestone]
    @State private var browserURL: BrowserLink?

    var body: some View {
        WrappingRow {
            ForEach(milestones) { milestone in
                pill(milestone)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
    }

    @ViewBuilder private func pill(_ milestone: ThreadMilestone) -> some View {
        let pill = TonePill(text: milestone.label, tone: milestone.tone)
        Group {
            if let url = WebLinkRule.safeURL(milestone.url) {
                // Drawn as tall as every pill; the hit area is 44 and does not add to the row's height.
                Button {
                    browserURL = BrowserLink(url: url)
                } label: {
                    HStack(spacing: Design.Space.tight) {
                        pill
                        Image(systemName: "arrow.up.right").font(.caption2.weight(.semibold))
                            .foregroundStyle(milestone.tone.pillText).accessibilityHidden(true)
                    }
                    .frame(minHeight: Design.Size.control).contentShape(.rect)
                }
                .buttonStyle(.plain)
                .padding(.vertical, -(Design.Size.control - Design.Size.pill) / 2)
            } else {
                pill
            }
        }
        .accessibilityIdentifier("thread.milestone.\(milestone.key.rawValue)")
    }
}

/// A retry run has no text: a divider says that Yak tried again.
struct RetryDivider: View {
    let message: ThreadMessage

    var body: some View {
        HStack(spacing: Design.Space.snug) {
            line
            Group {
                if let number = message.retryNumber { Text("chat.retry.number \(number)") } else { Text("chat.retry") }
            }
            .font(.footnote.weight(.medium)).foregroundStyle(Color.yakCardSecondaryText).fixedSize()
            .accessibilityIdentifier("chat.retry")
            line
        }
    }

    private var line: some View {
        Rectangle().fill(Color.yakCardSeparator).frame(height: Design.Space.line).accessibilityHidden(true)
    }
}

/// One answer of a thread made from an old task, rendered by the server.
struct LegacyAnswerView: View {
    let answer: LegacyAnswer
    let thread: ThreadModel
    @State private var browserURL: BrowserLink?

    var body: some View {
        TaskEntryView(
            entry: TaskConversationEntry(kind: "yak", bodyHtml: answer.html), serverOrigin: thread.instanceURL,
            openLink: { browserURL = BrowserLink(url: $0) }
        )
        .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
        .accessibilityIdentifier("chat.legacyAnswer")
    }
}

/// Above a person's message: who it is from and the channel it came from, with a link to it, as on the board.
struct ViaCaption: View {
    let name: String
    let via: MessageVia
    let isReply: Bool
    @State private var browserURL: BrowserLink?

    private var detail: String { [via.label, via.reference].compactMap { $0 }.joined(separator: " ") }

    var body: some View {
        let text =
            isReply ? Text("chat.message.replyBy \(name) \(detail)") : Text("chat.message.viaBy \(name) \(detail)")
        Group {
            if let url = WebLinkRule.safeURL(via.url) {
                Button {
                    browserURL = BrowserLink(url: url)
                } label: {
                    text.frame(minHeight: Design.Size.control).contentShape(.rect)
                }
                .buttonStyle(.plain)
                .padding(.vertical, -(Design.Size.control - Design.Space.large) / 2)
            } else {
                text
            }
        }
        .font(.footnote.weight(.medium)).foregroundStyle(Color.yakCardSecondaryText)
        .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
        .accessibilityIdentifier("chat.message.via")
    }
}

/// What the chat's list draws: a row, or the milestones of one run gathered into one row.
enum ChatDisplayRow: Identifiable {
    case row(ConversationRow)
    case milestones(id: String, [ThreadMilestone])

    var id: String {
        switch self {
        case .row(let row): row.id
        case .milestones(let id, _): id
        }
    }

    /// Consecutive milestones become one row, so the pills of a run wrap together as on the board.
    static func group(_ rows: [ConversationRow]) -> [ChatDisplayRow] {
        var result: [ChatDisplayRow] = []
        for row in rows {
            if case .entry(.milestone(let milestone), _) = row {
                if case .milestones(let id, let existing)? = result.last {
                    result[result.count - 1] = .milestones(id: id, existing + [milestone])
                } else {
                    result.append(.milestones(id: row.id, [milestone]))
                }
            } else {
                result.append(.row(row))
            }
        }
        return result
    }
}
