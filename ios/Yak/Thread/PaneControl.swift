import SwiftUI
import YakKit

/// The pane button of the bar and of the trailing strip. A tap opens the menu of panes; press, slide and release
/// picks one in a single motion, which is how the system's menu works. The menu carries the identifier `pane.menu`
/// and the label "Thread panes"; with more than one pane the menu shows that heading too.
struct PaneMenuButton: View {
    let panes: [PaneID]
    /// The pane this button's bar shows.
    let current: PaneID
    /// The pane in the other slot of a two-slot layout.
    var beside: PaneID?
    /// The bar's slot in a two-slot layout; nil for the one bar of a single pane.
    var slot: SlotPosition?
    let select: (PaneID) -> Void

    private var focused: Binding<PaneID> { Binding(get: { current }, set: select) }

    var body: some View {
        Menu {
            if slot != nil {
                slotItems
            } else if panes.count > 1 {
                Section {
                    picker
                } header: {
                    Text("pane.menu")
                }
            } else {
                picker
            }
            SecondPaneMenuSection()
        } label: {
            HStack(spacing: 4) {
                Image(systemName: PaneCatalog.descriptor(for: current)?.symbol ?? "bubble.left")
                Image(systemName: "chevron.up.chevron.down").font(.caption2.weight(.semibold))
            }
            .font(.body)
            .foregroundStyle(.primary)
            .padding(.leading, Design.Space.medium).padding(.trailing, Design.Space.compact + 2)
            .frame(minWidth: Design.Size.control, minHeight: Design.Size.control)
            .background(Color.yakFillStrong, in: .capsule)
            .contentShape(.capsule)
            .hoverEffect(.highlight)
        }
        .tint(.primary)
        .popoverTip(PaneSlideTip(), arrowEdge: .bottom)
        .onChange(of: panes.count, initial: true) {
            PaneSlideTip.isEligible = GestureTips.eligibleForPaneSlide(usesMenuButton: true, paneCount: panes.count)
        }
        .onDisappear { PaneSlideTip.isEligible = false }
        .menuOrder(.fixed)
        .accessibilityLabel(Text("pane.menu"))
        .accessibilityValue(Text(PaneCatalog.descriptor(for: current)?.name ?? ""))
        .accessibilityIdentifier(slot.map { "pane.button.\($0.rawValue)" } ?? "pane.menu")
    }

    /// One row per pane of a two-slot layout: a checkmark on this bar's pane, "Shown beside" under the other bar's.
    private var slotItems: some View {
        ForEach(panes, id: \.self) { id in
            if let descriptor = PaneCatalog.descriptor(for: id) {
                Button {
                    select(id)
                } label: {
                    Label {
                        Text(descriptor.name)
                        if id == beside { Text("pane.slot.shownBeside") }
                    } icon: {
                        Image(systemName: id == current ? "checkmark" : descriptor.symbol)
                    }
                }
                .accessibilityIdentifier("pane.menu.\(id.rawValue)")
            }
        }
    }

    private var picker: some View {
        Picker(
            selection: Binding(
                get: { focused.wrappedValue },
                set: {
                    focused.wrappedValue = $0
                    PaneSlideTip.recordUse()
                })
        ) {
            ForEach(panes, id: \.self) { id in
                if let descriptor = PaneCatalog.descriptor(for: id) {
                    Label(descriptor.name, systemImage: descriptor.symbol).tag(id)
                        .accessibilityIdentifier("pane.menu.\(id.rawValue)")
                }
            }
        } label: {
            Text("pane.menu")
        }
        .pickerStyle(.inline)
    }
}

/// How the focused and the second pane show in the rail and the row: filled and outlined capsules, or the system's
/// tint alone (the second pane then has no mark). One switch, so the look can change after the device pass.
enum PaneMarkStyle { case capsules, systemTint }

enum PaneControl {
    static let markStyle: PaneMarkStyle = .capsules
}

/// The capsule behind a pane's item while two panes are shown: neutral, never the accent.
struct PaneMarkLabel: ViewModifier {
    let mark: PaneMark?

    @ViewBuilder func body(content: Content) -> some View {
        let style = PaneControl.markStyle
        if mark == nil {
            content
        } else {
            marked(content, style: style)
        }
    }

    private func marked(_ content: Content, style: PaneMarkStyle) -> some View {
        content
            .foregroundStyle(mark == .focused && style == .capsules ? Color.yakBackground : .primary)
            .background {
                if mark == .focused, style == .capsules {
                    RoundedRectangle(cornerRadius: Design.Radius.row).fill(Color.primary)
                } else if mark == .second, style == .capsules {
                    RoundedRectangle(cornerRadius: Design.Radius.row).strokeBorder(Color.yakMuted, lineWidth: 1.5)
                }
            }
    }
}

