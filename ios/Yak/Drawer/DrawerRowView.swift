import SwiftUI
import YakKit

/// One line of the drawer: the indicator, the title and subtitle, the age, a lock for a private thread and the
/// chevron that shows its sub-threads. The whole row of a thread that needs the person carries a light tint.
struct DrawerRowView: View {
    let row: DrawerRow
    let now: Date
    let open: () -> Void
    let toggle: () -> Void
    /// True while an action runs on this sub-thread.
    var isRunning = false
    /// A close or a delete is running on this thread: the row reads dimmed with a spinner and says what is happening.
    var busy: Busy?
    /// True for the thread the window shows.
    var isSelected = false
    /// The long-press preview shows the whole title and subtitle.
    var showsEverything = false

    enum Busy: Equatable { case closing, deleting }

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @ScaledMetric(relativeTo: .body) private var indicatorSize = 12.0
    @ScaledMetric(relativeTo: .body) private var indentation = 18.0

    /// Opens a pull request of a Review row; the number is its own target.
    var openPullRequest: (ReviewLine.Link) -> Void = { _ in }

    var body: some View {
        HStack(spacing: 0) {
            if row.depth > 0 { nesting }
            rowButton
            if row.childCount > 0 {
                Button(action: toggle) {
                    Image(systemName: "chevron.right").font(.yak(.footnote, .semibold))
                        .foregroundStyle(Color.yakMuted)
                        .rotationEffect(.degrees(row.isExpanded ? 90 : 0))
                        .frame(width: 44, height: 44).contentShape(.rect)
                }
                .buttonStyle(.pressable)
                .padding(.trailing, 8)
                .accessibilityLabel(row.isExpanded ? "drawer.hideChildren" : "drawer.showChildren")
                .accessibilityIdentifier("drawer.toggle.\(row.id)")
            }
        }
    }

    @ViewBuilder private var rowButton: some View {
        if let line = row.reviewLine { reviewRow(line) } else { plainRow }
    }

    /// A row with one target: the whole row opens the thread.
    private var plainRow: some View {
        Button(action: open) {
            rowLayout(line: nil).contentShape(.rect)
        }
        .buttonStyle(RowButtonStyle(isSelected: isSelected))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(accessibilityLabel)
        .accessibilityAddTraits(isSelected ? .isSelected : [])
        .accessibilityIdentifier("drawer.row.\(row.id)")
    }

    /// A row with two kinds of target. The row opens the thread through a button behind its content; the content
    /// lets touches through except on the pull request numbers. VoiceOver reads one element with the whole line and
    /// reaches each pull request through an action; the numbers are hidden from it and carry identifiers for tests.
    private func reviewRow(_ line: ReviewLine) -> some View {
        rowLayout(line: line)
            .accessibilityHidden(true)
            .background {
                Button(action: open) { Color.clear.contentShape(.rect) }
                    .buttonStyle(RowButtonStyle(isSelected: isSelected))
                    .accessibilityLabel(accessibilityLabel)
                    .accessibilityAddTraits(isSelected ? .isSelected : [])
                    .accessibilityIdentifier("drawer.row.\(row.id)")
                    .modifier(PullRequestActions(links: line.actionableLinks, open: openPullRequest))
            }
    }

    private func rowLayout(line: ReviewLine?) -> some View {
        let passesTouches = line != nil
        return HStack(alignment: .top, spacing: Design.Space.snug) {
            if !isLarge {
                DrawerIndicator(indicator: row.indicator)
                    .frame(width: indicatorSize, height: indicatorSize)
                    .opacity(isDimmed ? Design.closedMarkOpacity : 1)
                    .padding(.top, Design.Space.small)
                    .allowsHitTesting(!passesTouches)
            }
            VStack(alignment: .leading, spacing: 2) {
                title.allowsHitTesting(!passesTouches)
                detailLine.allowsHitTesting(!passesTouches)
                failureText.allowsHitTesting(!passesTouches)
                if let line {
                    ReviewLineView(line: line, rowID: row.id, openPullRequest: openPullRequest)
                        .padding(.top, Design.Space.tight)
                }
                if isLarge {
                    // The indicator moves in front of the age so the title keeps the whole width.
                    HStack(spacing: 8) {
                        DrawerIndicator(indicator: row.indicator).frame(width: 22, height: 22)
                            .opacity(isDimmed ? Design.closedMarkOpacity : 1)
                        trailing(isInline: true)
                    }
                    .allowsHitTesting(!passesTouches)
                }
            }
            Spacer(minLength: 4)
            if isRunning { ProgressView().frame(width: 22, height: 22) }
            if !isLarge { trailing(isInline: false).allowsHitTesting(!passesTouches) }
        }
        .padding(.leading, isLarge ? 12 : (row.depth == 0 ? Design.Space.snug : 8))
        .padding(.trailing, row.childCount > 0 ? 0 : Design.Space.medium)
        .padding(.top, Design.Space.compact)
        .padding(.bottom, passesTouches ? Design.Space.reviewRowBottom : Design.Space.compact)
        .frame(minHeight: isLarge ? 44 : Design.Size.row)
    }

