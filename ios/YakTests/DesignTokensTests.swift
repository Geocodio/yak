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
        for weight in [Font.Weight.regular, .medium, .semibold] {
            let mono = Font.yakFaceName(weight: weight, isMonospaced: true)
            #expect(UIFont(name: mono, size: 13) != nil, "\(mono) is not registered")
        }
        #expect(Font.yakFaceName(weight: .semibold, isMonospaced: true) == "IBMPlexMono-SmBld")
        #expect(Font.yakFaceName(weight: .medium, isMonospaced: true) == "IBMPlexMono-Medm")
        // Bold Text moves every weight one step up.
        #expect(Font.yakFaceName(weight: .regular, isMonospaced: false, isBoldText: true) == "IBMPlexSans-Medm")
        #expect(Font.yakFaceName(weight: .semibold, isMonospaced: false, isBoldText: true) == "IBMPlexSans-Bold")
        #expect(UIFont(name: "IBMPlexSans-Bold", size: 15) != nil)
    }

    /// Emphasis asks the system for the italic trait on the current face; the bundled italics answer it.
    @Test func italicAndBoldItalicResolveToPlexFaces() throws {
        func resolved(_ face: String, _ traits: UIFontDescriptor.SymbolicTraits) throws -> String {
            let base = try #require(UIFont(name: face, size: 15))
            let descriptor = try #require(base.fontDescriptor.withSymbolicTraits(traits))
            return UIFont(descriptor: descriptor, size: 15).fontName
        }
        let regular = Font.yakFaceName(weight: .regular, isMonospaced: false)
        #expect(try resolved(regular, .traitItalic) == "IBMPlexSans-Italic")
        #expect(try resolved(regular, [.traitBold, .traitItalic]) == "IBMPlexSans-BoldItalic")
        let semibold = Font.yakFaceName(weight: .semibold, isMonospaced: false)
        #expect(try resolved(semibold, .traitItalic).hasPrefix("IBMPlexSans"))
        #expect(try resolved(semibold, .traitItalic).contains("Italic"))
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

    private func trait(_ style: UIUserInterfaceStyle, highContrast: Bool) -> UITraitCollection {
        var traits = UITraitCollection(userInterfaceStyle: style)
        traits = traits.modifyingTraits { $0.accessibilityContrast = highContrast ? .high : .normal }
        return traits
    }

    private func rgba(_ color: Color, _ traits: UITraitCollection) -> (Double, Double, Double, Double) {
        let resolved = UIColor(color).resolvedColor(with: traits)
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

    /// The surface as drawn: a translucent one is laid over `base`, where it is used.
    private func surface(_ color: Color, over base: Color = .yakBackground, _ traits: UITraitCollection) -> Double {
        let top = rgba(color, traits)
        let bottom = rgba(base, traits)
        let mixed = (
            top.0 * top.3 + bottom.0 * (1 - top.3), top.1 * top.3 + bottom.1 * (1 - top.3),
            top.2 * top.3 + bottom.2 * (1 - top.3)
        )
        return luminance(mixed)
    }

    private func ratio(_ text: Color, on surfaceLuminance: Double, _ traits: UITraitCollection) -> Double {
        let value = rgba(text, traits)
        let first = luminance((value.0, value.1, value.2))
        return (max(first, surfaceLuminance) + 0.05) / (min(first, surfaceLuminance) + 0.05)
    }

    /// Each text token on every surface it is drawn on, in both appearances and with Increase Contrast.
    @Test(arguments: [
        (UIUserInterfaceStyle.light, false), (.dark, false), (.light, true), (.dark, true),
    ]) func textColorsKeepTheirContrast(style: UIUserInterfaceStyle, highContrast: Bool) {
        let traits = trait(style, highContrast: highContrast)
        let page = surface(.yakBackground, traits)
        let sidebar = surface(.yakSidebar, traits)
        let card = surface(.yakCard, traits)
        let bar = surface(.yakBar, traits)
        let quiet = surface(.yakBubble, traits)
        let fill = surface(.yakFill, traits)
        let pressed = surface(.yakFillStrong, traits)
        let selected = surface(.yakSelection, over: .yakSidebar, traits)
        let everywhere = [page, sidebar, card, bar, quiet, fill, pressed, selected]
        let calm = [page, sidebar, card, bar, quiet, fill, selected]
        var cases: [(String, Color, [Double])] = [
            ("text", .yakText, everywhere), ("primary", .primary, everywhere), ("muted", .yakMuted, everywhere),
            ("brand", .yakSlate, calm), ("warning", .yakWarning, [page, sidebar, card, bar, quiet]),
            ("blocked", .yakCardDangerText, everywhere), ("action", .yakAction, [page, card, bar, sidebar]),
            ("danger", .yakDanger, [page, card, bar, sidebar]),
        ]
        if !highContrast {
            // Not text under Increase Contrast: the dots and the success mark are never read as words.
            cases.append(("brand on selection", .yakSlate, [selected]))
        }
        for (name, text, surfaces) in cases {
            for (index, luminance) in surfaces.enumerated() {
                let value = ratio(text, on: luminance, traits)
                #expect(
                    value >= 4.5, "\(name) on surface \(index) in \(style.rawValue), high \(highContrast): \(value)")
            }
        }
    }

    /// The accent button text on the accent fill, and the quiet fill against the page it sits on.
    @Test(arguments: [UIUserInterfaceStyle.light, .dark]) func accentInkKeepsItsContrast(style: UIUserInterfaceStyle) {
        let traits = trait(style, highContrast: false)
        let accent = surface(.yakAccent, traits)
        #expect(ratio(.yakAccentInk, on: accent, traits) >= 4.5)
    }

    /// The drawer's tab row: the count on the selected Review tab, the count on a quiet tab, a quiet tab's name on the
    /// row's fill, the selected tab's name on its card, and the Filter count on the accent.
    @Test(arguments: [
        (UIUserInterfaceStyle.light, false), (.dark, false), (.light, true), (.dark, true),
    ]) func theTabRowKeepsItsContrast(style: UIUserInterfaceStyle, highContrast: Bool) {
        let traits = trait(style, highContrast: highContrast)
        let pairs: [(String, Color, Color)] = [
            ("selected count", .yakAccentInk, .yakSlate), ("quiet count", .yakText, .yakFillStrong),
            ("quiet tab", .yakMuted, .yakBubble), ("selected tab", .yakText, .yakTabSelected),
            ("filter count", .yakAccentInk, .yakAccent),
        ]
        for (name, text, fill) in pairs {
            let value = ratio(text, on: surface(fill, traits), traits)
            #expect(value >= 4.5, "\(name) in \(style.rawValue), high \(highContrast): \(value)")
        }
    }

    /// Under Increase Contrast the quiet fill and the pressed fill are a step apart from the page, so selected and
    /// pressed controls show.
    @Test(arguments: [UIUserInterfaceStyle.light, .dark]) func theQuietFillsDifferFromThePage(
        style: UIUserInterfaceStyle
    ) {
        for highContrast in [false, true] {
            let traits = trait(style, highContrast: highContrast)
            let page = surface(.yakBackground, traits)
            for fill in highContrast ? [Color.yakFillStrong, .yakBubble] : [Color.yakFillStrong] {
                let other = surface(fill, traits)
                let ratio = (max(page, other) + 0.05) / (min(page, other) + 0.05)
                #expect(ratio >= 1.15, "a fill is too close to the page, high \(highContrast): \(ratio)")
            }
        }
    }

    /// The pull request line of a Review row: the toned badges on their fills, the quiet badge, the other approvals on
    /// the surfaces of a row (the drawer, the selected row, the pressed row), and the muted number and error text.
    @Test(arguments: [
        (UIUserInterfaceStyle.light, false), (.dark, false), (.light, true), (.dark, true),
    ]) func theReviewLineKeepsItsContrast(style: UIUserInterfaceStyle, highContrast: Bool) {
        let traits = trait(style, highContrast: highContrast)
        let badges: [(String, Color, Color)] = [
            ("conflicts", .yakPillBadText, .yakPillBad), ("new commits", .yakPillWarnText, .yakPillWarn),
            ("ready", .yakText, .yakBubble),
        ]
        for (name, text, fill) in badges {
            let value = ratio(text, on: surface(fill, traits), traits)
            #expect(value >= 4.5, "\(name) in \(style.rawValue), high \(highContrast): \(value)")
        }
        let rowSurfaces = [
            surface(.yakSidebar, traits), surface(.yakBackground, traits),
            surface(.yakSelection, over: .yakSidebar, traits),
            surface(.yakFillStrong, traits),
        ]
        for (index, luminance) in rowSurfaces.enumerated() {
            for (name, text) in [("other approvals", Color.yakPillOKText), ("number", .yakMuted)] {
                let value = ratio(text, on: luminance, traits)
                #expect(
                    value >= 4.5, "\(name) on surface \(index) in \(style.rawValue), high \(highContrast): \(value)")
            }
        }
    }
}
