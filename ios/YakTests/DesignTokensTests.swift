import SwiftUI
import Testing
import UIKit

@testable import Yak

/// The web theme's type and colors: the bundled faces load, every text style grows with Dynamic Type, and the text
/// colors keep 4.5 to 1 on every surface they sit on, in both appearances.
@Suite struct DesignTokensTests {
    @Test func theBundledFacesLoad() {
        for weight in [Font.Weight.regular, .medium, .semibold, .bold] {
            let name = Font.yakFaceName(weight: weight, isMonospaced: false)
            #expect(UIFont(name: name, size: 15) != nil, "\(name) is not registered")
        }
        let mono = Font.yakFaceName(weight: .regular, isMonospaced: true)
        #expect(UIFont(name: mono, size: 13) != nil, "\(mono) is not registered")
    }

    /// The point sizes the board names, at the default text size.
    @Test func theBoardSizesAreExact() {
        #expect(Font.yakPointSize(of: .subheadline) == 15)  // row title, header title
        #expect(Font.yakPointSize(of: .footnote) == 13)  // row detail, section heading, queued text
        #expect(Font.yakPointSize(of: .caption) == 12)  // time, second header line
        #expect(Font.yakPointSize(of: .headline) == 17)  // instance name
        #expect(Font.yakPointSize(of: .callout) == 16)  // message field
    }

    @MainActor private func width(_ style: Font.TextStyle, _ size: DynamicTypeSize, mono: Bool) -> CGFloat {
        let view = Text(verbatim: "Hamburgefonstiv").font(.yak(style, isMonospaced: mono)).fixedSize()
            .environment(\.dynamicTypeSize, size)
        return UIHostingController(rootView: view).sizeThatFits(in: CGSize(width: 4000, height: 4000)).width
    }

    /// A style made without `relativeTo:` would stay as wide at the largest size as at the default one.
    @MainActor
    @Test(arguments: [
        Font.TextStyle.largeTitle, .title, .title2, .title3, .headline, .body, .callout, .subheadline, .footnote,
        .caption, .caption2,
    ]) func everyStyleGrowsWithDynamicType(style: Font.TextStyle) {
        for isMonospaced in [false, true] {
            let normal = width(style, .large, mono: isMonospaced)
            let largest = width(style, .accessibility5, mono: isMonospaced)
            #expect(largest > normal * 1.3, "\(style) does not grow (mono \(isMonospaced)): \(normal) to \(largest)")
        }
    }

    private func rgba(_ color: Color, _ style: UIUserInterfaceStyle) -> (Double, Double, Double, Double) {
        let resolved = UIColor(color).resolvedColor(with: UITraitCollection(userInterfaceStyle: style))
        var red: CGFloat = 0
        var green: CGFloat = 0
        var blue: CGFloat = 0
        var alpha: CGFloat = 0
        resolved.getRed(&red, green: &green, blue: &blue, alpha: &alpha)
        return (Double(red), Double(green), Double(blue), Double(alpha))
    }

    private func luminance(_ value: (Double, Double, Double)) -> Double {
        func linear(_ part: Double) -> Double { part <= 0.03928 ? part / 12.92 : pow((part + 0.055) / 1.055, 2.4) }
        return 0.2126 * linear(value.0) + 0.7152 * linear(value.1) + 0.0722 * linear(value.2)
    }

    /// The surface as drawn: a translucent one is laid over the sidebar, where it is used.
    private func surface(_ color: Color, over base: Color = .yakBackground, _ style: UIUserInterfaceStyle) -> Double {
        let top = rgba(color, style)
        let bottom = rgba(base, style)
        let mixed = (
            top.0 * top.3 + bottom.0 * (1 - top.3), top.1 * top.3 + bottom.1 * (1 - top.3),
            top.2 * top.3 + bottom.2 * (1 - top.3)
        )
        return luminance(mixed)
    }

    private func ratio(_ text: Color, on surfaceLuminance: Double, _ style: UIUserInterfaceStyle) -> Double {
        let first = luminance((rgba(text, style).0, rgba(text, style).1, rgba(text, style).2))
        return (max(first, surfaceLuminance) + 0.05) / (min(first, surfaceLuminance) + 0.05)
    }

    /// Each text token on every surface it is drawn on.
    @Test(arguments: [UIUserInterfaceStyle.light, .dark]) func textColorsKeepTheirContrast(
        style: UIUserInterfaceStyle
    ) {
        let page = surface(.yakBackground, style)
        let sidebar = surface(.yakSidebar, style)
        let card = surface(.yakCard, style)
        let bar = surface(.yakBar, style)
        let quiet = surface(.yakBubble, style)
        let fill = surface(.yakFill, style)
        let pressed = surface(.yakFillStrong, style)
        let selected = surface(.yakSelection, over: .yakSidebar, style)
        let everywhere = [page, sidebar, card, bar, quiet, fill, pressed, selected]
        let calm = [page, sidebar, card, bar, quiet, fill, selected]
        let cases: [(String, Color, [Double])] = [
            ("text", .yakText, everywhere), ("primary", .primary, everywhere), ("muted", .yakMuted, everywhere),
            ("brand", .yakSlate, calm), ("warning", .yakWarning, [page, sidebar, card, bar, quiet]),
            ("blocked", .yakCardDangerText, everywhere), ("action", .yakAction, [page, card, bar, sidebar]),
        ]
        for (name, text, surfaces) in cases {
            for (index, luminance) in surfaces.enumerated() {
                let value = ratio(text, on: luminance, style)
                #expect(value >= 4.5, "\(name) on surface \(index) in \(style.rawValue): \(value)")
            }
        }
    }
}
