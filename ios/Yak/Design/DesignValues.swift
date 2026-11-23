import SwiftUI
import UIKit

/// The design values of the approved boards: colors, spacing steps, radii, control sizes and type. Views use these
/// names and never a bare number, so the screens stay equal to the boards in one place.
enum Design {
    /// How faded a disabled control looks.
    static let disabledOpacity: Double = 0.4

    /// Gaps and insets, in points.
    enum Space {
        static let hairline: CGFloat = 0.5
        static let line: CGFloat = 1
        /// Between the lines of a two line label.
        static let nudge: CGFloat = 2
        static let tight: CGFloat = 4
        static let small: CGFloat = 6
        static let compact: CGFloat = 8
        /// Between compact and medium: a bubble's vertical padding and a menu button's trailing padding.
        static let snug: CGFloat = 10
        static let medium: CGFloat = 12
        static let large: CGFloat = 16
        static let section: CGFloat = 24
        /// Left and right of text, bubbles and the cards of every pane, so all panes line up.
        static let pageInset: CGFloat = 16
        /// Left and right inside a message bubble.
        static let bubbleInset: CGFloat = 15
        /// Left and right of a list of grouped rows (the boards' 14 point inset around a group).
        static let groupInset: CGFloat = 14
        /// Left and right inside a group's row.
        static let rowInset: CGFloat = 16
        /// Left and right of the floating top row and of the pane's bars.
        static let barInset: CGFloat = 10
        /// Between a pane's bottom edge and the bar.
        static let barGap: CGFloat = 6
        /// Between the screen's bottom edge and the bar.
        static let barEdgeGap: CGFloat = 30
        /// Padding between a bar's edge and its controls.
        static let barPadding: CGFloat = 6
        /// Padding inside the terminal around its text.
        static let terminalInset: CGFloat = 14
        /// Between two blocks of the chat.
        static let chatGap: CGFloat = 16
        /// Top and bottom padding of a status pill.
        static let pillVertical: CGFloat = 3
    }

    /// Corner radii.
    enum Radius {
        static let small: CGFloat = 8
        static let code: CGFloat = 6
        /// A button and a field: `--radius-control`.
        static let control: CGFloat = 6
        /// A badge or a strip: `--radius-chip`.
        static let chip: CGFloat = 5
        static let key: CGFloat = 6
        /// A card and the composer: `--radius-card`.
        static let card: CGFloat = 8
        /// A code block and a table in the chat.
        static let block: CGFloat = 10
        static let row: CGFloat = 6
        static let group: CGFloat = 8
        static let question: CGFloat = 26
        static let bar: CGFloat = 8
        static let bubble: CGFloat = 22
        static let bubbleTail: CGFloat = 8
        /// The pane's own corners in a layout with two panes.
        static let pane: CGFloat = 28
        /// The card above the composer.
        static let workCard: CGFloat = 22
    }

    /// The card above the composer.
    enum Card {
        /// A share of the pane's height, and the card scrolls its text inside beyond it.
        static let heightShare: CGFloat = 0.6
        /// A pane shorter than this (a phone on its side, or with the keyboard up) offers the card the short share.
        static let shortPane: CGFloat = 500
        static let shortShare: CGFloat = 0.45
        /// At the accessibility sizes the card leaves more of the pane to the chat.
        static let largeShare: CGFloat = 0.5
        /// The least height the card is offered: its title and buttons.
        static let leastHeight: CGFloat = 160
        /// The least the chat keeps above a card, so its text always has room to be read.
        static let chatLeastHeight: CGFloat = 120
        /// The least a card's text area keeps when the buttons under it need the room: a title line.
        static let textLeastHeight: CGFloat = 60
    }

