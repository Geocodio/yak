import SwiftUI
import YakKit

/// The empty rectangle that stands where a pane sits in a two-slot layout. It reports its frame to the host, which
/// places the pane there. An unfocused slot's marker takes the tap that focuses it, so the tap never reaches the
/// pane's content; once focused, taps go through to the pane.
struct SlotMarker: View {
    let position: SlotPosition
    let pane: PaneID
    let isFocused: Bool
    let model: PaneSlotsModel
    let focus: () -> Void

    var body: some View {
        Color.clear
            .contentShape(.rect)
            .allowsHitTesting(!isFocused)
            .onTapGesture(perform: focus)
            .onGeometryChange(for: CGRect.self) {
                $0.frame(in: .named(ThreadLayoutView.coordinateSpace))
            } action: {
                model.report($0, for: position)
            }
            .accessibilityElement()
            .accessibilityLabel(Text(PaneCatalog.descriptor(for: pane)?.name ?? ""))
            .accessibilityValue(Text(isFocused ? "pane.slot.shown" : "pane.slot.shownBeside"))
            .accessibilityAddTraits(isFocused ? .isSelected : [])
            .accessibilityAddTraits(isFocused ? [] : .isButton)
            .accessibilityIdentifier("pane.slot.\(position.rawValue)")
    }
}
