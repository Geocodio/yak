import XCTest

extension XCTestCase {
    /// The accessibility audit of a thread screen, shared by the thread smoke tests: which findings belong to the
    /// system's bars or to a screen that shows no pixels, and nothing else is excused.
    @MainActor func auditThreadScreen(_ app: XCUIApplication) throws {
        try auditTwice(app) { [app] in
            let auditBars = AuditRules.Bars(app)
            return { issue in
                let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
                if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                    // The system caps the size of text in its bars, and draws its glass under the threads badge.
                    if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                    if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
                }
                // On a wide screen the audit reports contrast on the thread screen without naming an element.
                if issue.auditType == .contrast, issue.element == nil { return true }
                // An element entirely outside the screen shows no pixels to measure.
                if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
                // Text behind a bar is covered by the bar.
                if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
                // A search field is one line by design.
                if issue.auditType == .textClipped, issue.element?.elementType == .textField { return true }
                return false
            }
        }
    }
}