    /// Control sizes.
    enum Size {
        static let control: CGFloat = 44
        /// A distance that puts a view wholly outside any screen.
        static let offscreen: CGFloat = 4000
        /// The widest a person's message bubble grows.
        static let bubbleMaximumWidth: CGFloat = 300
        /// The drawer's search field and new thread button.
        static let bar: CGFloat = 44
        static let key: CGFloat = 44
        static let chip: CGFloat = 36
        /// The drawer's filter chip when it shows its name.
        static let filterChip: CGFloat = 40
        /// The status column of a step list.
        static let stepColumn: CGFloat = 24
        static let uploadBarWidth: CGFloat = 160
        static let uploadBarHeight: CGFloat = 3
        /// One item of the pane row on a wide screen.
        static let paneItemWidth: CGFloat = 50
        static let paneItemHeight: CGFloat = 50
        static let smallChip: CGFloat = 32
        static let row: CGFloat = 52
        static let listRow: CGFloat = 50
        static let option: CGFloat = 52
        static let statusColumn: CGFloat = 20
        static let statusDot: CGFloat = 9
        static let sectionDot: CGFloat = 10
        /// A card's button.
        static let cardButton: CGFloat = 50
        /// The widest the request row and the chat's text grow on a wide screen.
        static let chatMaximumWidth: CGFloat = 720
        /// A status pill's smallest height.
        static let pill: CGFloat = 24
        /// The source chip's drawn height.
        static let sourceChip: CGFloat = 28
    }
}

/// What a board color becomes under Increase Contrast; a field left nil keeps the normal value.
struct BoardContrast {
    var dark: UInt32?
    var light: UInt32?
    var darkAlpha: Double?
    var lightAlpha: Double?
}

extension Color {
    /// A color that follows the appearance, from the boards' two token sets. Under Increase Contrast it takes
    /// `highContrast` when given: the same fields, each falling back to the normal value.
    static func board(
        dark: UInt32, light: UInt32, darkAlpha: Double = 1, lightAlpha: Double = 1, highContrast: BoardContrast? = nil
    ) -> Color {
        Color(
            uiColor: UIColor { traits in
                let isDark = traits.userInterfaceStyle == .dark
                let isHigh = traits.accessibilityContrast == .high
                let value =
                    isDark ? (isHigh ? highContrast?.dark : nil) ?? dark : (isHigh ? highContrast?.light : nil) ?? light
                let alpha =
                    isDark
                    ? (isHigh ? highContrast?.darkAlpha : nil) ?? darkAlpha
                    : (isHigh ? highContrast?.lightAlpha : nil) ?? lightAlpha
                return UIColor(
                    red: CGFloat((value >> 16) & 0xFF) / 255, green: CGFloat((value >> 8) & 0xFF) / 255,
                    blue: CGFloat(value & 0xFF) / 255, alpha: alpha)
            })
    }

