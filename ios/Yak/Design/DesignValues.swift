import SwiftUI
import UIKit

/// The design values of the approved boards: colors, spacing steps, radii, control sizes and type. Views use these
/// names and never a bare number, so the screens stay equal to the boards in one place.
enum Design {
    /// Gaps and insets, in points.
    enum Space {
        static let hairline: CGFloat = 0.5
        static let tight: CGFloat = 4
        static let small: CGFloat = 6
        static let compact: CGFloat = 8
        static let medium: CGFloat = 12
        static let large: CGFloat = 16
        static let section: CGFloat = 24
        /// Left and right of text, bubbles and the cards of every pane, so all panes line up.
        static let pageInset: CGFloat = 16
        /// Left and right of a list of grouped rows (the boards' 14 point inset around a group).
        static let groupInset: CGFloat = 14
        /// Left and right inside a group's row.
        static let rowInset: CGFloat = 16
        /// Left and right of the floating top row and of the pane's bars.
        static let barInset: CGFloat = 10
        /// Between a pane's bottom edge and the bar.
        static let barGap: CGFloat = 6
        /// Between the screen's bottom edge and the bar.
        static let barEdgeGap: CGFloat = 24
        /// Padding between a bar's edge and its controls.
        static let barPadding: CGFloat = 6
        /// Padding inside the terminal around its text.
        static let terminalInset: CGFloat = 14
        /// Between two blocks of the chat.
        static let chatGap: CGFloat = 16
    }

    /// Corner radii.
    enum Radius {
        static let small: CGFloat = 8
        static let code: CGFloat = 6
        static let key: CGFloat = 14
        static let row: CGFloat = 18
        static let group: CGFloat = 20
        static let question: CGFloat = 26
        static let bar: CGFloat = 30
        static let bubble: CGFloat = 22
        static let bubbleTail: CGFloat = 8
        /// The pane's own corners in a layout with two panes.
        static let pane: CGFloat = 28
    }

    /// Control sizes.
    enum Size {
        static let control: CGFloat = 44
        /// The widest a person's message bubble grows.
        static let bubbleMaximumWidth: CGFloat = 300
        /// The drawer's search field and new thread button.
        static let bar: CGFloat = 52
        static let key: CGFloat = 44
        static let chip: CGFloat = 36
        /// One item of the pane row on a wide screen.
        static let paneItemWidth: CGFloat = 62
        static let paneItemHeight: CGFloat = 50
        static let smallChip: CGFloat = 32
        static let row: CGFloat = 60
        static let listRow: CGFloat = 50
        static let option: CGFloat = 52
        static let statusColumn: CGFloat = 20
        static let statusDot: CGFloat = 9
        static let sectionDot: CGFloat = 10
    }
}

extension Color {
    /// A color that follows the appearance, from the boards' two token sets.
    static func board(dark: UInt32, light: UInt32, darkAlpha: Double = 1, lightAlpha: Double = 1) -> Color {
        Color(
            uiColor: UIColor { traits in
                let isDark = traits.userInterfaceStyle == .dark
                let value = isDark ? dark : light
                return UIColor(
                    red: CGFloat((value >> 16) & 0xFF) / 255, green: CGFloat((value >> 8) & 0xFF) / 255,
                    blue: CGFloat(value & 0xFF) / 255, alpha: isDark ? darkAlpha : lightAlpha)
            })
    }

    /// The page: `--bg`.
    static let yakBackground = board(dark: 0x0F1011, light: 0xFBFBFC)
    /// Grouped rows and panels: `--card`.
    static let yakCard = board(dark: 0x1B1C1F, light: 0xFFFFFF)
    /// Message bubbles and chips on a card: `--bub`.
    static let yakBubble = board(dark: 0x26272B, light: 0xECEEF1)
    /// The bars and the question card, opaque: `--gl` laid over the page.
    static let yakBar = board(dark: 0x1F2024, light: 0xFFFFFF)
    /// The hairline around a bar: `--gb`.
    static let yakBarBorder = board(dark: 0xFFFFFF, light: 0x000000, darkAlpha: 0.14, lightAlpha: 0.08)
    /// A quiet control's fill, opaque: `--fill` laid over the page, so text on it keeps its contrast.
    static let yakFill = board(dark: 0x262729, light: 0xEBEBEC)
    /// A pressed or selected control's fill, opaque: `--fill2` laid over the page.
    static let yakFillStrong = board(dark: 0x2D2E30, light: 0xE2E2E3)
    /// The line between rows: `--line`.
    static let yakLine = board(dark: 0xFFFFFF, light: 0x000000, darkAlpha: 0.09, lightAlpha: 0.08)
    /// The terminal's page: `--term`.
    static let yakTerminal = Color(red: 8 / 255, green: 9 / 255, blue: 10 / 255)
    /// Text on the page: `--tx`.
    static let yakText = board(dark: 0xF1F2F4, light: 0x1C1D21)
    /// The accent as a fill: `--acc`.
    static let yakAccent = board(dark: 0xD58F71, light: 0xB45C35)
    /// Success and a running step: `--ok`.
    static let yakOK = board(dark: 0x6FBE68, light: 0x3E8E38)
    /// Waiting and a warning: `--wn` in dark; in light a darker orange, so it reads as text on a light surface.
    static let yakWarning = board(dark: 0xE8985A, light: 0x9E5400)
    /// A step that has news: `--info`.
    static let yakInfo = board(dark: 0x6FA6D8, light: 0x3D7CB8)
    /// Icons of the drawer's two shortcuts: `--slate`.
    static let yakSlate = board(dark: 0x7F9CB4, light: 0x4A6580)
}

/// The boards' text styles. Each one is a system text style, so it follows Dynamic Type.
extension Font {
    /// Titles in the top row: 15 pt semibold.
    static let yakBarTitle = Font.subheadline.weight(.semibold)
    /// Row title: 17 pt medium.
    static let yakRowTitle = Font.body.weight(.medium)
    /// Row detail and the time: 14 pt.
    static let yakRowDetail = Font.footnote.weight(.medium)
    /// A group's heading: 14 pt semibold.
    static let yakGroupHeading = Font.footnote.weight(.semibold)
    /// A chip and a terminal key: 14 pt semibold or medium.
    static let yakChip = Font.footnote.weight(.semibold)
    /// The terminal: 13 pt monospaced.
    static let yakTerminal = Font.system(.footnote, design: .monospaced)
}

extension EnvironmentValues {
    /// True in a thread that the system's vertical pane strip stands beside.
    @Entry var hasSystemStrip = false
}

/// The page color. In dark beside the system's pane strip it is black: the audit reads the strip's glass labels
/// ("Preview", "Terminal") as low contrast over the board color `#0f1011` (the rendered label is white on about
/// `#2E2F30`, 13.4 to 1), and passes them over black.
struct PageStyle: ShapeStyle {
    func resolve(in environment: EnvironmentValues) -> Color {
        environment.hasSystemStrip && environment.colorScheme == .dark ? .black : .yakBackground
    }
}

extension ShapeStyle where Self == PageStyle {
    static var yakPage: PageStyle { PageStyle() }
}
