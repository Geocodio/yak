import SwiftUI
import YakKit

/// The bar at the bottom of a thread: the pane control where the placement puts it, then the focused pane's own
/// row. It sits in a bottom safe-area inset, so it rides on the keyboard.
struct BottomBar: View {
    let thread: ThreadModel
    let placement: PaneControlPlacement
    @Binding var focused: PaneID

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency
    private let swipeDistance: CGFloat = 60

    var body: some View {
        layout
            .padding(.horizontal, 10).padding(.vertical, 8)
            .background { background }
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
            PaneRow(panes: thread.panes, focused: $focused, hasOpenQuestion: thread.hasOpenQuestion)
        }
    }

    @ViewBuilder private var row: some View {
        if let descriptor = PaneCatalog.descriptor(for: focused) { descriptor.barRow(thread) }
    }

    @ViewBuilder private var background: some View {
        // Under Reduce Transparency the text sits on an opaque surface instead of the glass.
        if reduceTransparency {
            RoundedRectangle(cornerRadius: 28).fill(Color(.secondarySystemBackground))
                .overlay(RoundedRectangle(cornerRadius: 28).stroke(Color.primary.opacity(0.12), lineWidth: 0.5))
        } else {
            Color.clear.glassEffect(.regular, in: .rect(cornerRadius: 28))
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