    /// The page: `--bg`.
    static let yakBackground = board(dark: 0x0F1011, light: 0xFBFBFC)
    /// The drawer's surface: `--sidebar`.
    static let yakSidebar = board(dark: 0x141517, light: 0xF5F6F8)
    /// Grouped rows, panels and fields: `--panel`.
    static let yakCard = board(dark: 0x171819, light: 0xFFFFFF)
    /// Message bubbles, chips and the quiet fill of a control on a panel: `--panel-2`.
    static let yakBubble = board(dark: 0x232427, light: 0xEEF0F2)
    /// The composer card and the sheets' bars, opaque: `--panel`.
    static let yakBar = board(dark: 0x171819, light: 0xFFFFFF)
    /// The line around a field, a card and a button: `--border-strong`.
    static let yakBorderStrong = board(
        dark: 0x303137, light: 0xE0E1E6, highContrast: BoardContrast(dark: 0x8A8D95, light: 0x6A6D75))
    /// The border of a bar's chip: `--border-strong`.
    static let yakBarBorder = yakBorderStrong
    /// A quiet control's fill, opaque: `--panel-2`.
    static let yakFill = board(
        dark: 0x232427, light: 0xEEF0F2, highContrast: BoardContrast(dark: 0x3A3B3E, light: 0xDCDCDE))
    /// A pressed or selected control's fill, opaque: `--border-strong`'s tone, the web's pressed fill.
    static let yakFillStrong = board(
        dark: 0x303137, light: 0xE0E1E6, highContrast: BoardContrast(dark: 0x4A4B4E, light: 0xCBCBCD))
    /// A control inside the composer card: `--panel-2`.
    static let yakBarFill = board(
        dark: 0x232427, light: 0xEEF0F2, highContrast: BoardContrast(dark: 0x45464A, light: 0xDCDCDE))
    /// A selected or pressed control inside the composer card.
    static let yakBarFillStrong = board(
        dark: 0x303137, light: 0xE0E1E6, highContrast: BoardContrast(dark: 0x55565A, light: 0xCBCBCD))
    /// The line between rows and under and above a bar: `--border`.
    static let yakLine = board(
        dark: 0x232428, light: 0xEBECEF, highContrast: BoardContrast(dark: 0x8A8D95, light: 0x6A6D75))
    /// The selected row: `--brand-soft`.
    static let yakSelection = board(
        dark: 0x7F9CB4, light: 0x4A6580, darkAlpha: 0.16, lightAlpha: 0.12,
        highContrast: BoardContrast(darkAlpha: 0.3, lightAlpha: 0.22))
    /// The terminal's page: `--term`.
    static let yakTerminal = Color(red: 8 / 255, green: 9 / 255, blue: 10 / 255)
    /// Text on the page: `--tx`.
    static let yakText = board(dark: 0xE6E7EA, light: 0x282A30)
    /// The accent as a fill: `--accent`.
    static let yakAccent = board(dark: 0xD58F71, light: 0xB45C35)
    /// Success and a running step: `--ok`.
    static let yakOK = board(dark: 0x6FBE68, light: 0x3E8E38)
    /// Warning text: `--warn` in dark; in light a darker orange, because the web's `#D97A22` is under 4.5 to 1 as text.
    static let yakWarning = board(dark: 0xE8985A, light: 0x9E5400)
    /// A thread waiting for an answer, as a dot: `--warn`.
    static let yakWarningDot = board(dark: 0xE8985A, light: 0xD97A22)
    /// Blocked, as a dot: `--fail`.
    static let yakFailure = board(dark: 0xE0655F, light: 0xC93F3B)
    /// A step that has news: `--info`.
    static let yakInfo = board(dark: 0x6FA6D8, light: 0x3D7CB8)
    /// Running, a selected row's tone and the shortcut icons: `--brand`.
    static let yakSlate = board(dark: 0x7F9CB4, light: 0x4A6580)
    /// The soft shadow under a card: `--shadow-card`.
    static let yakShadow = board(dark: 0x000000, light: 0x282A30, darkAlpha: 0.45, lightAlpha: 0.10)
    /// The faintest text color, for marks that are not text: `--text-3`.
    static let yakFaint = board(dark: 0x62666E, light: 0x9598A1)
    /// Text on the accent: `--accent-ink`.
    static let yakAccentInk = board(dark: 0x101014, light: 0xFFFFFF)
}

/// The colors of the cards, pills and chips that show the work of a thread, from the work boards.
extension Color {
    /// The white row on a card.
    static let yakCardRow = board(dark: 0x2C2C2E, light: 0xFFFFFF)
    /// The source chip and the neutral card.
    static let yakCardSurface = board(dark: 0x1C1C1E, light: 0xF2F2F7)
    static let yakCardWarnSurface = board(dark: 0x2E2206, light: 0xFDF3DC)
    static let yakCardBadSurface = board(dark: 0x32110F, light: 0xFDECEA)
    static let yakCardButton = board(dark: 0x3A3A3C, light: 0xE5E5EA)
    static let yakCardPrimary = board(dark: 0xFFFFFF, light: 0x1C1C1E)
    static let yakCardPrimaryText = board(dark: 0x000000, light: 0xFFFFFF)
    static let yakCardDangerText = board(dark: 0xFF8A80, light: 0xA0201A)
    static let yakCardSeparator = board(dark: 0x3A3A3C, light: 0xD1D1D6)
    static let yakCardSecondaryText = board(dark: 0xD1D1D6, light: 0x3A3A3C)
    static let yakPillNeutral = board(dark: 0x2C2C2E, light: 0xE5E5EA)
    static let yakPillNeutralText = board(dark: 0xE5E5EA, light: 0x2C2C2E)
    static let yakPillOK = board(dark: 0x12381F, light: 0xDCF2E3)
    static let yakPillOKText = board(dark: 0xB9EFC8, light: 0x14532D)
    static let yakPillWarn = board(dark: 0x3D2C05, light: 0xFBEBC8)
    static let yakPillWarnText = board(dark: 0xF6D98F, light: 0x6B4A00)
    static let yakPillBad = board(dark: 0x451512, light: 0xFBDCD8)
    static let yakPillBadText = board(dark: 0xF7C1BB, light: 0x7F1D1D)
    static let yakDotOK = board(dark: 0x4CC071, light: 0x1E7A3C)
    static let yakDotWarn = board(dark: 0xF0A93B, light: 0xB26A00)
    static let yakDotBad = board(dark: 0xF2695F, light: 0xB3261E)
    static let yakDotNeutral = board(dark: 0x8E8E93, light: 0x8E8E93)
}

