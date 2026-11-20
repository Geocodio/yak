import SwiftUI

/// A band of full strength that sweeps across a skeleton's dimmed shapes, so a wait looks alive. It stays still under
/// Reduce Motion and in test mode, where an endless animation keeps the accessibility audit from reading the screen.
private struct Shimmer: ViewModifier {
    @State private var isSweeping = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    /// How far the shapes dim away from the band.
    private let dimmedOpacity: Double = 0.4
    /// Half the band's width, as a share of the view's width.
    private let halfBand: CGFloat = 0.5

    func body(content: Content) -> some View {
        if StillMotion.isOn(reduceMotion: reduceMotion) {
            content
        } else {
            content
                .mask {
                    let center: CGFloat = isSweeping ? 1 + halfBand : -halfBand
                    LinearGradient(
                        colors: [.black.opacity(dimmedOpacity), .black, .black.opacity(dimmedOpacity)],
                        startPoint: UnitPoint(x: center - halfBand, y: 0.5),
                        endPoint: UnitPoint(x: center + halfBand, y: 0.5))
                }
                .onAppear { withAnimation(Motion.shimmerPass) { isSweeping = true } }
        }
    }
}

extension View {
    /// Sweeps a band of full strength across this view's shapes while it is on screen.
    func shimmering() -> some View { modifier(Shimmer()) }
}
