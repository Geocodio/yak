import SwiftUI
import UIKit
import YakKit

/// The thread list's tabs under the Observations tile, and the Filter button at the row's end. Where the four names
/// do not fit (the largest text sizes, a narrow drawer) one menu button names the selected tab and its count.
struct DrawerTabsView: View {
    let tabs: [ThreadListFilter]
    let selected: ThreadListFilter
    let reviewCount: Int
    let filterCount: Int
    let pick: (ThreadListFilter) -> Void
    let openFilter: () -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    /// The room for the names, measured; the width they need is worked out from the fonts, so the row decides
    /// again whenever the text size, the count or the drawer's width changes, and nothing is drawn twice.
    @State private var availableWidth: Double = 0

    var body: some View {
        HStack(spacing: Design.Space.small) {
            tabsOrMenu
                // The tab row stops growing at accessibility1, as the account button does, so the menu and the
                // Filter button share the width; the names themselves never shorten.
                .dynamicTypeSize(...DynamicTypeSize.accessibility1)
            filterButton
        }
        .frame(minHeight: Design.Size.control)
        .onPreferenceChange(AvailableWidthKey.self) { availableWidth = $0 }
    }

    /// The four names when they fit the width at the current text size and count, else the menu button, whatever
    /// the text size or the drawer's width.
    @ViewBuilder private var tabsOrMenu: some View {
        let natural = TabRowMeasure.naturalWidth(
            names: tabs.map { (tab: $0, text: plainName(of: $0), isSelected: $0 == selected) },
            reviewCount: reviewCount,
            size: min(dynamicTypeSize, .accessibility1))
        Group {
            // Above the default text size the names are a menu: segments that fill the row hide how much the text grew,
            // which the accessibility audit reads as text that does not scale.
            if dynamicTypeSize <= .large,
                TabRowFit.segmentsFit(naturalWidth: natural, availableWidth: availableWidth)
            {
                segments
            } else {
                menuButton
            }
        }
        .frame(maxWidth: .infinity)
        .background {
            GeometryReader { proxy in
                Color.clear.preference(key: AvailableWidthKey.self, value: Double(proxy.size.width))
            }
        }
    }

    // MARK: Segments

    private var segments: some View {
        EvenlySpread {
            ForEach(Array(tabs.enumerated()), id: \.element) { index, tab in
                segment(tab, index: index)
            }
        }
        .padding(.horizontal, Design.Space.tight)
        .frame(height: Design.Size.tabRow)
        .background(Color.yakBubble, in: .rect(cornerRadius: Design.Radius.control))
        .accessibilityElement(children: .contain)
        .accessibilityAddTraits(.isTabBar)
        .accessibilityIdentifier("drawer.tabs")
    }

    private func segment(_ tab: ThreadListFilter, index: Int) -> some View {
        let isSelected = tab == selected
        return Button {
            pick(tab)
        } label: {
            HStack(spacing: Design.Space.tight) {
                Text(name(of: tab)).font(.yak(.footnote, isSelected ? .semibold : .medium)).lineLimit(1)
                if tab == .review, reviewCount > 0 { CountPill(count: reviewCount, isOn: isSelected) }
            }
            // The names never shorten: where they do not fit, the row gives way to the menu.
            .fixedSize(horizontal: true, vertical: false)
            .foregroundStyle(isSelected ? Color.yakText : Color.yakMuted)
            .padding(.horizontal, Design.Space.small)
            .frame(minHeight: Design.Size.tabSegment)
            .background {
                if isSelected {
                    RoundedRectangle(cornerRadius: Design.Radius.control).fill(Color.yakTabSelected)
                        .shadow(color: Color.yakShadow, radius: Design.Space.nudge, y: Design.Space.line)
                }
            }
            // The hit area reaches the row's full height, past the control's edge.
            .frame(minHeight: Design.Size.control)
            .contentShape(.rect)
        }
        .buttonStyle(.plain)
        .padding(.vertical, -(Design.Size.control - Design.Size.tabRow) / 2)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(accessibilityName(of: tab))
        .accessibilityAddTraits(isSelected ? [.isButton, .isSelected] : .isButton)
        .accessibilityIdentifier("drawer.tab.\(tab.rawValue)")
    }

    // MARK: Menu

