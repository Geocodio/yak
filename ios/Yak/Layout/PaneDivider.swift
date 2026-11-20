import SwiftUI
import UIKit
import YakKit

/// The drag handle between two stacked panes: a 44-point strip with a short rounded bar. Dragging moves the boundary
/// live; letting go stores the fraction. A double tap returns to the middle. VoiceOver adjusts it in steps of 10 percent.
struct PaneDivider: View {
    /// The strip between the two panes is as tall as the touch target, so the target covers no pane content.
    static let bandHeight: CGFloat = 44

    /// One VoiceOver step.
    static let adjustmentStep = 0.1

    static func adjusted(_ fraction: Double, increment: Bool) -> Double {
        PaneLayoutRules.clampedFraction(fraction + (increment ? adjustmentStep : -adjustmentStep))
    }

    /// The upper slot's share, clamped.
    let fraction: Double
    /// The height the two panes share, without the band.
    let availableHeight: CGFloat
    let upperName: LocalizedStringResource
    /// Called while dragging with the unclamped fraction.
    let drag: (Double) -> Void
    /// Called when the finger lifts, or for a double tap or an adjustment.
    let commit: (Double) -> Void

    @State private var isDragging = false
    @State private var startFraction: Double?
    @State private var snapCount = 0
    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency

    var body: some View {
        Capsule()
            .fill(Color(.systemGray).opacity(isDragging ? 0.8 : 0.5))
            .frame(width: isDragging ? 56 : 44, height: 5)
            .frame(maxWidth: .infinity).frame(height: Self.bandHeight)
            .background(reduceTransparency ? Color.yakCard : .clear)
            .contentShape(.rect)
            .hoverEffect(.lift)
            .gesture(dragGesture)
            .onTapGesture(count: 2) {
                snapCount += 1
                commit(0.5)
            }
            .animation(Motion.quick, value: isDragging)
            .sensoryFeedback(.impact(weight: .light), trigger: snapCount)
            .accessibilityElement()
            .accessibilityLabel(Text("pane.divider.label"))
            .accessibilityValue(
                Text("pane.divider.value \(Int((fraction * 100).rounded())) \(String(localized: upperName))")
            )
            .accessibilityAdjustableAction { direction in
                commit(Self.adjusted(fraction, increment: direction == .increment))
            }
            .accessibilityIdentifier("pane.divider")
    }

    private var dragGesture: some Gesture {
        DragGesture(minimumDistance: 2, coordinateSpace: .global)
            .onChanged { value in
                guard availableHeight > 0 else { return }
                isDragging = true
                let start = startFraction ?? fraction
                startFraction = start
                drag(start + Double(value.translation.height / availableHeight))
            }
            .onEnded { value in
                defer {
                    isDragging = false
                    startFraction = nil
                }
                guard availableHeight > 0 else { return }
                commit((startFraction ?? fraction) + Double(value.translation.height / availableHeight))
            }
    }
}
