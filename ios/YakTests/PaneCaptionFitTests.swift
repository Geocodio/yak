import Testing
import UIKit

/// The trailing strip captions its panes in caption2 on one line. With five panes the longest name is "Overview";
/// at the default text size every caption stays inside the 56 point cell the pane row uses.
@Suite struct PaneCaptionFitTests {
    @Test func fivePaneCaptionsFitOneLine() {
        let names = ["Chat", "Terminal", "Preview", "Files", "Overview"]
        let font = UIFont.preferredFont(
            forTextStyle: .caption2, compatibleWith: UITraitCollection(preferredContentSizeCategory: .large))
        for name in names {
            let width = (name as NSString).size(withAttributes: [.font: font]).width
            #expect(width <= 56, "\(name) is \(width) points wide")
        }
    }
}