extension PaneMark {
    /// "Shown" or "Shown beside" while two panes are shown, nil otherwise.
    var accessibilityWords: Text? {
        switch self {
        case .focused: Text("pane.slot.shown")
        case .second: Text("pane.slot.shownBeside")
        case .none: nil
        }
    }
}

/// The panes as a row of icon-over-name buttons, the selected one with a neutral fill.
struct PaneRow: View {
    let panes: [PaneID]
    let focused: PaneID
    let thread: ThreadModel
    let select: (PaneID) -> Void
    @Environment(SceneModel.self) private var scene

    /// The marks of two shown panes; empty while one pane shows, which keeps the one-pane look.
    private var marks: [PaneID: PaneMark] {
        guard let plan = scene.plan, plan.slots.count == 2 else { return [:] }
        return PaneLayoutRules.marks(state: scene.paneState, plan: plan)
    }

    var body: some View {
        ViewThatFits(in: .horizontal) {
            row
            ScrollView(.horizontal, showsIndicators: false) { row }
        }
    }

    private var row: some View {
        HStack(spacing: Design.Space.tight) {
            ForEach(panes, id: \.self) { id in
                if let descriptor = PaneCatalog.descriptor(for: id) {
                    PaneRowButton(
                        descriptor: descriptor, isSelected: id == focused, mark: marks[id],
                        badge: PaneBadge.make(descriptor, thread)
                    ) { select(id) }
                }
            }
        }
    }
}

private struct PaneRowButton: View {
    let descriptor: PaneDescriptor
    let isSelected: Bool
    /// Nil while one pane shows.
    let mark: PaneMark?
    let badge: PaneBadge?
    let select: () -> Void

    var body: some View {
        Button(action: select) {
            VStack(spacing: Design.Space.hairline * 2) {
                Image(systemName: descriptor.symbol).font(.body)
                    .overlay(alignment: .topTrailing) { badge.map { PaneBadgeView(badge: $0).offset(x: 6, y: -3) } }
                Text(descriptor.name).font(.caption2.weight(.semibold)).lineLimit(1).fixedSize(
                    horizontal: true, vertical: false)
            }
            .foregroundStyle(.primary)
            .padding(.horizontal, Design.Space.tight).padding(.vertical, Design.Space.tight)
            .frame(minWidth: Design.Size.paneItemWidth, minHeight: Design.Size.paneItemHeight)
            .background(
                mark == nil && isSelected ? Color.yakFillStrong : .clear,
                in: .rect(cornerRadius: Design.Radius.row)
            )
            .modifier(PaneMarkLabel(mark: mark))
            .contentShape(.rect)
            .hoverEffect(.highlight)
        }
        .buttonStyle(.plain)
        .secondPaneContextMenu(for: descriptor.id)
        .accessibilityLabel(Text(descriptor.name))
        .accessibilityValue(valueText)
        .accessibilityAddTraits(isSelected ? .isSelected : [])
        .accessibilityIdentifier("pane.row.\(descriptor.id.rawValue)")
    }
}

extension PaneRowButton {
    fileprivate var valueText: Text { paneAccessibilityValue(mark: mark, badge: badge) }
}

/// "Shown" or "Shown beside", then the badge's words, in one value.
func paneAccessibilityValue(mark: PaneMark?, badge: PaneBadge?) -> Text {
    let words = mark?.accessibilityWords
    let badgeText = badge?.accessibilityText
    if let words, let badgeText { return Text("\(words), \(badgeText)") }
    return words ?? badgeText ?? Text("")
}

/// What a pane's button carries: the dot that asks for attention, or a number.
enum PaneBadge: Equatable {
    case question
    case count(Int)

    /// A number wins over the dot; a count of zero or less shows nothing.
    @MainActor static func make(_ descriptor: PaneDescriptor, _ thread: ThreadModel) -> PaneBadge? {
        if let count = descriptor.badgeCount(thread), count > 0 { return .count(count) }
        return descriptor.hasBadge(thread) ? .question : nil
    }

    var accessibilityText: Text {
        switch self {
        case .question: Text("pane.badge.question")
        case .count(let number): Text("pane.badge.count \(number)")
        }
    }
}

struct PaneBadgeView: View {
    let badge: PaneBadge

