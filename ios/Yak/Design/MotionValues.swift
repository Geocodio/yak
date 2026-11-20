import SwiftUI

/// The motion values of the app: one name per kind of movement, so every screen moves the same way. Views use these
/// and never a literal duration or spring.
enum Motion {
    /// The drawer sliding in and out, and the thread scaling back beside it.
    static let drawer: Animation = .spring(duration: drawerDuration, bounce: drawerBounce)
    private static let drawerDuration: Double = 0.4
    private static let drawerBounce: Double = 0.1
    /// A pane replacing another, and panes taking their frames in a two pane layout.
    static let paneSwitch: Animation = .smooth(duration: 0.28)
    /// A panel or a toast entering and leaving: cards, bars and sheet-like surfaces.
    static let panel: Animation = .smooth(duration: 0.28)
    /// Content changing inside a surface: a list swapping its rows, a section opening.
    static let content: Animation = .smooth(duration: 0.25)
    /// Feedback to a touch: a pressed button, a selected row.
    static let quick: Animation = .snappy(duration: 0.15)
    /// One screen replacing another, and a skeleton giving way to what it stood for.
    static let crossFade: Animation = .easeInOut(duration: 0.2)
    /// A placeholder appearing after a short wait.
    static let reveal: Animation = .easeOut(duration: 0.2)
    /// One pass of the shimmer across a skeleton.
    static let shimmerPass: Animation = .linear(duration: 1.4).repeatForever(autoreverses: false)
    /// The slow pulse of a status dot or a waiting row.
    static let pulse: Animation = .easeInOut(duration: 0.9).repeatForever(autoreverses: true)

    /// How far a pane waits to the side it slides in from.
    static let paneSlideDistance: CGFloat = 24
    /// The size the thread scales back by when the drawer is fully open.
    static let drawerScaleBack: CGFloat = 0.06
    /// How far the thread fades toward the background when the drawer is fully open. The strip of the thread that stays
    /// beside the drawer keeps its small gray text above the contrast bar only up to this much.
    static let drawerFade: Double = 0.12
    /// The strength of the hairline around the thread beside the open drawer.
    static let drawerStrokeOpacity: Double = 0.12
    /// The size of a pressed control.
    static let pressedScale: CGFloat = 0.97
    /// How much a pressed control dims.
    static let pressedOpacity: Double = 0.85

    /// The animation to run for `animation`: the cross-fade in its place under Reduce Motion, and none in test mode,
    /// where every screen stays still for the accessibility audit. Pass the result to `withAnimation`, `.animation`
    /// or a transaction.
    static func resolve(_ animation: Animation, reduceMotion: Bool, isTestMode: Bool = StillMotion.isTestMode)
        -> Animation?
    {
        if isTestMode { return nil }
        return reduceMotion ? crossFade : animation
    }

    /// The drawer spring a drag release continues with: `velocity` is the finger's speed toward the end point in points per
    /// second and `distance` the points still to travel.
    static func release(velocity: CGFloat, distance: CGFloat) -> Animation {
        guard distance > 0 else { return drawer }
        let normalized = min(max(velocity / distance, -maxReleaseVelocity), maxReleaseVelocity)
        return .interpolatingSpring(duration: drawerDuration, bounce: drawerBounce, initialVelocity: Double(normalized))
    }

    /// Spring hand-off speeds beyond this look like a jump rather than a throw.
    private static let maxReleaseVelocity: CGFloat = 12
}

extension View {
    /// Animates `value` with `animation`, or with the Reduce Motion variant.
    func motion<Value: Equatable>(_ animation: Animation, value: Value) -> some View {
        modifier(MotionModifier(animation: animation, value: value))
    }
}

private struct MotionModifier<Value: Equatable>: ViewModifier {
    let animation: Animation
    let value: Value
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    func body(content: Content) -> some View {
        content.animation(Motion.resolve(animation, reduceMotion: reduceMotion), value: value)
    }
}

/// `withAnimation` with the Reduce Motion variant.
@MainActor func withMotion<Result>(
    _ animation: Animation, reduceMotion: Bool, _ body: () throws -> Result
) rethrows -> Result {
    try withAnimation(Motion.resolve(animation, reduceMotion: reduceMotion), body)
}
