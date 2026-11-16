import XCTest

/// Rules shared by every accessibility audit in the UI tests.
enum AuditRules {
    /// A bar's frame with the frames of the bar's own elements, which are not scroll content.
    struct Bar {
        let frame: CGRect
        let ownFrames: [CGRect]

        init(_ bar: XCUIElement) {
            frame = bar.frame
            ownFrames = bar.descendants(matching: .any).allElementsBoundByIndex.prefix(100).map { $0.frame }
        }
    }

    /// Frames read before the audit starts, because the audit can remove elements from the tree while it runs.
    struct Bars {
        let window: CGRect
        let bars: [Bar]

        /// The window, the largest navigation bar and the largest thread bottom bar.
        init(_ app: XCUIApplication) {
            window = app.windows.firstMatch.frame
            let navigationBar = app.navigationBars.allElementsBoundByIndex.max { $0.frame.height < $1.frame.height }
            let bottomBar = app.otherElements.matching(identifier: "thread.bar").allElementsBoundByIndex
                .max { $0.frame.height < $1.frame.height }
            bars = [navigationBar, bottomBar].compactMap { $0 }.map(Bar.init)
        }
    }

    /// A contrast finding about an element that is entirely outside the screen, such as a row of the closed drawer.
    /// An element entirely outside the screen shows no pixels, so it has no contrast to measure.
    static func isContrastOfAnElementOffScreen(_ issue: XCUIAccessibilityAuditIssue, bars: Bars) -> Bool {
        guard issue.auditType == .contrast, let element = issue.element else { return false }
        return isOffScreen(element.frame, window: bars.window)
    }

    /// A contrast finding about text whose centre lies behind a bar, so the bar covers it. A bar's own title and
    /// buttons are descendants of the bar and stay audited; scroll content is not a descendant of a bar.
    static func isContrastOfTextBehindABar(_ issue: XCUIAccessibilityAuditIssue, bars: Bars) -> Bool {
        guard issue.auditType == .contrast, let element = issue.element,
            [.staticText, .other].contains(element.elementType)
        else { return false }
        let frame = element.frame
        return bars.bars.contains { isBehind(frame, bar: $0.frame) && !isOwn(frame, ownFrames: $0.ownFrames) }
    }

    /// True when the frame equals one of the bar's own elements' frames, within a point.
    static func isOwn(_ frame: CGRect, ownFrames: [CGRect]) -> Bool {
        ownFrames.contains {
            abs($0.minX - frame.minX) <= 1 && abs($0.minY - frame.minY) <= 1
                && abs($0.width - frame.width) <= 1 && abs($0.height - frame.height) <= 1
        }
    }

    /// True when the frame has an area and its centre lies inside the bar's frame.
    static func isBehind(_ frame: CGRect, bar: CGRect) -> Bool {
        !frame.isEmpty && bar.contains(CGPoint(x: frame.midX, y: frame.midY))
    }

    /// True when the frame has an area and does not intersect the window at all; an empty frame is not off screen.
    static func isOffScreen(_ frame: CGRect, window: CGRect) -> Bool {
        !frame.isEmpty && !frame.intersects(window)
    }

    /// The frames of the elements inside the web view's own subtree, read before the audit starts. Cards and the
    /// signing-in overlay sit over the web view but are not part of its subtree, so they stay audited.
    @MainActor struct Page {
        let frames: [CGRect]

        /// Reads one snapshot of the subtree, because a page that is still loading changes under per-element reads.
        init(_ app: XCUIApplication) {
            var found: [CGRect] = []
            func collect(_ snapshot: any XCUIElementSnapshot) {
                for child in snapshot.children {
                    found.append(child.frame)
                    collect(child)
                }
            }
            if app.webViews.firstMatch.exists, let snapshot = try? app.webViews.firstMatch.snapshot() {
                collect(snapshot)
            }
            frames = found
        }
    }

    /// A finding about an element of the page's own content, which is the page's, not ours to restyle.
    static func isInsidePage(_ issue: XCUIAccessibilityAuditIssue, page: Page) -> Bool {
        guard let element = issue.element, !element.frame.isEmpty else { return false }
        return isOwn(element.frame, ownFrames: page.frames)
    }
}
