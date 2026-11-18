import SwiftUI
import UIKit
import WidgetKit
import YakKit

/// Colors and small pieces the widget views share.
enum WidgetChrome {
    /// Needs you: a question, a block or a co-owner request.
    static let accent = dynamic(light: (0.71, 0.28, 0.12), dark: (0.93, 0.52, 0.35))
    /// A finished, unseen item.
    static let info = dynamic(light: (0.10, 0.40, 0.80), dark: (0.45, 0.70, 1.0))
    /// A running thread.
    static let success = dynamic(light: (0.243, 0.557, 0.220), dark: (0.435, 0.745, 0.408))

    private static func dynamic(light: (Double, Double, Double), dark: (Double, Double, Double)) -> Color {
        Color(
            uiColor: UIColor { traits in
                let value = traits.userInterfaceStyle == .dark ? dark : light
                return UIColor(red: value.0, green: value.1, blue: value.2, alpha: 1)
            })
    }

    static func color(for dot: WidgetRowDot) -> Color {
        switch dot {
        case .accent: accent
        case .info: info
        case .success: success
        }
    }

    /// The tile's background on the home screen, light and dark.
    static let background = Color(
        uiColor: UIColor { traits in
            traits.userInterfaceStyle == .dark
                ? UIColor(red: 0.11, green: 0.11, blue: 0.12, alpha: 1)
                : UIColor(red: 0.97, green: 0.96, blue: 0.95, alpha: 1)
        })
}

/// The dot in front of a row; it carries the accent in the tinted and clear home screen styles.
struct WidgetDot: View {
    let dot: WidgetRowDot

    var body: some View {
        Circle().fill(WidgetChrome.color(for: dot)).frame(width: 8, height: 8).widgetAccentable()
            .accessibilityHidden(true)
    }
}

/// A row's title as text: a thread's own title is shown verbatim, never looked up as a key.
struct WidgetRowTitleText: View {
    let title: WidgetRowTitle

    var body: some View {
        switch title {
        case .text(let text): Text(verbatim: text)
        case .task(let text): Text(WidgetStrings.task(text))
        case .privateThread: Text(WidgetStrings.privateThread)
        case .unknown: Text(WidgetStrings.unknownThread)
        }
    }
}

extension WidgetRowTitle {
    /// The spoken form of a title, for accessibility labels.
    var spoken: String {
        switch self {
        case .text(let text): text
        case .task(let text): String(localized: WidgetStrings.task(text))
        case .privateThread: String(localized: WidgetStrings.privateThread)
        case .unknown: String(localized: WidgetStrings.unknownThread)
        }
    }
}
