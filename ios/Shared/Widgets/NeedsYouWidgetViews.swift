import SwiftUI
import WidgetKit
import YakKit

/// The home screen and lock screen views of both widgets. They read a finished `WidgetDisplay`; every rule about what
/// shows, and every private title, is decided in YakKit before they run.

extension WidgetDisplay {
    fileprivate var headerColor: Color { kind == .running ? WidgetChrome.success : WidgetChrome.accent }

    fileprivate var smallHeader: LocalizedStringResource {
        kind == .running ? WidgetStrings.runningHeader : WidgetStrings.needsYouHeader
    }

    fileprivate var mediumHeader: LocalizedStringResource {
        kind == .running
            ? WidgetStrings.runningHeader(on: instanceName) : WidgetStrings.needsYouHeader(on: instanceName)
    }

    fileprivate var emptySentence: LocalizedStringResource {
        kind == .running ? WidgetStrings.runningEmpty : WidgetStrings.needsYouEmpty
    }

    fileprivate var summaryLabel: LocalizedStringResource {
        let first = rows.first?.title.spoken ?? ""
        if kind == .running {
            return count == 0
                ? WidgetStrings.a11yRunningNone(instance: instanceName)
                : WidgetStrings.a11yRunning(count: countLabel, instance: instanceName)
        }
        return count == 0
            ? WidgetStrings.a11yNeedsYouNone(instance: instanceName)
            : WidgetStrings.a11yNeedsYou(count: countLabel, instance: instanceName, first: first)
    }
}

// MARK: Small

struct WidgetSmallView: View {
    let display: WidgetDisplay
    let now: Date
    @ScaledMetric(relativeTo: .largeTitle) private var countSize = 54