    var body: some View {
        switch badge {
        case .question:
            QuestionBadge()
        case .count(let number):
            // No pane returns a count yet. Look at this badge in light and dark when the first one does.
            Text(number, format: .number).font(.caption2.weight(.bold)).foregroundStyle(Color.yakOnBadge)
                .padding(.horizontal, 4).frame(minWidth: 14, minHeight: 14)
                .background(Color.yakBadge, in: .capsule)
                .overlay(Capsule().stroke(Color.yakBackground, lineWidth: 1.5))
                .accessibilityHidden(true)
        }
    }
}

/// The dot on Chat while a question is open. A system color, never the rust accent.
struct QuestionBadge: View {
    var body: some View {
        Circle().fill(Color.blue).frame(width: 9, height: 9)
            .overlay(Circle().stroke(Color.yakBackground, lineWidth: 1.5))
            .accessibilityHidden(true)
    }
}

/// The panes as bottom-bar toolbar items for the system's vertical strip. Only the placement that gets a strip
/// declares them, so the system's pill never covers the app's own bar.
@available(iOS 27.1, *)
struct PaneStripToolbar: ToolbarContent {
    let panes: [PaneID]
    @Binding var focused: PaneID
    let thread: ThreadModel
    /// The marks of two shown panes; empty while one pane shows.
    var marks: [PaneID: PaneMark] = [:]
    /// Higher stays longer when the strip is too short for every item.
    var priorities: [PaneID: Int] = [:]

    /// A chain of priorities in the given order, so Terminal, Overview, Preview and Files leave first, in that order.
    private var visibility: [PaneID: ToolbarItemVisibilityPriority] {
        var result: [PaneID: ToolbarItemVisibilityPriority] = [:]
        var previous: (value: Int, priority: ToolbarItemVisibilityPriority)?
        for (id, value) in priorities.sorted(by: { ($0.value, $0.key.rawValue) < ($1.value, $1.key.rawValue) }) {
            let priority: ToolbarItemVisibilityPriority
            if let previous, previous.value == value {
                priority = previous.priority
            } else if let previous {
                priority = ToolbarItemVisibilityPriority(higherThan: previous.priority)
            } else {
                priority = .low
            }
            result[id] = priority
            previous = (value, priority)
        }
        return result
    }

    var body: some ToolbarContent {
        if marks.isEmpty {
            // One pane shows: the one group the strip always had, in its own order and with its own tint.
            ToolbarItemGroup(placement: .bottomBar) {
                ForEach(panes.compactMap { PaneCatalog.descriptor(for: $0) }, id: \.id) { button($0) }
            }
            .axisBehavior(.verticalPreferred)
        } else {
            ForEach(panes.compactMap { PaneCatalog.descriptor(for: $0) }, id: \.id) { descriptor in
                ToolbarItem(placement: .bottomBar) { button(descriptor) }
                    .visibilityPriority(visibility[descriptor.id] ?? .automatic)
            }
            .axisBehavior(.verticalPreferred)
        }
    }

    private func button(_ descriptor: PaneDescriptor) -> some View {
        let id = descriptor.id
        return Button {
            focused = id
        } label: {
            VStack(spacing: 2) {
                Image(systemName: descriptor.symbol)
                    .overlay(alignment: .topTrailing) {
                        PaneBadge.make(descriptor, thread).map {
                            PaneBadgeView(badge: $0).offset(x: 6, y: -3)
                        }
                    }
                // Fit: caption2 on one line passes the contrast audit and leaves room for a fifth caption ("Overview")
                // when five panes share the strip; a longer name must shorten, not scale.
                Text(descriptor.name).font(.caption2).foregroundStyle(.primary).lineLimit(1)
            }
        }
        .modifier(PaneMarkLabel(mark: marks[id]))
        .secondPaneContextMenu(for: id)
        .accessibilityLabel(Text(descriptor.name))
        .accessibilityValue(paneAccessibilityValue(mark: marks[id], badge: PaneBadge.make(descriptor, thread)))
        .accessibilityAddTraits(id == focused ? .isSelected : [])
        .accessibilityIdentifier("pane.strip.\(id.rawValue)")
    }
}

/// Whether the system offers a vertical toolbar strip here. It reads `toolbarVerticalEdge`, which exists from
/// iOS 27.1, and gives its content the answer.
struct ToolbarStripReader<Content: View>: View {
    @ViewBuilder let content: (Bool) -> Content

    var body: some View {
        if #available(iOS 27.1, *) {
            Probe(content: content)
        } else {
            content(false)
        }
    }

    @available(iOS 27.1, *)
    private struct Probe: View {
        let content: (Bool) -> Content
        @Environment(\.toolbarVerticalEdge) private var edge

        var body: some View { content(edge != nil) }
    }
}
