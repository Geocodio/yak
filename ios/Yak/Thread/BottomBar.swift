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

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    private let swipeDistance: CGFloat = 60

    /// The pane's controls are in the trailing strip, so the bar has nothing of its own to show.
    private var isInStrip: Bool { placement == .trailingStrip && slots.stripRows[pane] != nil }
    private var showsPaneRow: Bool { placement?.showsPaneRowInBar == true }
    private var showsPaneButton: Bool { placement?.showsPaneButtonInBar == true }

    var body: some View {
        if !isInStrip, !thread.question.isEditing { bar }
    }

    private var bar: some View {
        layout
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(Design.Space.barPadding)
            .background { BarSurface() }
            .padding(.horizontal, Design.Space.barInset).padding(.bottom, Design.Space.barEdgeGap)
            .simultaneousGesture(swipe)
            .accessibilityElement(children: .contain)
            .modifier(SlotBarLabel(isInSlot: slot != nil, pane: pane))
            .accessibilityIdentifier(slot.map { "bar.\($0.rawValue)" } ?? "thread.bar")
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
                withAnimation(.smooth(duration: 0.28)) { select(next) }
            }
        }
    }
}

/// The surface behind the bar and the trailing strip: the board's `--gl` laid over the page, opaque, so the text on it
/// keeps its contrast whatever is behind it.
struct BarSurface: View {
    var body: some View {
        RoundedRectangle(cornerRadius: Design.Radius.bar).fill(Color.yakBar)
            .overlay(
                RoundedRectangle(cornerRadius: Design.Radius.bar)
                    .stroke(Color.yakBarBorder, lineWidth: Design.Space.hairline))
    }
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
