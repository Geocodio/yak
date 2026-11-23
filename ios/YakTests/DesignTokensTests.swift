import SwiftUI
import Testing
import UIKit

@testable import Yak

/// The web theme's type and colors: the bundled faces load, and the text colors keep 4.5 to 1 on the surfaces they sit
/// on, in both appearances.
@Suite struct DesignTokensTests {
    @Test func theBundledFacesLoad() {
        for weight in [Font.Weight.regular, .medium, .semibold, .bold] {
            let name = Font.yakFaceName(weight: weight, isMonospaced: false)
            #expect(UIFont(name: name, size: 15) != nil, "\(name) is not registered")
        }
        let mono = Font.yakFaceName(weight: .regular, isMonospaced: true)
        #expect(UIFont(name: mono, size: 13) != nil, "\(mono) is not registered")
    }

    private func luminance(_ color: Color, _ style: UIUserInterfaceStyle) -> Double {
        let resolved = UIColor(color).resolvedColor(with: UITraitCollection(userInterfaceStyle: style))
        var red: CGFloat = 0
        var green: CGFloat = 0
        var blue: CGFloat = 0
        var alpha: CGFloat = 0
        resolved.getRed(&red, green: &green, blue: &blue, alpha: &alpha)
        func linear(_ value: CGFloat) -> Double {
            Double(value <= 0.03928 ? value / 12.92 : pow((value + 0.055) / 1.055, 2.4))
        }
        return 0.2126 * linear(red) + 0.7152 * linear(green) + 0.0722 * linear(blue)
    }

    private func ratio(_ text: Color, on surface: Color, _ style: UIUserInterfaceStyle) -> Double {
        let first = luminance(text, style)
        let second = luminance(surface, style)
        return (max(first, second) + 0.05) / (min(first, second) + 0.05)
    }

    @Test(arguments: [UIUserInterfaceStyle.light, .dark]) func textColorsKeepTheirContrast(
        style: UIUserInterfaceStyle
    ) {
        let surfaces: [Color] = [.yakBackground, .yakSidebar, .yakCard, .yakBubble]
        let texts: [(String, Color)] = [
            ("text", .yakText), ("muted", .yakMuted), ("brand", .yakSlate), ("warning", .yakWarning),
            ("blocked", .yakCardDangerText),
        ]
        for (name, text) in texts {
            for surface in surfaces {
                #expect(ratio(text, on: surface, style) >= 4.5, "\(name) in \(style.rawValue)")
            }
        }
    }
}
