import SwiftUI
import YakKit

/// An instance's initial on its tint. Decorative: the row's label carries the name.
struct InstanceTintTile: View {
    let instance: Instance
    var size: CGFloat = 36

    @ScaledMetric(relativeTo: .headline) private var scale: CGFloat = 1

    var body: some View {
        let side = min(size * scale, size * 1.4)
        let tint = Self.color(for: instance.tint)
        Text(InstanceInitial.make(name: instance.name, host: instance.url.host()))
            .font(.headline.bold())
            .foregroundStyle(tint ?? Color.primary)
            .frame(width: side, height: side)
            .background(
                tint?.opacity(0.2) ?? Color(.systemGray4), in: .rect(cornerRadius: side * 11 / 36, style: .continuous)
            )
            .accessibilityHidden(true)
    }

    /// Nil for a missing or unknown tint (the store has not loaded yet): the tile shows its neutral fill.
    static func color(for tint: InstanceTint?) -> Color? {
        guard let tint, tint.isKnown else { return nil }
        return Color("InstanceTint/\(tint.rawValue.capitalized)")
    }
}
