import SwiftUI
import YakKit

/// The pull request line under a Review row. The kit decides which parts show and on which lines; this view draws
/// them. A number takes its own tap; a tap on any other part reaches the row.
struct ReviewLineView: View {
    let line: ReviewLine
    let rowID: String
    let openPullRequest: (ReviewLine.Link) -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        arranged()
            .frame(maxWidth: .infinity, alignment: .leading)
    }

    /// The items of a line sit side by side, and under each other at accessibility sizes. The same views change
    /// their layout, so the size never rebuilds the line.
    private func arranged() -> some View {
        let lines = line.lines
        return VStack(alignment: .leading, spacing: Design.Space.tight) {
            ForEach(Array(lines.enumerated()), id: \.offset) { index, items in
                let layout =
                    dynamicTypeSize.isAccessibilitySize
                    ? AnyLayout(VStackLayout(alignment: .leading, spacing: Design.Space.tight))
                    : AnyLayout(HStackLayout(spacing: Design.Space.small))
                layout {
                    ForEach(Array(items.enumerated()), id: \.offset) { _, item in
                        switch item {
                        case .numbers: numbers(isOnLastLine: index == lines.count - 1)
                        case .part(let part): ReviewPartView(part: part).allowsHitTesting(false)
                        }
                    }
                }
            }
        }
    }

    /// The numbers, each a target of its own. A target never reaches into another line or another target: the comma
    /// and half the gap after a number belong to it, the other half to the next number, and the reach down is the
    /// row's own padding, only when the numbers sit on the row's last line with nothing stacked under them.
    private func numbers(isOnLastLine: Bool) -> some View {
        HStack(spacing: 0) {
            ForEach(Array(line.links.enumerated()), id: \.offset) { index, link in
                let isLast = index == line.links.count - 1
                let reach = ReviewHitArea.reach(
                    isFirst: index == 0, isLast: isLast,
                    isOnLastLine: isOnLastLine && !dynamicTypeSize.isAccessibilitySize,
                    bottomPadding: Design.Space.reviewRowBottom, lineHeight: Design.Size.numberLineHeight,
                    outerReach: Design.Space.numberReachHorizontal)
                target(link, index: index, isLast: isLast, reach: reach)
            }
        }
        .fixedSize(horizontal: false, vertical: true)
    }

    @ViewBuilder private func target(
        _ link: ReviewLine.Link, index: Int, isLast: Bool, reach: ReviewHitArea.Reach
    ) -> some View {
        let label = HStack(spacing: 0) {
            Text(link.text).font(.yakRowRepository).foregroundStyle(Color.yakMuted)
                .underline(link.isActionable)
                .fixedSize(horizontal: false, vertical: true)
            if !isLast {
                Text(verbatim: ",").font(.yakRowRepository).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
        .padding(.leading, index == 0 ? 0 : Design.Space.nudge)
        .padding(.trailing, isLast ? 0 : Design.Space.nudge)
        if link.isActionable {
            // A text with a tap, not a button: the row's one element carries the actions for VoiceOver.
            label
                .padding(.leading, reach.leading).padding(.trailing, reach.trailing).padding(.bottom, reach.bottom)
                .contentShape(.rect)
                .onTapGesture { openPullRequest(link) }
                .padding(.leading, -reach.leading).padding(.trailing, -reach.trailing)
                .padding(.bottom, -reach.bottom)
                .accessibilityElement(children: .combine)
                .accessibilityIdentifier("drawer.review.pr.\(rowID).\(index)")
        } else {
            label.allowsHitTesting(false)
        }
    }
}

/// One fact of the line: a badge, the check mark or the other approvals. State is in the badge fill, the mark and
/// the words, never a colored edge.
private struct ReviewPartView: View {
    let part: ReviewLine.Part

    var body: some View {
        switch part {
        case .draft: ReviewBadge(text: String(localized: "drawer.review.draft"), tone: .quiet)
        case .ready: ReviewBadge(text: String(localized: "drawer.review.ready"), tone: .quiet)
        case .conflicts: ReviewBadge(text: String(localized: "drawer.review.conflicts"), tone: .danger)
        case .ciFailing: ReviewBadge(text: String(localized: "drawer.review.ciFailing"), tone: .danger)
        case .checksPassing:
            Image(systemName: "checkmark").font(.yak(.caption2, .semibold)).foregroundStyle(Color.yakPillOKText)
        case .newCommitsSinceApproval:
            ReviewBadge(text: String(localized: "drawer.review.newCommits"), tone: .warning)
        case .otherApprovals(let count):
            HStack(spacing: Design.Space.nudge) {
                Image(systemName: "checkmark").font(.yak(.caption2, .semibold))
                Text(
                    count == 1
                        ? String(localized: "drawer.review.other \(count)")
                        : String(localized: "drawer.review.others \(count)")
                )
                .font(.yak(.caption, .medium)).fixedSize(horizontal: false, vertical: true)
            }
            .foregroundStyle(Color.yakPillOKText)
        }
    }
}

/// A badge whose fill and text come from a pair of tokens; the quiet one is the source badge's pair. A plain stack: no custom layout in a drawer row.
private struct ReviewBadge: View {
    enum Tone { case quiet, danger, warning }

    let text: String
    let tone: Tone

    var body: some View {
        Text(text).font(.yakBadge).foregroundStyle(textColor)
            .padding(.horizontal, Design.Space.small).padding(.vertical, Design.Space.line)
            .background(fill, in: .rect(cornerRadius: Design.Radius.badge))
            .fixedSize(horizontal: false, vertical: true)
    }

    private var textColor: Color {
        switch tone {
        case .quiet: .yakText
        case .danger: .yakPillBadText
        case .warning: .yakPillWarnText
        }
    }

    private var fill: Color {
        switch tone {
        case .quiet: .yakBubble
        case .danger: .yakPillBad
        case .warning: .yakPillWarn
        }
    }
}
