import XCTest

/// Rules shared by every accessibility audit in the UI tests.
enum AuditRules {
    /// A contrast finding about an element that is entirely outside the screen, such as a row of the closed drawer.
    /// An element that is entirely outside the screen shows no pixels, so it has no contrast to measure.
    static func isContrastOfAnElementOffScreen(_ issue: XCUIAccessibilityAuditIssue, in app: XCUIApplication) -> Bool {
        guard issue.auditType == .contrast, let element = issue.element else { return false }
        return isOffScreen(element.frame, window: app.windows.firstMatch.frame)
    }

    /// True when the frame has an area and does not intersect the window at all; an empty frame is not off screen.
    static func isOffScreen(_ frame: CGRect, window: CGRect) -> Bool {
        !frame.isEmpty && !frame.intersects(window)
    }
}
