import SwiftUI
import YakKit

/// The bar at the bottom of a thread: the pane control where the placement puts it, then the focused pane's own
/// row. It sits in a bottom safe-area inset, so it rides on the keyboard.
struct BottomBar: View {
    let thread: ThreadModel
    let slots: PaneSlots
    let placement: PaneControlPlacement
    @Binding var focused: PaneID

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    private let swipeDistance: CGFloat = 60

    /// The pane's controls are in the trailing strip, so the bar has nothing of its own to show.
    private var isInStrip: Bool { placement == .trailingStrip && slots.stripRows[focused] != nil }

    var body: some View {
        if !isInStrip { bar }
    }

    private var bar: some View {
        layout
            .padding(.horizontal, 10).padding(.vertical, 8)
            .background { BarSurface() }
            .padding(.horizontal, 8).padding(.bottom, 4)
            .simultaneousGesture(swipe)
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("thread.bar")
    }

    @ViewBuilder private var layout: some View {
        if dynamicTypeSize.isAccessibilitySize && (placement.showsPaneRowInBar || placement.showsPaneButtonInBar) {
            VStack(alignment: .leading, spacing: 8) {
                control
                row
            }
        } else {
            HStack(spacing: 8) {
                control
                row
            }
        }
    }

    @ViewBuilder private var control: some View {
        if placement.showsPaneButtonInBar {
            PaneMenuButton(panes: thread.panes, focused: $focused)
        } else if placement.showsPaneRowInBar {
            PaneRow(panes: thread.panes, focused: $focused, thread: thread)
        }
    }

    @ViewBuilder private var row: some View {
        if let own = slots.barRows[focused] {
            own.view
        } else if let descriptor = PaneCatalog.descriptor(for: focused) {
            descriptor.barRow(thread)
        }
    }

    /// A sideways swipe moves one pane, and does nothing at either end.
    private var swipe: some Gesture {
        DragGesture(minimumDistance: 24).onEnded { value in
            let across = value.translation.width
            guard abs(across) > swipeDistance, abs(across) > abs(value.translation.height) else { return }
            if let next = Panes.neighbor(of: focused, in: thread.panes, step: across < 0 ? 1 : -1) {
                withAnimation(.smooth(duration: 0.28)) { focused = next }
            }
        }
    }
}

/// The glass behind the bar and the trailing strip. Under Reduce Transparency the text sits on an opaque surface.
struct BarSurface: View {
    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency

    var body: some View {
        if reduceTransparency {
            RoundedRectangle(cornerRadius: 28).fill(Color(.secondarySystemBackground))
                .overlay(RoundedRectangle(cornerRadius: 28).stroke(Color.primary.opacity(0.12), lineWidth: 0.5))
        } else {
            Color.clear.glassEffect(.regular, in: .rect(cornerRadius: 28))
        }
    }
}