/// The type scale of the web theme: IBM Plex Sans and IBM Plex Mono, bundled with the app. Every style takes the point
/// size of the system text style it stands for at the default size and scales with it, so Dynamic Type works. Views
/// pick a text style and a weight; none names a font file.
extension Font {
    /// The point sizes of the system text styles at the default content size.
    private static func baseSize(of style: Font.TextStyle) -> CGFloat {
        switch style {
        case .largeTitle: 34
        case .title: 28
        case .title2: 22
        case .title3: 20
        case .headline, .body: 17
        case .callout: 16
        case .subheadline: 15
        case .footnote: 13
        case .caption: 12
        case .caption2: 11
        @unknown default: 17
        }
    }

    /// The face for a weight: regular, medium, or semibold for anything heavier. Mono has the regular face only.
    static func yakFaceName(weight: Font.Weight, isMonospaced: Bool) -> String {
        if isMonospaced { return "IBMPlexMono" }
        switch weight {
        case .ultraLight, .thin, .light, .regular: return "IBMPlexSans"
        case .medium: return "IBMPlexSans-Medm"
        default: return "IBMPlexSans-SmBld"
        }
    }

    /// A text style in Plex, scaling with Dynamic Type.
    static func yak(_ style: Font.TextStyle, _ weight: Font.Weight = .regular, isMonospaced: Bool = false) -> Font {
        .custom(yakFaceName(weight: weight, isMonospaced: isMonospaced), size: baseSize(of: style), relativeTo: style)
    }

    /// Titles in the header: 15 pt semibold.
    static let yakBarTitle = Font.yak(.subheadline, .semibold)
    /// Row title: 15 pt, regular; an unread row is semibold.
    static let yakRowTitle = Font.yak(.subheadline)
    /// Row detail and the time: 13 pt.
    static let yakRowDetail = Font.yak(.footnote)
    /// A group's heading: 13 pt semibold.
    static let yakGroupHeading = Font.yak(.footnote, .semibold)
    /// A chip and a terminal key: 13 pt medium.
    static let yakChip = Font.yak(.footnote, .medium)
    /// The terminal: 13 pt monospaced.
    static let yakTerminal = Font.yak(.footnote, isMonospaced: true)
    /// The message field: 16 pt.
    static let yakField = Font.yak(.callout)
}

extension EnvironmentValues {
    /// True in a thread that the system's vertical pane strip stands beside.
    @Entry var hasSystemStrip = false
}

/// The page color. Beside the system's pane strip it is pure black in dark and pure white in light: the audit reads
/// the strip's glass labels ("Preview", "Overview", "Terminal") as low contrast over the board colors (`#0f1011`,
/// `#FBFBFC`), though the rendered label is white on about `#2E2F30` (13.4 to 1) in dark, and passes them over black
/// and white.
struct PageStyle: ShapeStyle {
    func resolve(in environment: EnvironmentValues) -> Color {
        guard environment.hasSystemStrip else { return .yakBackground }
        return environment.colorScheme == .dark ? .black : .white
    }
}

extension ShapeStyle where Self == PageStyle {
    static var yakPage: PageStyle { PageStyle() }
}

extension View {
    /// A card's background with a line around it, so a card on a page of its own color keeps its edge.
    func yakCardSurface<S: InsettableShape>(in shape: S) -> some View {
        background(Color.yakCard, in: shape).overlay(
            shape.strokeBorder(Color.yakLine, lineWidth: Design.Space.line))
    }

    /// A bar's surface and hairline on a chip that floats above the bar.
    func yakBarSurface<S: InsettableShape>(in shape: S) -> some View {
        background(Color.yakBar, in: shape).overlay(
            shape.strokeBorder(Color.yakBarBorder, lineWidth: Design.Space.line))
    }
}
