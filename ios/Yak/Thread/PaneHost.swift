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

    private var isSingle: Bool { slots.arrangement == .single }

    /// With one slot the focused pane is the shown one, read in the same update as `focused` so the fade and the slide
    /// run with the switch; with two slots the placement the layout wrote says which panes are shown.
    static func isShown(_ id: PaneID, focused: PaneID, isSingle: Bool, placed: Bool) -> Bool {
        isSingle ? id == focused : placed
    }

    var body: some View {
        ZStack(alignment: isSingle ? .center : .topLeading) {
            ForEach(slots.alive, id: \.self) { id in
                if let descriptor = PaneCatalog.descriptor(for: id) {
                    let isShown = Self.isShown(id, focused: focused, isSingle: isSingle, placed: slots.isShown(id))
                    let frame = slots.frame(for: id)
                    descriptor.content(thread)
                        .frame(width: frame?.width, height: frame?.height)
                        .background(isSingle ? Color.clear : Color(.systemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: cornerRadius).inset(by: isSingle ? -4000 : 0))
                        .offset(x: (frame?.minX ?? 0) + slide(of: id, isShown: isShown), y: frame?.minY ?? 0)
                        .opacity(isShown && (isSingle || frame != nil) ? 1 : 0)
                        .environment(\.paneID, id)
                        .environment(\.isPaneShown, isShown)
                        .allowsHitTesting(isShown)
                        .accessibilityHidden(!isShown)
                }
            }
        }
        .animation(reduceMotion ? .easeInOut(duration: 0.2) : .smooth(duration: 0.28), value: focused)
        .animation(reduceMotion ? .easeInOut(duration: 0.2) : .smooth(duration: 0.28), value: slots.frames)
    }

    private func slide(of id: PaneID, isShown: Bool) -> CGFloat {
        guard !reduceMotion, !isShown, isSingle else { return 0 }
        let order = thread.panes
        guard let own = order.firstIndex(of: id), let current = order.firstIndex(of: focused) else { return 0 }
        return own < current ? -24 : 24
    }
}