    var body: some View {
        if display.state != .content {
            WidgetStateView(state: display.state, family: .small)
        } else {
            VStack(alignment: .leading, spacing: 2) {
                Text(display.smallHeader).font(.footnote.weight(.semibold)).foregroundStyle(display.headerColor)
                    .widgetLines(1)
                Text(verbatim: display.countLabel).font(.system(size: countSize, weight: .bold).monospacedDigit())
                    .kerning(-1).widgetLines(1).minimumScaleFactor(0.5)
                Spacer(minLength: 0)
                if let row = display.rows.first {
                    WidgetRowTitleText(title: row.title).font(.footnote.weight(.medium)).widgetLines(2)
                    display.detail(of: row, isLast: true, now: now).font(.caption.weight(.medium)).foregroundStyle(
                        display.isStale ? .tertiary : .secondary
                    ).widgetLines(1)
                } else {
                    Text(display.emptySentence).font(.footnote).foregroundStyle(.secondary).widgetLines(2)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(Text(display.summaryLabel))
            .accessibilityHint(Text(display.link == nil ? WidgetStrings.opensYak : WidgetStrings.opensThread))
        }
    }
}

// MARK: Medium

struct WidgetMediumView: View {
    let display: WidgetDisplay
    let now: Date
    @Environment(\.dynamicTypeSize) private var textSize

    private var visibleRows: Int {
        switch textSize {
        case ...DynamicTypeSize.large: 3
        case ...DynamicTypeSize.xxLarge: 2
        default: 1
        }
    }

    var body: some View {
        if display.state != .content {
            WidgetStateView(state: display.state, family: .medium)
        } else {
            VStack(alignment: .leading, spacing: 6) {
                HStack {
                    Text(display.mediumHeader).font(.footnote.weight(.semibold)).foregroundStyle(display.headerColor)
                        .widgetLines(1)
                    Spacer(minLength: 8)
                    Text(verbatim: display.countLabel).font(.footnote).foregroundStyle(.secondary)
                }
                if display.rows.isEmpty {
                    Text(display.emptySentence).font(.subheadline).foregroundStyle(.secondary)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    // Fewer rows when the text size makes them taller, so none is clipped.
                    rowStack(Array(display.rows.prefix(visibleRows)))
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        }
    }

    private func rowStack(_ rows: [WidgetRowContent]) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            ForEach(rows) { row in
                Link(destination: row.link) {
                    HStack(spacing: 8) {
                        WidgetDot(dot: row.dot)
                        VStack(alignment: .leading, spacing: 0) {
                            WidgetRowTitleText(title: row.title).font(.subheadline.weight(.medium)).widgetLines(1)
                            detailLine(row: row, isLast: row.id == rows.last?.id)
                        }
                        Spacer(minLength: 0)
                    }
                    .frame(minHeight: 34, alignment: .leading)
                    .foregroundStyle(.primary)
                }
                .tint(.primary)
                .accessibilityElement(children: .ignore)
                .accessibilityLabel(Text(verbatim: "\(row.title.spoken). \(detailSpoken(row))"))
                .accessibilityHint(Text(WidgetStrings.opensThread))
            }
            Spacer(minLength: 0)
        }
    }

    private func detailLine(row: WidgetRowContent, isLast: Bool) -> some View {
        HStack(spacing: 0) {
            display.detail(of: row, isLast: isLast, now: now)
            if let date = row.lastActivityAt, !(display.isStale && isLast) {
                Text(verbatim: ", ")
                Text(WidgetStrings.age(from: date, to: now))
            }
        }
        .font(.caption.weight(.medium)).foregroundStyle(display.isStale && isLast ? .tertiary : .secondary).widgetLines(
            1)
    }

    private func detailSpoken(_ row: WidgetRowContent) -> String {
        if let detail = row.detail, !detail.isEmpty { return detail }
        return String(localized: display.kind == .running ? WidgetStrings.working : WidgetStrings.reason(row.reason))
    }
}

// MARK: Lock screen

struct WidgetRectangularView: View {
    let display: WidgetDisplay

    var body: some View {
        if display.state != .content {
            WidgetStateView(state: display.state, family: .accessoryRectangular)
        } else {
            VStack(alignment: .leading, spacing: 1) {
                HStack(spacing: 4) {
                    WidgetDot(dot: .accent)
                    Text(lockHeadline).font(.footnote.weight(.semibold)).widgetLines(1)
                }
                if let row = display.rows.first {
                    WidgetRowTitleText(title: row.title).font(.caption.weight(.medium)).foregroundStyle(.secondary)
                        .widgetLines(2)
                        .privacySensitive()
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .leading)
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(Text(display.summaryLabel))
        }
    }

    private var lockHeadline: LocalizedStringResource {
        switch display.count {
        case 0: WidgetStrings.lockNone
        case 1: WidgetStrings.lockOne
        default: WidgetStrings.lockMany(display.countLabel)
        }
    }
}

struct WidgetCircularView: View {
    let display: WidgetDisplay

    var body: some View {
        if display.state != .content {
            WidgetStateView(state: display.state, family: .accessoryCircular)
        } else {
            ZStack {
                AccessoryWidgetBackground()
                Color.clear
                VStack(spacing: 0) {
                    Text(verbatim: display.countLabel).font(.title2.bold()).minimumScaleFactor(0.5).widgetLines(1)
                    Text(WidgetStrings.brand).font(.caption)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(Text(display.summaryLabel))
        }
    }
}

// MARK: States

/// Why a widget shows no list: nothing paired, a removed or unpaired instance, or no data yet.
struct WidgetStateView: View {
    let state: WidgetState
    let family: WidgetFamilySize

    var body: some View {
        Group {
            switch family {
            case .small, .medium:
                VStack(spacing: 8) {
                    Image(systemName: "bubble.left.and.text.bubble.right").font(.title2).foregroundStyle(.secondary)
                        .accessibilityHidden(true)
                    Text(WidgetStrings.stateText(state)).font(.footnote).multilineTextAlignment(.center)
                        .foregroundStyle(.secondary)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            case .accessoryRectangular:
                Text(WidgetStrings.stateShort(state)).font(.footnote.weight(.semibold)).widgetLines(1)
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .leading)
            case .accessoryCircular:
                ZStack {
                    AccessoryWidgetBackground()
                    Color.clear
                    VStack(spacing: 0) {
                        if case .instanceMissing = state {
                            Text(verbatim: "?").font(.title2.bold())
                        } else if case .noInstance = state {
                            Image(systemName: "bubble.left.and.text.bubble.right")
                        } else if case .needsPairing = state {
                            Image(systemName: "exclamationmark.bubble")
                        }
                        if let caption = WidgetStrings.stateCircular(state) {
                            Text(caption).font(.caption2).widgetLines(1).minimumScaleFactor(0.6)
                        }
                        Text(WidgetStrings.brand).font(.caption)
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text(WidgetStrings.stateText(state)))
        .accessibilityHint(Text(WidgetStrings.opensYak))
    }
}

/// The view for one family; the widgets and the debug gallery both use it.
struct WidgetBody: View {
    let display: WidgetDisplay
    let size: WidgetFamilySize
    let now: Date

    var body: some View {
        switch size {
        case .small: WidgetSmallView(display: display, now: now)
        case .medium: WidgetMediumView(display: display, now: now)
        case .accessoryRectangular: WidgetRectangularView(display: display)
        case .accessoryCircular: WidgetCircularView(display: display)
        }
    }
}

/// A line limit that grows by one line at the larger text sizes, so a title or a label is never cut off by the
/// size alone; a tile still shows only what fits.
private struct WidgetLines: ViewModifier {
    let lines: Int
    @Environment(\.dynamicTypeSize) private var textSize

    func body(content: Content) -> some View {
        content.lineLimit(textSize > .large ? lines + 1 : lines)
    }
}

extension View {
    fileprivate func widgetLines(_ lines: Int) -> some View { modifier(WidgetLines(lines: lines)) }
}
