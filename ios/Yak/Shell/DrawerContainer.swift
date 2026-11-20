import SwiftUI

/// The compact-width layout: the drawer on the leading edge and the thread beside it. Opening slides the
/// drawer in and pushes the thread aside by the drawer's width, scaled down with a hairline border.
struct DrawerContainer<Drawer: View, Detail: View>: View {
    @Binding var isOpen: Bool
    @ViewBuilder let drawer: Drawer
    @ViewBuilder let detail: Detail

    /// How far the finger has moved the drawer from where it rests, while a drag runs.
    @State private var dragOffset: CGFloat = 0
    /// The spring of the drag that just ended, so the settle carries the finger's speed; nil for every other opener.
    @State private var releaseAnimation: Animation?
    /// Counts the times the drawer settled open, for the haptic.
    @State private var settledOpenCount = 0
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
                Color.yakBackground.ignoresSafeArea()
                drawer
                    .frame(width: width)
                    .frame(maxHeight: .infinity)
                    .offset(x: -(1 - progress) * width)
                    .opacity(reduceMotion ? progress : 1)
                    .accessibilityHidden(!isOpen)
                    .simultaneousGesture(dragGesture(width: width))
                detail
                    .background {
                        RoundedRectangle(cornerRadius: Design.Radius.pane * progress).fill(Color.yakBackground)
                            .ignoresSafeArea()
                    }
                    .overlay {
                        // The thread recedes behind the drawer, as the board's faded card does.
                        RoundedRectangle(cornerRadius: Design.Radius.pane * progress)
                            .fill(Color.yakBackground.opacity(Motion.drawerFade * progress))
                            .ignoresSafeArea()
                            .allowsHitTesting(false)
                    }
                    .overlay(
                        RoundedRectangle(cornerRadius: Design.Radius.pane * progress)
                            .stroke(Color.primary.opacity(0.12 * progress), lineWidth: Design.Space.hairline)
                            .ignoresSafeArea()
                    )
                    .scaleEffect(1 - Motion.drawerScaleBack * progress, anchor: .leading)
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
            // Every opener (button, shortcut, thread pick, scrim tap, drag release) flips `isOpen`, so one transaction
            // here animates them all.
            .transaction(value: isOpen) { transaction in
                transaction.animation = Motion.resolve(releaseAnimation ?? Motion.drawer, reduceMotion: reduceMotion)
                if isOpen {
                    // The drawer may have closed again by the time the open settles.
                    transaction.addAnimationCompletion(criteria: .logicallyComplete) {
                        if isOpen { settledOpenCount += 1 }
                    }
                }
            }
            .haptic(.drawerSettled, trigger: settledOpenCount)
        }
    }

    private func openProgress(width: CGFloat) -> CGFloat {
        let base = isOpen ? width : 0
        return min(max(base + dragOffset, 0), width) / width
    }

    /// Settles the drawer open or closed. `velocity` is the finger's speed along the width in points per second.
    private func setOpen(_ open: Bool, from position: CGFloat = 0, velocity: CGFloat = 0, width: CGFloat = 1) {
        let distance = open ? width - position : position
        let animation = Motion.release(velocity: open ? velocity : -velocity, distance: distance)
        releaseAnimation = animation
        withAnimation(Motion.resolve(animation, reduceMotion: reduceMotion)) {
            isOpen = open
            dragOffset = 0
        } completion: {
            releaseAnimation = nil
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
                let position = min(max((isOpen ? width : 0) + moved, 0), width)
                let release = { (open: Bool) in
                    setOpen(open, from: position, velocity: value.velocity.width, width: width)
                }
                // From closed, a move past a third of the width or a fast flick opens; from open, the same
                // move the other way closes.
                if isOpen {
                    release(!(moved < -width / 3 || flick < -flickDistance))
                } else {
                    release(moved > width / 3 || flick > flickDistance)
                }
            }
    }
}
