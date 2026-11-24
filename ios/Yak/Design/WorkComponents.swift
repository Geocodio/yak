import SwiftUI
import YakKit

/// The tones of the work boards: a pill, a dot and a whole card surface. Info and any word the app does not know read
/// as neutral.
extension Tone {
    var surface: Color {
        switch self {
        case .warn: .yakCardWarnSurface
        case .bad: .yakCardBadSurface
        default: .yakCardSurface
        }
    }

    var pillBackground: Color {
        switch self {
        case .ok: .yakPillOK
        case .warn: .yakPillWarn
        case .bad: .yakPillBad
        default: .yakPillNeutral
        }
    }

    var pillText: Color {
        switch self {
        case .ok: .yakPillOKText
        case .warn: .yakPillWarnText
        case .bad: .yakPillBadText
        default: .yakPillNeutralText
        }
    }

    var dot: Color {
        switch self {
        case .ok: .yakDotOK
        case .warn: .yakDotWarn
        case .bad: .yakDotBad
        default: .yakDotNeutral
        }
    }

    /// The dot of the thread title: the same hues as the pill's dot.
    var color: Color { dot }
}

/// A short label on a tinted capsule: the tone is the tint.
struct TonePill: View {
    let text: String
    var tone: Tone = .neutral

    var body: some View {
        Text(verbatim: text).font(.yak(.footnote, .semibold)).foregroundStyle(tone.pillText)
            .padding(.horizontal, Design.Space.snug).padding(.vertical, Design.Space.pillVertical)
            .frame(minHeight: Design.Size.pill)
            .background(tone.pillBackground, in: .capsule)
            .fixedSize(horizontal: false, vertical: true)
    }
}

extension View {
}