    private var menuButton: some View {
        Menu {
            Picker(
                "drawer.filter.title",
                selection: Binding(get: { selected }, set: { pick($0) })
            ) {
                ForEach(tabs, id: \.self) { tab in
                    Text(menuName(of: tab)).tag(tab)
                }
            }
        } label: {
            HStack(spacing: Design.Space.compact) {
                Text(name(of: selected)).font(.yak(.body, .medium)).lineLimit(1)
                if selected == .review, reviewCount > 0 { CountPill(count: reviewCount, isOn: true) }
                Spacer(minLength: Design.Space.tight)
                Image(systemName: "chevron.down").font(.yak(.footnote, .semibold)).accessibilityHidden(true)
            }
            .foregroundStyle(Color.yakText)
            .padding(.horizontal, Design.Space.medium)
            .frame(maxWidth: .infinity, minHeight: Design.Size.control)
            .background(Color.yakCard, in: .rect(cornerRadius: Design.Radius.control))
            .overlay(
                RoundedRectangle(cornerRadius: Design.Radius.control)
                    .strokeBorder(Color.yakBorderStrong, lineWidth: Design.Space.line)
            )
            .contentShape(.rect(cornerRadius: Design.Radius.control))
        }
        .accessibilityLabel(String(localized: "drawer.tabs.menuLabel \(accessibilityName(of: selected))"))
        .accessibilityIdentifier("drawer.tabs.menu")
    }

    // MARK: Filter button

    private var filterButton: some View {
        Button(action: openFilter) {
            Image(systemName: "line.3.horizontal.decrease").font(.yak(.body, .medium))
                .dynamicTypeSize(...DynamicTypeSize.accessibility1)
                .foregroundStyle(Color.yakText)
                .frame(width: Design.Size.tabRow, height: Design.Size.tabRow)
                .background(Color.yakCard, in: .rect(cornerRadius: Design.Radius.control))
                .overlay(
                    RoundedRectangle(cornerRadius: Design.Radius.control)
                        .strokeBorder(Color.yakBorderStrong, lineWidth: Design.Space.line)
                )
                .overlay(alignment: .topTrailing) {
                    if filterCount > 0 {
                        Text(filterCount, format: .number).font(.yak(.caption, .semibold))
                            .foregroundStyle(Color.yakAccentInk)
                            .padding(.horizontal, Design.Space.small).padding(.vertical, Design.Space.hairline)
                            .background(Color.yakAccent, in: .capsule)
                            .offset(x: Design.Space.tight, y: -Design.Space.tight)
                            .accessibilityHidden(true)
                    }
                }
                // The hit area reaches 44 points, past the control's edge into the margin.
                .padding(Design.Space.small)
                .contentShape(.rect)
                .padding(-Design.Space.small)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(
            filterCount > 0
                ? Text("drawer.filterButton.count \(filterCount)") : Text("drawer.filterButton.label")
        )
        .accessibilityIdentifier("drawer.filter")
    }

    // MARK: Words

    private func name(of tab: ThreadListFilter) -> LocalizedStringKey {
        switch tab {
        case .review: "drawer.tab.review"
        case .everyone: "drawer.tab.everyone"
        case .closed: "drawer.tab.closed"
        default: "drawer.tab.mine"
        }
    }

    /// The tab's name as the row draws it.
    private func plainName(of tab: ThreadListFilter) -> String {
        switch tab {
        case .review: String(localized: "drawer.tab.review")
        case .everyone: String(localized: "drawer.tab.everyone")
        case .closed: String(localized: "drawer.tab.closed")
        default: String(localized: "drawer.tab.mine")
        }
    }

    /// What VoiceOver says for a tab: the Review tab names its count, as a part of the tab.
    private func accessibilityName(of tab: ThreadListFilter) -> String {
        switch tab {
        case .review:
            reviewCount > 0
                ? String(localized: "drawer.tab.reviewCount \(reviewCount)") : String(localized: "drawer.tab.review")
        case .everyone: String(localized: "drawer.tab.everyone")
        case .closed: String(localized: "drawer.tab.closed")
        default: String(localized: "drawer.tab.mine")
        }
    }

    private func menuName(of tab: ThreadListFilter) -> String {
        tab == .review && reviewCount > 0
            ? String(localized: "drawer.tab.reviewMenu \(reviewCount)") : accessibilityName(of: tab)
    }
}

/// The count after a tab's name: the brand color on the selected tab, the quiet pressed fill otherwise.
private struct CountPill: View {
    let count: Int
    let isOn: Bool

