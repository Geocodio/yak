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
        Text(verbatim: text).font(.footnote.weight(.semibold)).foregroundStyle(tone.pillText)
            .padding(.horizontal, Design.Space.snug).padding(.vertical, Design.Space.pillVertical)
            .frame(minHeight: Design.Size.pill)
            .background(tone.pillBackground, in: .capsule)
            .fixedSize(horizontal: false, vertical: true)
    }
}

/// The surface of a card: the tone's whole surface, with the board's padding and radius.
private struct WorkCardSurface: ViewModifier {
    let tone: Tone

    func body(content: Content) -> some View {
        content
            .padding(Design.Space.large).frame(maxWidth: .infinity, alignment: .leading)
            .background(tone.surface, in: .rect(cornerRadius: Design.Radius.workCard))
    }
}

/// A white row inside a card.
private struct WorkBox: ViewModifier {
    let fill: Color

    func body(content: Content) -> some View {
        content
            .padding(.vertical, Design.Space.snug).padding(.horizontal, Design.Space.groupInset)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(fill, in: .rect(cornerRadius: Design.Radius.card))
    }
}

extension View {
    func workCardSurface(tone: Tone) -> some View { modifier(WorkCardSurface(tone: tone)) }
    func workBox(fill: Color = .yakCardRow) -> some View { modifier(WorkBox(fill: fill)) }
}

/// The buttons of a card: 50 points high with the board's radius. Primary is the ink fill, secondary a gray fill and
/// danger a gray fill with red words.
struct WorkButtonStyle: ButtonStyle {
    enum Kind { case primary, secondary, danger }
    let kind: Kind
    @Environment(\.isEnabled) private var isEnabled

    func makeBody(configuration: Configuration) -> some View {
        let isPrimary = kind == .primary
        configuration.label
            .font(.headline)
            .foregroundStyle(foreground(isPrimary: isPrimary))
            .multilineTextAlignment(.center)
            .frame(maxWidth: .infinity, minHeight: Design.Size.cardButton)
            .padding(.horizontal, Design.Space.groupInset)
            .background(
                isPrimary && isEnabled ? Color.yakCardPrimary : Color.yakCardButton,
                in: .rect(cornerRadius: Design.Radius.key)
            )
            .opacity(configuration.isPressed ? 0.85 : 1)
    }

    private func foreground(isPrimary: Bool) -> Color {
        guard isEnabled else { return Color.primary.opacity(0.72) }
        switch kind {
        case .primary: return Color.yakCardPrimaryText
        case .secondary: return .primary
        case .danger: return Color.yakCardDangerText
        }
    }
}
