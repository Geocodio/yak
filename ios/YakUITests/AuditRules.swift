import XCTest

/// Rules shared by every accessibility audit in the UI tests.
enum AuditRules {
    /// A bar's frame with the frames of the bar's own elements, which are not scroll content.
    @MainActor struct Bar {
        let frame: CGRect
        let ownFrames: [CGRect]

        init(_ bar: any XCUIElementSnapshot) {
            frame = bar.frame
            ownFrames = AuditRules.descendants(of: bar).map { $0.frame }
        }
    }

    /// Frames read before the audit starts, because the audit can remove elements from the tree while it runs.
    @MainActor struct Bars {
        let window: CGRect
        let bars: [Bar]

        /// The window, the largest navigation bar and the largest thread bottom bar, from one snapshot of the tree, so
        /// a screen that changes while they are read cannot fail the test. Without a snapshot the window is empty, and
        /// no off-screen or behind-a-bar rule applies.
        init(_ app: XCUIApplication) {
            let all = (try? app.snapshot()).map(AuditRules.descendants) ?? []
            window = all.first { $0.elementType == .window }?.frame ?? .zero
            let navigationBar = all.filter { $0.elementType == .navigationBar }.max {
                $0.frame.height < $1.frame.height
            }
            let bottomBar = all.filter { $0.elementType == .other && $0.identifier == "thread.bar" }
                .max { $0.frame.height < $1.frame.height }
            bars = [navigationBar, bottomBar].compactMap { $0 }.map(Bar.init)
        }
    }

    /// Every element under the snapshot, depth first.
    @MainActor static func descendants(of snapshot: any XCUIElementSnapshot) -> [any XCUIElementSnapshot] {
        snapshot.children.flatMap { [$0] + descendants(of: $0) }
    }

    /// Waits up to `timeout` until two snapshots of the screen half a second apart are the same, so an audit does not
    /// measure a frame in the middle of a transition. Returns after the timeout even when the screen keeps changing.
    @MainActor static func waitUntilStill(_ app: XCUIApplication, timeout: TimeInterval = 15) {
        func signature() -> [String]? {
            (try? app.snapshot()).map { root in
                descendants(of: root).map {
                    "\($0.elementType.rawValue) \($0.frame) \($0.label) \(String(describing: $0.value))"
                }
            }
        }
        let deadline = Date().addingTimeInterval(timeout)
        var last = signature()
        while Date() < deadline {
            RunLoop.current.run(until: Date().addingTimeInterval(0.5))
            let next = signature()
            if let next, next == last { return }
            last = next
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

    /// A contrast finding about an element the keyboard covers; what is behind the keyboard cannot be judged. The
    /// keyboard frame is read before the audit starts, and is nil when no keyboard is up.
    static func isContrastBehindTheKeyboard(_ issue: XCUIAccessibilityAuditIssue, keyboard: CGRect?) -> Bool {
        guard issue.auditType == .contrast, let element = issue.element, let keyboard else { return false }
        return keyboard.intersects(element.frame)
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

    /// True when the frame has an area and does not intersect the window at all; an empty frame is not off screen, and
    /// nothing is off an unknown (empty) window.
    static func isOffScreen(_ frame: CGRect, window: CGRect) -> Bool {
        !frame.isEmpty && !window.isEmpty && !frame.intersects(window)
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

extension XCTestCase {
    /// The accessibility audit of a smoke test. It waits until the screen is still, then audits; `excuses` is called
    /// before each audit (so it reads frames from the screen as it is then) and returns the test's rule, true for an
    /// excused issue. When the first audit has findings or throws (it did not finish in time, or the tree changed
    /// under it), the screen settles and a second audit runs, and the test fails with what the second audit reports:
    /// a real defect is still there the second time, and a frame caught in the middle of a transition is not.
    @MainActor func auditTwice(
        _ app: XCUIApplication, for types: XCUIAccessibilityAuditType = .all,
        excuses: () -> (XCUIAccessibilityAuditIssue) -> Bool
    ) throws {
        for attempt in 1...2 {
            AuditRules.waitUntilStill(app)
            let isExcused = excuses()
            var findings: [String] = []
            do {
                try app.performAccessibilityAudit(for: types) { issue in
                    if isExcused(issue) { return true }
                    findings.append(
                        "\(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "no element")"
                    )
                    return true
                }
            } catch {
                if attempt == 2 { throw error }
                print("audit: the first audit threw (\(error.localizedDescription)); auditing once more")
                continue
            }
            if findings.isEmpty { return }
            if attempt == 1 {
                print("audit: the first audit found \(findings.count); auditing once more after the screen settles")
                continue
            }
            let screenshot = XCTAttachment(screenshot: app.screenshot())
            screenshot.lifetime = .keepAlways
            screenshot.name = "audit-failure"
            add(screenshot)
            XCTFail("audit (checked twice): \(findings.joined(separator: "\n"))")
        }
    }
}