    var body: some View {
        Text(ThreadTabs.badgeText(count)).font(.yak(.caption, .semibold))
            .foregroundStyle(isOn ? Color.yakAccentInk : Color.yakText)
            .padding(.horizontal, Design.Space.small).padding(.vertical, Design.Space.hairline)
            .background(isOn ? Color.yakSlate : Color.yakFillStrong, in: .capsule)
    }
}

/// The chips of the filters that are on, under the tabs, and Clear.
struct DrawerFilterChips: View {
    let chips: [(key: String, label: String)]
    let remove: (String) -> Void
    let clear: () -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        let layout =
            dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: Design.Space.tight))
            : AnyLayout(HStackLayout(spacing: Design.Space.compact))
        ScrollView(.horizontal, showsIndicators: false) {
            layout {
                ForEach(chips, id: \.key) { chip in
                    Button {
                        remove(chip.key)
                    } label: {
                        HStack(spacing: Design.Space.tight) {
                            Text(chip.label).lineLimit(1)
                            Image(systemName: "xmark").imageScale(.small).accessibilityHidden(true)
                        }
                        .font(.yak(.footnote, .medium)).foregroundStyle(Color.yakText)
                        .padding(.horizontal, Design.Space.compact)
                        .frame(minHeight: Design.Size.tabSegment)
                        .background(Color.yakBubble, in: .rect(cornerRadius: Design.Radius.control))
                        .frame(minHeight: Design.Size.control)
                        .contentShape(.rect)
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(Text("drawer.filterSheet.chipRemove \(chip.label)"))
                    .accessibilityIdentifier("drawer.chip.\(chip.key)")
                }
                Button(action: clear) {
                    Text("drawer.filterSheet.chipClear").font(.yak(.footnote, .medium))
                        .foregroundStyle(Color.yakAction)
                        .padding(.horizontal, Design.Space.compact)
                        .frame(minHeight: Design.Size.control)
                        .contentShape(.rect)
                }
                .buttonStyle(.plain)
                .accessibilityIdentifier("drawer.chip.clear")
            }
        }
        .scrollBounceBehavior(.basedOnSize)
    }
}

/// Lays its children in a row, each at its own natural width plus an equal share of the room left over, so the
/// segments fill the control and no name is ever cut. Its natural width is the sum of the names.
private struct EvenlySpread: Layout {
    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let sizes = subviews.map { $0.sizeThatFits(.unspecified) }
        let natural = sizes.reduce(0) { $0 + $1.width }
        return CGSize(width: max(natural, proposal.width ?? natural), height: sizes.map(\.height).max() ?? 0)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let sizes = subviews.map { $0.sizeThatFits(.unspecified) }
        let spare = max(0, bounds.width - sizes.reduce(0) { $0 + $1.width })
        let extra = subviews.isEmpty ? 0 : spare / CGFloat(subviews.count)
        var x = bounds.minX
        for (subview, size) in zip(subviews, sizes) {
            let width = size.width + extra
            subview.place(
                at: CGPoint(x: x, y: bounds.midY), anchor: .leading,
                proposal: ProposedViewSize(width: width, height: bounds.height))
            x += width
        }
    }
}

private struct AvailableWidthKey: PreferenceKey {
    static let defaultValue: Double = 0
    static func reduce(value: inout Double, nextValue: () -> Double) { value = nextValue() }
}

/// What the four names take, from the fonts the row draws them in, at a text size.
private enum TabRowMeasure {
    static func naturalWidth(
        names: [(tab: ThreadListFilter, text: String, isSelected: Bool)], reviewCount: Int, size: DynamicTypeSize
    )
        -> Double
    {
        let traits = UITraitCollection(preferredContentSizeCategory: category(of: size))
        func width(_ text: String, style: UIFont.TextStyle, base: CGFloat, weight: Font.Weight) -> Double {
            let face = Font.yakFaceName(weight: weight, isMonospaced: false, isBoldText: Font.yakIsBoldText)
            let font = UIFont(name: face, size: base) ?? .systemFont(ofSize: base)
            let scaled = UIFontMetrics(forTextStyle: style).scaledFont(for: font, compatibleWith: traits)
            return Double((text as NSString).size(withAttributes: [.font: scaled]).width)
        }
        // The row's inset on both sides, and a point or two of slack for rounding.
        var total = 2 * Double(Design.Space.tight) + 2
        for item in names {
            var itemWidth = width(
                item.text, style: .footnote, base: Font.yakPointSize(of: .footnote),
                weight: item.isSelected ? .semibold : .medium)
            if item.tab == .review, reviewCount > 0 {
                itemWidth += Double(Design.Space.tight) + 2 * Double(Design.Space.small)
                itemWidth += width(
                    ThreadTabs.badgeText(reviewCount), style: .caption1, base: Font.yakPointSize(of: .caption),
                    weight: .semibold)
            }
            total += itemWidth + 2 * Double(Design.Space.small)
        }
        return total
    }

    private static func category(of size: DynamicTypeSize) -> UIContentSizeCategory {
        switch size {
        case .xSmall: .extraSmall
        case .small: .small
        case .medium: .medium
        case .large: .large
        case .xLarge: .extraLarge
        case .xxLarge: .extraExtraLarge
        case .xxxLarge: .extraExtraExtraLarge
        case .accessibility1: .accessibilityMedium
        case .accessibility2: .accessibilityLarge
        case .accessibility3: .accessibilityExtraLarge
        case .accessibility4: .accessibilityExtraExtraLarge
        case .accessibility5: .accessibilityExtraExtraExtraLarge
        @unknown default: .large
        }
    }
}
