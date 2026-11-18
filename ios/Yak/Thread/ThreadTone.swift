import SwiftUI
import YakKit

extension Tone {
    /// ok green, warn orange, bad red; info, neutral and any other word gray.
    var color: Color {
        switch self {
        case .ok: .green
        case .warn: .yakWarning
        case .bad: .yakDanger
        default: Color(.systemGray2)
        }
    }

    /// The tint of a whole card surface; neutral and info stay on the plain surface.
    var surfaceTint: Color? {
        switch self {
        case .warn: Color.yakWarning.opacity(0.14)
        case .bad: Color.yakDanger.opacity(0.1)
        default: nil
        }
    }
}

/// A short label on a tinted capsule: the tone is the tint, the words stay in the primary color.
struct TonePill: View {
    let text: String
    var tone: Tone = .neutral

    var body: some View {
        Text(verbatim: text).font(.caption.weight(.semibold)).foregroundStyle(.primary)
            .padding(.horizontal, 10).padding(.vertical, 4).frame(minHeight: 24)
            .background(tone.color.opacity(0.2), in: .capsule)
            .fixedSize(horizontal: false, vertical: true)
    }
}
