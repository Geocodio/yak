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
    /// True for the thread the window shows.
    var isSelected = false
    /// The long-press preview shows the whole title and subtitle.
    var showsEverything = false

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @ScaledMetric(relativeTo: .body) private var indicatorSize = 12.0
    @ScaledMetric(relativeTo: .body) private var indentation = 18.0

    var body: some View {
        HStack(spacing: 0) {
            if row.depth > 0 { nesting }
            Button(action: open) {
                HStack(alignment: .top, spacing: Design.Space.snug) {
                    if !isLarge {
                        DrawerIndicator(indicator: row.indicator)
                            .frame(width: indicatorSize, height: indicatorSize)
                            .padding(.top, Design.Space.small)
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        title
                        detailLine
                        if isLarge {
                            // The indicator moves in front of the age so the title keeps the whole width.
                            HStack(spacing: 8) {
                                DrawerIndicator(indicator: row.indicator).frame(width: 22, height: 22)
                                trailing(isInline: true)
                            }
                        }
                    }
                    Spacer(minLength: 4)
                    if isRunning { ProgressView().frame(width: 22, height: 22) }
                    if !isLarge { trailing(isInline: false) }
                }
                .padding(.leading, isLarge ? 12 : (row.depth == 0 ? Design.Space.snug : 8))
                .padding(.trailing, row.childCount > 0 ? 0 : Design.Space.medium)
                .padding(.vertical, 8)
                .frame(minHeight: isLarge ? 44 : Design.Size.row)
                .contentShape(.rect)
            }
            .buttonStyle(RowButtonStyle(isSelected: isSelected))
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(accessibilityLabel)
            .accessibilityAddTraits(isSelected ? .isSelected : [])
            .accessibilityIdentifier("drawer.row.\(row.id)")
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

    /// The title; an unread thread's is semibold, with a small dot after it.
    private var title: some View {
        let isUnread = row.indicator == .unseen
        var name = AttributedString(row.title)
        name.font = isUnread ? .yak(.subheadline, .semibold) : .yakRowTitle
        // A closed thread reads dimmed, in the quiet text color.
        name.foregroundColor = row.indicator == .closed ? .yakMuted : .yakText
        if isUnread {
            var dot = AttributedString(" \u{25CF}")
            dot.font = .system(size: 6)
            dot.foregroundColor = .yakInfo
            name += dot
        }
        return Text(name).multilineTextAlignment(.leading).lineLimit(wrapsLines ? nil : 1)
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
                        DrawerBadge(text: source).accessibilityIdentifier("drawer.sourceLabel")
                    }
                    if isTask {
                        DrawerBadge(text: String(localized: "drawer.taskLabel"), systemImage: "checklist")
                            .accessibilityIdentifier("drawer.taskLabel")
                    }
                }
                .layoutPriority(1)
            }
            if let repository = row.repositoryDetail {
                Text(repository).font(.yakRowRepository).foregroundStyle(subtitleColor)
                    .multilineTextAlignment(.leading)
                    .lineLimit(wrapsLines ? nil : 1).truncationMode(.middle)
            } else {
                Text(subtitle).font(.yakRowDetail).foregroundStyle(subtitleColor)
                    .multilineTextAlignment(.leading)
                    .lineLimit(wrapsLines ? nil : (row.indicator == .blocked ? 2 : 1))
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

    private var accessibilityLabel: String {
        var parts = [row.title.isEmpty ? String(localized: "drawer.untitled") : row.title, subtitle]
        if let source = row.source { parts.insert(String(localized: "drawer.from \(source)"), at: 1) }
        if let repository = row.repositoryDetail { parts.append(repository) }
        if isTask { parts.insert(String(localized: "drawer.taskLabel"), at: 0) }
        if let age { parts.append(age) }
        if row.isPrivate { parts.append(String(localized: "drawer.private")) }
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

    var body: some View {
        CappedWidth(limit: maxWidth) { label }
    }

    private var label: some View {
        HStack(spacing: Design.Space.tight) {
            if let systemImage { Image(systemName: systemImage).imageScale(.small) }
            Text(text).lineLimit(1)
        }
        .font(.yakBadge).foregroundStyle(Color.yakText)
        .padding(.horizontal, Design.Space.small).padding(.vertical, Design.Space.line)
        .background(Color.yakBubble, in: .rect(cornerRadius: Design.Radius.badge))
        .layoutPriority(1)
        .accessibilityElement(children: .combine)
    }
}

/// Offers its child at most `limit` points of width and takes the child's own size, so a short badge hugs its text and a
/// long one is cut at the limit.
private struct CappedWidth: Layout {
    let limit: CGFloat

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        guard let child = subviews.first else { return .zero }
        return child.sizeThatFits(ProposedViewSize(width: min(proposal.width ?? limit, limit), height: proposal.height))
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        subviews.first?.place(
            at: bounds.origin, anchor: .topLeading,
            proposal: ProposedViewSize(width: bounds.width, height: bounds.height))
    }
}
