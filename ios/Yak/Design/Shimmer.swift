import SwiftUI

/// A soft band of light that sweeps across a skeleton, so a wait looks alive. It stays still under Reduce Motion and in
/// test mode, where an endless animation keeps the accessibility audit from reading the screen.
private struct Shimmer: ViewModifier {
    @State private var isSweeping = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private let bandWidth: CGFloat = 0.5
    private let bandOpacity: Double = 0.14

    func body(content: Content) -> some View {
        if StillMotion.isOn(reduceMotion: reduceMotion) {
            content
        } else {
            content
                .overlay {
                    GeometryReader { geometry in
                        LinearGradient(
                            colors: [.clear, Color.primary.opacity(bandOpacity), .clear], startPoint: .leading,
                            endPoint: .trailing
                        )
                        .frame(width: geometry.size.width * bandWidth)
                        .offset(x: geometry.size.width * (isSweeping ? 1 : -bandWidth))
                    }
                    .mask(content)
                    .allowsHitTesting(false)
                }
                .onAppear { withAnimation(Motion.shimmerPass) { isSweeping = true } }
        }
    }
}

extension View {
    /// Sweeps a band of light across this view's shapes while it is on screen.
    func shimmering() -> some View { modifier(Shimmer()) }
}
