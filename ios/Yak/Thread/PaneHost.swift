import SwiftUI
import YakKit

/// Keeps every pane opened in this thread alive and shows the focused one. Opacity and hit testing hide the others,
/// so switching never reloads a pane. The views go away with the thread.
struct PaneHost: View {
    let thread: ThreadModel
    let focused: PaneID

    @State private var opened: [PaneID] = []
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        ZStack {
            ForEach(opened, id: \.self) { id in
                if let descriptor = PaneCatalog.descriptor(for: id) {
                    let isFocused = id == focused
                    descriptor.content(thread)
                        .opacity(isFocused ? 1 : 0)
                        .offset(x: reduceMotion || isFocused ? 0 : offset(of: id))
                        .environment(\.paneID, id)
                        .environment(\.isPaneShown, isFocused)
                        .allowsHitTesting(isFocused)
                        .accessibilityHidden(!isFocused)
                }
            }
        }
        .animation(reduceMotion ? .easeInOut(duration: 0.2) : .smooth(duration: 0.28), value: focused)
        .onAppear { open(focused) }
        .onChange(of: focused) { open(focused) }
    }

    private func open(_ id: PaneID) {
        if !opened.contains(id) { opened.append(id) }
    }

    /// A pane that is not focused waits one step to the side it would slide in from.
    private func offset(of id: PaneID) -> CGFloat {
        let order = thread.panes
        guard let own = order.firstIndex(of: id), let current = order.firstIndex(of: focused) else { return 0 }
        return own < current ? -24 : 24
    }
}
