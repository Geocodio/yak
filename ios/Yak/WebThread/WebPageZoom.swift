import UIKit

/// The page zoom of the web conversation for a text size. The page sets its sizes in pixels, so a root font size would
/// change nothing; the zoom scales the page the way Dynamic Type scales a native text style.
enum WebPageZoom {
    static let standard: CGFloat = 1
    /// The page stays usable on a phone at the largest accessibility size only up to this zoom.
    static let maximum: CGFloat = 1.6
    /// The smallest sizes shrink the page no further than this.
    static let minimum: CGFloat = 0.85

    static func value(for category: UIContentSizeCategory) -> CGFloat {
        let traits = UITraitCollection(preferredContentSizeCategory: category)
        let scaled = UIFontMetrics(forTextStyle: .body).scaledValue(for: standard, compatibleWith: traits)
        return min(max(scaled, minimum), maximum)
    }
}
