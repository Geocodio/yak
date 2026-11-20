import SwiftUI
import YakKit

/// Keeps every pane opened in this thread alive and puts each shown one in its slot's frame. Opacity and hit testing
/// hide the others, so switching or moving never reloads a pane. With one slot a pane fills the host. The views go
/// away with the thread. This is the only place a pane view is built, in one `ForEach` keyed by `PaneID`.
struct PaneHost: View {
    let thread: ThreadModel
    /// The focused pane: a pane that is not shown waits one step to the side it would slide in from.
    let focused: PaneID
    let slots: PaneSlotsModel
    /// The corners of a pane in a two-slot layout; a single pane is not clipped.
    let cornerRadius: CGFloat

    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private var isStill: Bool { StillMotion.isOn(reduceMotion: reduceMotion) }
    private var isSingle: Bool { slots.arrangement == .single }

    /// With one slot the focused pane is the shown one, read in the same update as `focused` so the fade and the slide
    /// run with the switch; with two slots the placement the layout wrote says which panes are shown.
    static func isShown(_ id: PaneID, focused: PaneID, isSingle: Bool, placed: Bool) -> Bool {
        isSingle ? id == focused : placed
    }

    /// A pane moves to its slot at once, with no fade: a text size change or a focus move re-plans the slots, and the
    /// screen must be still, text fully opaque, for the accessibility audit.
    var body: some View {
        ZStack(alignment: isSingle ? .center : .topLeading) {
            ForEach(slots.alive, id: \.self) { id in
                if let descriptor = PaneCatalog.descriptor(for: id) {
                    let isShown = Self.isShown(id, focused: focused, isSingle: isSingle, placed: slots.isShown(id))
                    let frame = slots.frame(for: id)
                    // A pane that waits for its slot's frame is neither drawn nor reachable.
                    let isVisible = isShown && (isSingle || frame != nil)
                    descriptor.content(thread)
                        .frame(width: frame?.width, height: frame?.height)
                        .background(isSingle ? Color.clear : Color.yakBackground)
                        .clipShape(RoundedRectangle(cornerRadius: cornerRadius).inset(by: isSingle ? -4000 : 0))
                        .offset(x: (frame?.minX ?? 0) + slide(of: id, isShown: isShown), y: frame?.minY ?? 0)
                        .opacity(isVisible ? 1 : 0)
                        .environment(\.paneID, id)
                        .environment(\.isPaneShown, isShown)
                        .allowsHitTesting(isVisible)
                        .accessibilityHidden(!isVisible)
                }
            }
        }
        // The frames move when panes open, close or swap, never while the divider is dragged: a drag moves the frames
        // without changing this key. Reduce Motion fades; test mode, where the audit needs a still screen, only fades
        // the single pane.
        .animation(
            isSingle && isStill ? Motion.crossFade : Motion.resolve(Motion.paneSwitch, reduceMotion: reduceMotion),
            value: Arrangement(focused: focused, kind: slots.arrangement, placement: slots.placement)
        )
        .haptic(.paneChanged, trigger: focused)
        // Scrolling any pane's list drags the keyboard down with it.
        .scrollDismissesKeyboard(.interactively)
    }

    private struct Arrangement: Equatable {
        let focused: PaneID
        let kind: PaneArrangement
        let placement: [PaneID: SlotPosition]
    }

    private func slide(of id: PaneID, isShown: Bool) -> CGFloat {
        guard !isShown, isSingle else { return 0 }
        // Without motion a pane that is not shown waits entirely off the screen, so an audit finds no pixels of it
        // to measure against the pane that is on top of its frame.
        if isStill { return Design.Size.offscreen }
        let order = thread.panes
        guard let own = order.firstIndex(of: id), let current = order.firstIndex(of: focused) else { return 0 }
        return own < current ? -Motion.paneSlideDistance : Motion.paneSlideDistance
    }
}