    /// The error of a failed thread: muted, two lines (one at accessibility sizes), cut at the end. The long-press
    /// preview shows all of it with its line breaks, in a scroll view that stops growing at a set height.
    @ViewBuilder private var failureText: some View {
        if showsEverything, let full = row.failureFullText {
            ScrollView {
                Text(full).font(.yak(.caption)).foregroundStyle(Color.yakMuted)
                    .multilineTextAlignment(.leading)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .frame(maxHeight: Design.Size.previewDetailMaxHeight)
            .fixedSize(horizontal: false, vertical: true)
        } else if let detail = row.failureDetail {
            Text(detail).font(.yak(.caption)).foregroundStyle(Color.yakMuted)
                .multilineTextAlignment(.leading)
                .lineLimit(isLarge ? 1 : 2).truncationMode(.tail)
                .fixedSize(horizontal: false, vertical: true)
        }
    }

    /// A closed thread, and one that is being closed or deleted, reads dimmed: the quiet text color and a fainter mark.
    /// The whole row is not made translucent, because text at that opacity would fail the contrast of the audit.
    private var isDimmed: Bool { row.indicator == .closed || busy != nil }

    /// The title; an unread thread's is semibold, with a small dot after it. A starred thread has a small star after it.
    private var title: some View {
        let isUnread = row.indicator == .unseen
        var name = AttributedString(row.title)
        name.font = isUnread ? .yak(.subheadline, .semibold) : .yakRowTitle
        name.foregroundColor = isDimmed ? .yakMuted : .yakText
        if isUnread {
            var dot = AttributedString(" \u{25CF}")
            dot.font = .system(size: 6)
            dot.foregroundColor = .yakInfo
            name += dot
        }
        return HStack(alignment: .firstTextBaseline, spacing: Design.Space.tight) {
            Text(name).multilineTextAlignment(.leading).lineLimit(wrapsLines ? nil : 1)
            if row.isStarred {
                Image(systemName: "star.fill").font(.yak(.caption)).foregroundStyle(Color.yakWarningDot)
                    .accessibilityHidden(true).layoutPriority(1)
                    .accessibilityIdentifier("drawer.starMark.\(row.id)")
            }
        }
    }

    /// The second line: the badges at its start (the source first, then a task's mark), then the reason or detail, or
    /// the repository in the mono face for a row with neither a source nor a reason. The badges never shorten; the
    /// text after them does. At accessibility sizes the badges sit above the text, which wraps.
    private var detailLine: some View {
        let layout =
            isLarge
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: Design.Space.tight))
            : AnyLayout(HStackLayout(spacing: Design.Space.small))
        return layout {
            if row.source != nil || isTask {
                HStack(spacing: Design.Space.small) {
                    if let source = row.source {
                        DrawerBadge(text: source)
                    }
                    if isTask {
                        DrawerBadge(text: String(localized: "drawer.taskLabel"), systemImage: "checklist")
                            .accessibilityIdentifier("drawer.taskLabel")
                    }
                }
                .layoutPriority(1)
            }
            if busy == nil, let repository = row.repositoryDetail {
                Text(repository).font(.yakRowRepository).foregroundStyle(subtitleColor)
                    .multilineTextAlignment(.leading)
                    .lineLimit(wrapsLines ? nil : 1).truncationMode(.middle)
            } else {
                Text(busy == nil ? subtitle : busyText).font(.yakRowDetail).foregroundStyle(subtitleColor)
                    .multilineTextAlignment(.leading)
                    .lineLimit(wrapsLines ? nil : detailLineLimit).truncationMode(.tail)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
    }

    /// A question reads in the brand color and a block in the failure color; everything else is quiet.
    private var subtitleColor: Color {
        switch row.indicator {
        case .needsYou: .yakSlate
        case .blocked: .yakCardDangerText
        default: .yakMuted
        }
    }

    /// A neutral hairline that shows the nesting.
    private var nesting: some View {
        HStack(spacing: 0) {
            Color.clear.frame(width: 12 + (isLarge ? 14 : indentation) * CGFloat(row.depth - 1))
            Rectangle().fill(Color.yakLine).frame(width: Design.Space.line)
        }
    }

    /// Above the default text size a title and a subtitle wrap, so no size cuts them short; at the default they end in an
    /// ellipsis after one line, as on the board.
    private var wrapsLines: Bool { isLarge || showsEverything || dynamicTypeSize > .large }

    /// At accessibility text sizes the title needs the whole width, so the age and the lock go under the subtitle.
    private var isLarge: Bool { dynamicTypeSize.isAccessibilitySize }

    @ViewBuilder private func trailing(isInline: Bool) -> some View {
        let layout =
            isInline ? AnyLayout(HStackLayout(spacing: 8)) : AnyLayout(VStackLayout(alignment: .trailing, spacing: 4))
        layout {
            if let age { Text(age).font(.yak(.caption)).foregroundStyle(Color.yakMuted) }
            if row.isPrivate {
                Image(systemName: "lock.fill").font(.yak(.caption)).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
            }
        }
    }

    private var age: String? {
        guard let date = row.lastActivity else { return nil }
        switch RelativeAge.value(from: date, now: now) {
        case .now: return String(localized: "drawer.age.now")
        case .minutes(let count): return String(localized: "drawer.age.minutes \(count)")
        case .hours(let count): return String(localized: "drawer.age.hours \(count)")
        case .days(let count): return String(localized: "drawer.age.days \(count)")
        case .date(let day): return day.formatted(.dateTime.month(.abbreviated).day())
        }
    }

    private var subtitle: String { DrawerRowView.subtitleText(row.subtitle) }

    /// The sub-thread summary and a failure take two lines at the default size, so they are not cut mid-phrase beside a
    /// badge; every other detail is one line with an ellipsis, as on the board.
    private var detailLineLimit: Int {
        if case .children = row.subtitle { return 2 }
        return row.indicator == .blocked ? 2 : 1
    }

    private var busyText: String {
        busy == .deleting ? String(localized: "drawer.deleting") : String(localized: "drawer.closing")
    }

    private var isTask: Bool {
        if case .task = row.target { true } else { false }
    }

    static func subtitleText(_ subtitle: DrawerSubtitle) -> String {
        switch subtitle {
        case .serverLabel(let label): return label
        case .children(let total, let working):
            return String(localized: "drawer.children \(total) \(working)")
        case .step(let text): return text
        case .taskWaiting: return String(localized: "drawer.taskWaiting")
        case .status(let status):
            switch status {
            case .running: return String(localized: "drawer.status.running")
            case .blocked: return String(localized: "drawer.status.blocked")
            case .paused: return String(localized: "drawer.status.paused")
            case .stopped: return String(localized: "drawer.status.stopped")
            case .closed: return String(localized: "drawer.status.closed")
            case .waitingForRepository: return String(localized: "drawer.status.waitingForRepository")
            default: return status.rawValue
            }
        case .unsent(let state):
            switch state {
            case .queued, .sending: return String(localized: "drawer.unsent.starting")
            case .slow: return String(localized: "drawer.unsent.slow")
            case .waiting: return String(localized: "drawer.unsent.waiting")
            case .refused(let refusal): return String(localized: "drawer.unsent.refused \(refusal.message)")
            }
        }
    }

    /// "Pull request 271, ready, has conflicts, new commits since your approval, 1 other approved".
    static func spokenSentence(of line: ReviewLine) -> String {
        let head =
            line.links.count == 1
            ? String(localized: "drawer.review.spoken.pullRequest \(line.links[0].number)")
            : String(
                localized:
                    "drawer.review.spoken.pullRequests \(line.links.map { "\($0.repository) \($0.number)" }.joined(separator: ", "))"
            )
        let facts = line.parts.map { part -> String in
            switch part {
            case .draft: String(localized: "drawer.review.spoken.draft")
            case .ready: String(localized: "drawer.review.spoken.ready")
            case .conflicts: String(localized: "drawer.review.spoken.conflicts")
            case .ciFailing: String(localized: "drawer.review.spoken.ciFailing")
            case .checksPassing: String(localized: "drawer.review.spoken.checksPassing")
            case .newCommitsSinceApproval: String(localized: "drawer.review.spoken.newCommits")
            case .otherApprovals(let count):
                count == 1
                    ? String(localized: "drawer.review.spoken.other \(count)")
                    : String(localized: "drawer.review.spoken.others \(count)")
            }
        }
        return ([head] + facts).joined(separator: ", ")
    }

    private var accessibilityLabel: String {
        var parts = [
            row.title.isEmpty ? String(localized: "drawer.untitled") : row.title, busy == nil ? subtitle : busyText,
        ]
        if row.isStarred { parts.insert(String(localized: "thread.starred"), at: 1) }
        if let detail = row.failureDetail { parts.append(detail) }
        if let source = row.source { parts.insert(String(localized: "drawer.from \(source)"), at: 1) }
        if let repository = row.repositoryDetail { parts.append(repository) }
        if isTask { parts.insert(String(localized: "drawer.taskLabel"), at: 0) }
        if let age { parts.append(age) }
        if row.isPrivate { parts.append(String(localized: "drawer.private")) }
        if let line = row.reviewLine { parts.append(DrawerRowView.spokenSentence(of: line)) }
        return parts.joined(separator: ". ")
    }
}

/// The status mark at the start of a row: a dot, a spinning arc or a glyph. Color is never the only signal: the
/// subtitle says the same in words, except on a stopped, closed, paused or waiting row, where the repository replaces
/// the word and the glyph itself differs.
struct DrawerIndicator: View {
    let indicator: DrawerRow.Indicator

