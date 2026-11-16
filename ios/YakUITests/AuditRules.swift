import XCTest

/// Rules shared by every accessibility audit in the UI tests.
enum AuditRules {
    /// Frames read before the audit starts, because the audit can remove elements from the tree while it runs.
    struct Bars {
        let window: CGRect
        let frames: [CGRect]

        /// The window, the largest navigation bar and the largest thread bottom bar.
        init(_ app: XCUIApplication) {
            window = app.windows.firstMatch.frame
            let navigationBar = app.navigationBars.allElementsBoundByIndex
                .map { $0.frame }.max { $0.height < $1.height }
            let bottomBar = app.otherElements.matching(identifier: "thread.bar").allElementsBoundByIndex
                .map { $0.frame }.max { $0.height < $1.height }
            frames = [navigationBar, bottomBar].compactMap { $0 }
        }
    }

    /// A contrast finding about an element that is entirely outside the screen, such as a row of the closed drawer.
    /// An element entirely outside the screen shows no pixels, so it has no contrast to measure.
    static func isContrastOfAnElementOffScreen(_ issue: XCUIAccessibilityAuditIssue, bars: Bars) -> Bool {
        guard issue.auditType == .contrast, let element = issue.element else { return false }
        return isOffScreen(element.frame, window: bars.window)
    }

    /// A contrast finding about text whose centre lies behind a bar, so the bar covers it. A bar's own title and
    /// buttons lie entirely inside the bar's frame and stay audited; text that spills out of the bar is scroll content.
    static func isContrastOfTextBehindABar(_ issue: XCUIAccessibilityAuditIssue, bars: Bars) -> Bool {
        guard issue.auditType == .contrast, let element = issue.element,
            [.staticText, .other].contains(element.elementType)
        else { return false }
        let frame = element.frame
        return bars.frames.contains { isBehind(frame, bar: $0) && !$0.contains(frame) }
    }

    /// True when the frame has an area and its centre lies inside the bar's frame.
    static func isBehind(_ frame: CGRect, bar: CGRect) -> Bool {
        !frame.isEmpty && bar.contains(CGPoint(x: frame.midX, y: frame.midY))
    }

    /// True when the frame has an area and does not intersect the window at all; an empty frame is not off screen.
    static func isOffScreen(_ frame: CGRect, window: CGRect) -> Bool {
        !frame.isEmpty && !frame.intersects(window)
    }
}
