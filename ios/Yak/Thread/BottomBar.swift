import SwiftUI
import YakKit

/// The bar at the bottom of a thread: the pane control where the placement puts it, then the focused pane's own
/// row. It sits below the pane, inside the safe area, so it rides on the keyboard.
struct BottomBar: View {
    let thread: ThreadModel
    let slots: PaneSlots
    /// The pane control this bar carries; nil when the system's strip carries it.
    let placement: PaneControlPlacement?
    /// The pane whose row the bar shows.
    let pane: PaneID
    /// Where the bar sits when it belongs to one of two slots; nil for the one bar of a single pane.
    var slot: SlotPosition?
    /// Called with a pane the person picked in this bar (its row or its menu).
    let select: (PaneID) -> Void
    /// The pane in the other slot, which the menu marks "Shown beside".
    var beside: PaneID?

    @Environment(SceneModel.self) private var scene
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var isTyping = false
    /// The bottom edge of the bar, already above the keyboard, and the height of the composer card under the menu.
    @State private var barBottom: CGFloat = 0
    @State private var cardHeight: CGFloat = 0
    private let swipeDistance: CGFloat = 60

    /// The pane's controls are in the trailing strip, so the bar has nothing of its own to show.
    private var isInStrip: Bool { placement == .trailingStrip && slots.stripRows[pane] != nil }
    private var showsPaneRow: Bool { placement?.showsPaneRowInBar == true }
    private var showsPaneButton: Bool { placement?.showsPaneButtonInBar == true }

    var body: some View {
        if !isInStrip { bar }
    }

    /// The bar is docked: a strip on the page color with a rule above it, holding one card. The card holds the waiting
    /// messages (the web page leaves them out of its list), then the controls.
    private var bar: some View {
        VStack(spacing: Design.Space.small) {
            if let menu = slashMenu {
                SlashMenuView(menu: menu, maxHeight: slashMenuHeight) { command in
                    thread.composer.pickSlashCommand(command)
                    scene.requestComposerFocus()
                }
            }
            card.onGeometryChange(for: CGFloat.self) {
                $0.size.height
            } action: {
                cardHeight = $0
            }
        }
        .onGeometryChange(for: CGFloat.self) {
            $0.frame(in: .global).maxY
        } action: {
            barBottom = $0
        }
        .padding(.horizontal, Design.Space.medium).padding(.top, Design.Space.tight)
        .padding(.bottom, Design.Space.barEdgeGap)
        .frame(maxWidth: .infinity)
        .background(.yakPage)
        .overlay(alignment: .top) { Rectangle().fill(Color.yakLine).frame(height: Design.Space.line) }
        .onPreferenceChange(ComposerFocusKey.self) { isTyping = $0 }
        .simultaneousGesture(swipe)
        .accessibilityElement(children: .contain)
        .modifier(SlotBarLabel(isInSlot: slot != nil, pane: pane))
        .accessibilityIdentifier(slot.map { "bar.\($0.rawValue)" } ?? "thread.bar")
    }

    /// The menu fills the room above the field, five rows at most, so it never pushes the field off the screen.
    private var slashMenuHeight: CGFloat {
        CGFloat(
            SlashMenuHeight.cap(
                barBottom: barBottom, cardHeight: cardHeight, spacing: Design.Space.small,
                topReserve: Design.Size.slashTopReserve, oneRow: Design.Size.slashRow,
                fiveRows: Design.Size.slashMenuMaxHeight))
    }

    /// The menu is open while the field has the keyboard, so leaving the field closes it.
    private var slashMenu: SlashMenu? { pane == .chat && isTyping ? thread.composer.slashMenu : nil }

    /// The waiting messages and the upload chip above the controls, in one card.
    private var card: some View {
        VStack(spacing: 0) {
            if pane == .chat {
                UploadChipsView(thread: thread)
                QueuedChipsView(thread: thread)
            }
            layout
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(Design.Space.nudge + 1)
        }
        .clipShape(.rect(cornerRadius: Design.Radius.card))
        .background { BarSurface(isFocused: isTyping) }
    }

    @ViewBuilder private var layout: some View {
        if dynamicTypeSize.isAccessibilitySize && (showsPaneRow || showsPaneButton) {
            VStack(alignment: .leading, spacing: 8) {
                control
                row
            }
        } else {
            HStack(alignment: .bottom, spacing: Design.Space.small) {
                control
                row
            }
        }
    }

    @ViewBuilder private var control: some View {
        if showsPaneButton {
            PaneMenuButton(panes: thread.panes, current: pane, beside: beside, slot: slot, select: select)
        } else if showsPaneRow {
            PaneRow(panes: thread.panes, focused: pane, thread: thread, select: select)
        }
    }

    @ViewBuilder private var row: some View {
        if let own = slots.barRows[pane] {
            own.view
        } else if let descriptor = PaneCatalog.descriptor(for: pane) {
            descriptor.barRow(thread)
        }
    }

    /// A sideways swipe moves one pane, and does nothing at either end. A bar that belongs to one of two slots has
    /// no swipe.
    private var swipe: some Gesture {
        DragGesture(minimumDistance: 24).onEnded { value in
            let across = value.translation.width
            guard slot == nil, abs(across) > swipeDistance, abs(across) > abs(value.translation.height) else { return }
            if let next = Panes.neighbor(of: pane, in: thread.panes, step: across < 0 ? 1 : -1) {
                withMotion(Motion.paneSwitch, reduceMotion: reduceMotion) { select(next) }
            }
        }
    }
}

/// The card of the bar and of the trailing strip: the web's panel, with its strong border and a soft shadow. While a
/// person types, the border takes the accent.
struct BarSurface: View {
    var isFocused = false

    var body: some View {
        RoundedRectangle(cornerRadius: Design.Radius.card).fill(Color.yakBar)
            .overlay(
                RoundedRectangle(cornerRadius: Design.Radius.card)
                    .strokeBorder(Color.yakBarBorder, lineWidth: Design.Space.line)
            )
            .overlay(
                RoundedRectangle(cornerRadius: Design.Radius.card)
                    .strokeBorder(Color.yakAccent.opacity(isFocused ? 0.42 : 0), lineWidth: Design.Space.line)
            )
            .shadow(color: Color.yakShadow, radius: 1, y: 1)
    }
}

/// Whether the message field has the keyboard.
struct ComposerFocusKey: PreferenceKey {
    static let defaultValue = false
    static func reduce(value: inout Bool, nextValue: () -> Bool) { value = value || nextValue() }
}

/// A bar of a two-slot layout names its pane, so VoiceOver tells the bars apart. The one bar of a single pane carries
/// no label.
private struct SlotBarLabel: ViewModifier {
    let isInSlot: Bool
    let pane: PaneID

    @ViewBuilder func body(content: Content) -> some View {
        if isInSlot {
            content.accessibilityLabel(Text(PaneCatalog.descriptor(for: pane)?.name ?? ""))
        } else {
            content
        }
    }
}