    var body: some View {
        Group {
            switch indicator {
            case .needsYou: dot(Color.yakWarningDot)
            case .blocked: dot(Color.yakFailure)
            case .running: dot(Color.yakSlate)
            case .paused: glyph("pause.fill")
            case .waiting: glyph("clock")
            case .notSent: glyph("arrow.up.circle").symbolVariant(.slash)
            case .unseen: dot(Color.yakInfo)
            case .stopped: glyph("stop.fill")
            case .closed: Circle().strokeBorder(Color.yakFaint, lineWidth: 1.5).frame(width: 8, height: 8)
            case .other: glyph("circle.dotted")
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .accessibilityHidden(true)
    }

    private func dot(_ color: Color) -> some View { Circle().fill(color).frame(width: 8, height: 8) }

    private func glyph(_ name: String) -> some View {
        Image(systemName: name).font(.yak(.footnote, .semibold)).foregroundStyle(Color.yakMuted)
    }
}

/// A small label on the quiet fill at the start of a row's second line: a task's mark and a thread's source.
struct DrawerBadge: View {
    let text: String
    var systemImage: String?

    /// A longer label from a newer server ends in an ellipsis here, so it cannot push into the age column.
    @ScaledMetric(relativeTo: .caption) private var maxWidth = Design.Size.badgeMaxWidth
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    /// A short badge hugs its text; a long label is cut at the cap with an ellipsis. Standard modifiers only: the badge is
    /// sized by its content (`fixedSize`), and the text's own frame caps that content. A custom layout in a list row
    /// trips the audit's Dynamic Type check.
    var body: some View {
        HStack(spacing: Design.Space.tight) {
            if let systemImage { Image(systemName: systemImage).imageScale(.small) }
            Text(text).lineLimit(1).truncationMode(.tail)
                .frame(maxWidth: textCap, alignment: .leading)
                .fixedSize(horizontal: false, vertical: true)
        }
        .font(.yakBadge).foregroundStyle(Color.yakText)
        .padding(.horizontal, Design.Space.small).padding(.vertical, Design.Space.line)
        .background(Color.yakBubble, in: .rect(cornerRadius: Design.Radius.badge))
        .fixedSize(horizontal: true, vertical: false)
        // The text size rebuilds the badge, so the audit's size changes are never read from a view that kept the old one.
        .id(dynamicTypeSize)
        .layoutPriority(1)
        .accessibilityElement(children: .combine)
    }

    /// What is left of the cap for the text, after the padding and the icon.
    private var textCap: CGFloat {
        max(maxWidth - 2 * Design.Space.small - (systemImage == nil ? 0 : Design.Space.medium + Design.Space.tight), 0)
    }
}

/// One action for each pull request of a row: "Open pull request 271".
private struct PullRequestActions: ViewModifier {
    let links: [ReviewLine.Link]
    let open: (ReviewLine.Link) -> Void

    func body(content: Content) -> some View {
        links.reduce(AnyView(content)) { view, link in
            AnyView(
                view.accessibilityAction(named: Text("drawer.review.open \(link.text)")) { open(link) })
        }
    }
}
