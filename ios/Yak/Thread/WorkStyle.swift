import SwiftUI
import UIKit
import YakKit

/// The measurements and colors of the views that show the work of a thread, read from the approved boards. Every new
/// view of the work reads them from here.
enum WorkStyle {
    /// The gap between two rows of the chat: the lazy stack's own.
    static let rowGap: CGFloat = 14
    static let cardRadius: CGFloat = 22
    static let cardPadding: CGFloat = 16
    static let cardGap: CGFloat = 10
    static let cardSideMargin: CGFloat = 8
    static let boxRadius: CGFloat = 14
    static let boxPaddingVertical: CGFloat = 10
    static let boxPaddingHorizontal: CGFloat = 14
    static let buttonHeight: CGFloat = 50
    static let buttonRadius: CGFloat = 14
    static let buttonGap: CGFloat = 8
    static let pillHeight: CGFloat = 24
    static let pillPaddingVertical: CGFloat = 3
    static let pillPaddingHorizontal: CGFloat = 10
    static let pillGap: CGFloat = 6
    static let chipHeight: CGFloat = 28
    static let chipPaddingVertical: CGFloat = 4
    static let headerGap: CGFloat = 8
    static let dotSize: CGFloat = 9
    static let noteRadius: CGFloat = 18
    static let notePaddingVertical: CGFloat = 12
    static let notePaddingHorizontal: CGFloat = 14
    /// The smallest height a control gets for a finger; a control drawn smaller keeps this as its hit area.
    static let tapTarget: CGFloat = 44
}

extension Color {
    /// A color from two 24-bit hex values, one for light and one for dark.
    init(light: UInt32, dark: UInt32) {
        func make(_ hex: UInt32) -> UIColor {
            UIColor(
                red: CGFloat((hex >> 16) & 0xFF) / 255, green: CGFloat((hex >> 8) & 0xFF) / 255,
                blue: CGFloat(hex & 0xFF) / 255, alpha: 1)
        }
        self.init(UIColor { $0.userInterfaceStyle == .dark ? make(dark) : make(light) })
    }
}

/// The tones of the boards: a pill, a dot and a whole card surface. Info and any word the app does not know read as
/// neutral.
extension Tone {
    var surface: Color {
        switch self {
        case .warn: Color(light: 0xFDF3DC, dark: 0x2E2206)
        case .bad: Color(light: 0xFDECEA, dark: 0x32110F)
        default: Color(light: 0xF2F2F7, dark: 0x1C1C1E)
        }
    }

    var pillBackground: Color {
        switch self {
        case .ok: Color(light: 0xDCF2E3, dark: 0x12381F)
        case .warn: Color(light: 0xFBEBC8, dark: 0x3D2C05)
        case .bad: Color(light: 0xFBDCD8, dark: 0x451512)
        default: Color(light: 0xE5E5EA, dark: 0x2C2C2E)
        }
    }

    var pillText: Color {
        switch self {
        case .ok: Color(light: 0x14532D, dark: 0xB9EFC8)
        case .warn: Color(light: 0x6B4A00, dark: 0xF6D98F)
        case .bad: Color(light: 0x7F1D1D, dark: 0xF7C1BB)
        default: Color(light: 0x2C2C2E, dark: 0xE5E5EA)
        }
    }

    var dot: Color {
        switch self {
        case .ok: Color(light: 0x1E7A3C, dark: 0x4CC071)
        case .warn: Color(light: 0xB26A00, dark: 0xF0A93B)
        case .bad: Color(light: 0xB3261E, dark: 0xF2695F)
        default: Color(light: 0x8E8E93, dark: 0x8E8E93)
        }
    }

    /// The dot of the thread title: the same hues as the pill's dot, kept as before for the title's own colors.
    var color: Color { dot }
}

enum WorkColor {
    /// The white row on a card, and the chip.
    static let box = Color(light: 0xFFFFFF, dark: 0x2C2C2E)
    static let chip = Color(light: 0xF2F2F7, dark: 0x1C1C1E)
    static let buttonFill = Color(light: 0xE5E5EA, dark: 0x3A3A3C)
    static let primaryFill = Color(light: 0x1C1C1E, dark: 0xFFFFFF)
    static let primaryText = Color(light: 0xFFFFFF, dark: 0x000000)
    static let dangerText = Color(light: 0xA0201A, dark: 0xFF8A80)
    static let separator = Color(light: 0xD1D1D6, dark: 0x3A3A3C)
    static let secondaryText = Color(light: 0x3A3A3C, dark: 0xD1D1D6)
}

/// A short label on a tinted capsule: the tone is the tint.
struct TonePill: View {
    let text: String
    var tone: Tone = .neutral

    var body: some View {
        Text(verbatim: text).font(.footnote.weight(.semibold)).foregroundStyle(tone.pillText)
            .padding(.horizontal, WorkStyle.pillPaddingHorizontal).padding(.vertical, WorkStyle.pillPaddingVertical)
            .frame(minHeight: WorkStyle.pillHeight)
            .background(tone.pillBackground, in: .capsule)
            .fixedSize(horizontal: false, vertical: true)
    }
}

/// The surface of a card: the tone's whole surface, with the board's padding and radius.
private struct WorkCardSurface: ViewModifier {
    let tone: Tone

    func body(content: Content) -> some View {
        content
            .padding(WorkStyle.cardPadding).frame(maxWidth: .infinity, alignment: .leading)
            .background(tone.surface, in: .rect(cornerRadius: WorkStyle.cardRadius))
    }
}

/// A white row inside a card.
private struct WorkBox: ViewModifier {
    func body(content: Content) -> some View {
        content
            .padding(.vertical, WorkStyle.boxPaddingVertical).padding(.horizontal, WorkStyle.boxPaddingHorizontal)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(WorkColor.box, in: .rect(cornerRadius: WorkStyle.boxRadius))
    }
}

extension View {
    func workCardSurface(tone: Tone) -> some View { modifier(WorkCardSurface(tone: tone)) }
    func workBox() -> some View { modifier(WorkBox()) }
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
            .frame(maxWidth: .infinity, minHeight: WorkStyle.buttonHeight)
            .padding(.horizontal, 14)
            .background(
                isPrimary && isEnabled ? WorkColor.primaryFill : WorkColor.buttonFill,
                in: .rect(cornerRadius: WorkStyle.buttonRadius)
            )
            .opacity(configuration.isPressed ? 0.85 : 1)
    }

    private func foreground(isPrimary: Bool) -> Color {
        guard isEnabled else { return Color.primary.opacity(0.72) }
        switch kind {
        case .primary: return WorkColor.primaryText
        case .secondary: return .primary
        case .danger: return WorkColor.dangerText
        }
    }
}
