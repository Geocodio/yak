import SwiftUI
import YakKit

/// One line of the drawer: the indicator, the title and subtitle, the age, a lock for a private thread and the
/// chevron that shows its sub-threads. The whole row of a thread that needs the person carries a light tint.
struct DrawerRowView: View {
    let row: DrawerRow
    let now: Date
    let open: () -> Void
    let toggle: () -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @ScaledMetric(relativeTo: .body) private var indicatorSize = 22.0
    @ScaledMetric(relativeTo: .body) private var indentation = 18.0

    var body: some View {
        HStack(spacing: 0) {
            if row.depth > 0 { nesting }
            Button(action: open) {
                HStack(alignment: .top, spacing: 12) {
                    if !isLarge {
                        DrawerIndicator(indicator: row.indicator)
                            .frame(width: indicatorSize, height: indicatorSize)
                            .padding(.top, 1)
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        if isTask {
                            Label("drawer.taskLabel", systemImage: "checklist").font(.caption.weight(.semibold))
                                .foregroundStyle(Color.yakMuted).accessibilityIdentifier("drawer.taskLabel")
                        }
                        Text(row.title).font(.body).foregroundStyle(.primary)
                            .multilineTextAlignment(.leading).lineLimit(isLarge ? nil : 3)
                        Text(subtitle).font(.footnote).foregroundStyle(Color.yakMuted)
                            .multilineTextAlignment(.leading).lineLimit(isLarge ? nil : 3)
                        if isLarge {
                            // The indicator moves in front of the age so the title keeps the whole width.
                            HStack(spacing: 8) {
                                DrawerIndicator(indicator: row.indicator).frame(width: 22, height: 22)
                                trailing(isInline: true)
                            }
                        }
                    }
                    Spacer(minLength: 4)
                    if !isLarge { trailing(isInline: false) }
                }
                .padding(.leading, isLarge ? 12 : (row.depth == 0 ? 20 : 8))
                .padding(.trailing, row.childCount > 0 ? 0 : (isLarge ? 12 : 20))
                .padding(.vertical, 8)
                .frame(minHeight: 44)
                .contentShape(.rect)
            }
            .buttonStyle(.plain)
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(accessibilityLabel)
            .accessibilityIdentifier("drawer.row.\(row.id)")
            if row.childCount > 0 {
                Button(action: toggle) {
                    Image(systemName: "chevron.right").font(.footnote.weight(.semibold))
                        .foregroundStyle(Color.yakMuted)
                        .rotationEffect(.degrees(row.isExpanded ? 90 : 0))
                        .frame(width: 44, height: 44).contentShape(.rect)
                }
                .buttonStyle(.plain)
                .padding(.trailing, 8)
                .accessibilityLabel(row.isExpanded ? "drawer.hideChildren" : "drawer.showChildren")
                .accessibilityIdentifier("drawer.toggle.\(row.id)")
            }
        }
        .background(row.indicator == .needsYou ? Color.yakAction.opacity(0.10) : Color.clear)
    }

    /// A neutral hairline that shows the nesting.
    private var nesting: some View {
        HStack(spacing: 0) {
            Color.clear.frame(width: (isLarge ? 12 : 20) + (isLarge ? 14 : indentation) * CGFloat(row.depth - 1))
            Rectangle().fill(Color.primary.opacity(0.15)).frame(width: 1)
        }
    }

    /// At accessibility text sizes the title needs the whole width, so the age and the lock go under the subtitle.
    private var isLarge: Bool { dynamicTypeSize.isAccessibilitySize }

    @ViewBuilder private func trailing(isInline: Bool) -> some View {
        let layout =
            isInline ? AnyLayout(HStackLayout(spacing: 8)) : AnyLayout(VStackLayout(alignment: .trailing, spacing: 4))
        layout {
            if let age { Text(age).font(.footnote.weight(.medium)).foregroundStyle(.primary) }
            if row.isPrivate {
                Image(systemName: "lock.fill").font(.caption).foregroundStyle(Color.yakMuted)
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
        if isTask { parts.insert(String(localized: "drawer.taskLabel"), at: 0) }
        if let age { parts.append(age) }
        if row.isPrivate { parts.append(String(localized: "drawer.private")) }
        return parts.joined(separator: ". ")
    }
}

/// The status mark at the start of a row: a dot, a spinning arc or a glyph. Color is never the only signal,
/// because the subtitle says the same in words.
struct DrawerIndicator: View {
    let indicator: DrawerRow.Indicator
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var isSpinning = false

    var body: some View {
        Group {
            switch indicator {
            case .needsYou: dot(Color.yakAction)
            case .unseen: dot(.blue)
            case .blocked: dot(.orange)
            case .running:
                Circle().trim(from: 0, to: 0.7).stroke(Color.yakMuted, style: .init(lineWidth: 2, lineCap: .round))
                    .frame(width: 14, height: 14)
                    .rotationEffect(.degrees(isSpinning ? 360 : 0))
                    .animation(
                        StillMotion.isOn(reduceMotion: reduceMotion)
                            ? nil : .linear(duration: 1.1).repeatForever(autoreverses: false),
                        value: isSpinning
                    )
                    .onAppear { isSpinning = true }
            case .paused: glyph("pause.fill")
            case .stopped, .closed: glyph("checkmark")
            case .waiting: glyph("clock")
            case .notSent: glyph("arrow.up.circle").symbolVariant(.slash)
            case .other: Circle().stroke(Color.primary.opacity(0.3), lineWidth: 1.5).frame(width: 10, height: 10)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .accessibilityHidden(true)
    }

    private func dot(_ color: Color) -> some View { Circle().fill(color).frame(width: 10, height: 10) }

    private func glyph(_ name: String) -> some View {
        Image(systemName: name).font(.footnote.weight(.semibold)).foregroundStyle(Color.yakMuted)
    }
}
