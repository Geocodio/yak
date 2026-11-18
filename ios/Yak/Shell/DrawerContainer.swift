import SwiftUI

/// The compact-width layout: the drawer on the leading edge and the thread beside it. Opening slides the
/// drawer in and pushes the thread aside by the drawer's width, scaled down with a hairline border.
struct DrawerContainer<Drawer: View, Detail: View>: View {
    @Binding var isOpen: Bool
    @ViewBuilder let drawer: Drawer
    @ViewBuilder let detail: Detail

    /// How far the finger has moved the drawer from where it rests, while a drag runs.
    @State private var dragOffset: CGFloat = 0
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    private let edgeWidth: CGFloat = 20
    private let flickDistance: CGFloat = 150

    var body: some View {
        GeometryReader { geometry in
            // Large text needs the width, so the thread keeps only a narrow strip beside the drawer.
            let width =
                dynamicTypeSize.isAccessibilitySize ? geometry.size.width - 44 : min(330, geometry.size.width - 60)
            let progress = reduceMotion ? (isOpen ? 1 : 0) : openProgress(width: width)
            ZStack(alignment: .leading) {
                Color.yakCard.ignoresSafeArea()
                drawer
                    .frame(width: width)
                    .frame(maxHeight: .infinity)
                    .offset(x: -(1 - progress) * width)
                    .opacity(reduceMotion ? progress : 1)
                    .accessibilityHidden(!isOpen)
                    .simultaneousGesture(dragGesture(width: width))
                detail
                    .background {
                        RoundedRectangle(cornerRadius: 28 * progress).fill(Color.yakBackground)
                            .ignoresSafeArea()
                    }
                    .overlay(
                        RoundedRectangle(cornerRadius: 28 * progress)
                            .stroke(Color.primary.opacity(0.12 * progress), lineWidth: 0.5).ignoresSafeArea()
                    )
                    .scaleEffect(1 - 0.06 * progress)
                    .offset(x: progress * width)
                    .allowsHitTesting(!isOpen)
                    .accessibilityHidden(isOpen)
                    .overlay(alignment: .leading) {
                        if !isOpen {
                            Color.clear.frame(width: edgeWidth).contentShape(.rect)
                                .gesture(dragGesture(width: width))
                                // In landscape the side inset would move the strip under the toolbar's leading button.
                                .ignoresSafeArea(edges: .leading)
                        }
                    }
                if isOpen {
                    Color.clear.contentShape(.rect)
                        .offset(x: width)
                        .onTapGesture { setOpen(false) }
                        .gesture(dragGesture(width: width))
                        .accessibilityLabel("shell.closeDrawer")
                        .accessibilityAddTraits(.isButton)
                        .accessibilityAction(.escape) { setOpen(false) }
                }
            }
            .animation(reduceMotion ? .easeInOut(duration: 0.2) : nil, value: isOpen)
        }
    }

    private func openProgress(width: CGFloat) -> CGFloat {
        let base = isOpen ? width : 0
        return min(max(base + dragOffset, 0), width) / width
    }

    private func setOpen(_ open: Bool) {
        withAnimation(reduceMotion ? .easeInOut(duration: 0.2) : .spring(duration: 0.38, bounce: 0.12)) {
            isOpen = open
            dragOffset = 0
        }
    }

    private func dragGesture(width: CGFloat) -> some Gesture {
        DragGesture(minimumDistance: 12)
            .onChanged { value in
                guard !reduceMotion, abs(value.translation.width) > abs(value.translation.height) else { return }
                dragOffset = value.translation.width
            }
            .onEnded { value in
                let flick = value.predictedEndTranslation.width - value.translation.width
                let moved = value.translation.width
                // From closed, a move past a third of the width or a fast flick opens; from open, the same
                // move the other way closes.
                if isOpen {
                    setOpen(!(moved < -width / 3 || flick < -flickDistance))
                } else {
                    setOpen(moved > width / 3 || flick > flickDistance)
                }
            }
    }
}
