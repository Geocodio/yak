import XCTest

final class AuditRulesSmokeTests: XCTestCase {
    func testOffScreenGeometry() {
        let window = CGRect(x: 0, y: 0, width: 400, height: 800)
        XCTAssertFalse(AuditRules.isOffScreen(CGRect(x: 10, y: 10, width: 50, height: 20), window: window))
        XCTAssertFalse(AuditRules.isOffScreen(CGRect(x: -20, y: 10, width: 50, height: 20), window: window))
        XCTAssertTrue(AuditRules.isOffScreen(CGRect(x: -322, y: 10, width: 300, height: 20), window: window))
        XCTAssertTrue(AuditRules.isOffScreen(CGRect(x: 10, y: 900, width: 50, height: 20), window: window))
        XCTAssertFalse(AuditRules.isOffScreen(.zero, window: window))
        XCTAssertFalse(AuditRules.isOffScreen(CGRect(x: -500, y: 10, width: 0, height: 0), window: window))
    }

    func testBehindABarGeometry() {
        let bar = CGRect(x: 0, y: 0, width: 400, height: 100)
        XCTAssertTrue(AuditRules.isBehind(CGRect(x: 10, y: 70, width: 50, height: 40), bar: bar))
        XCTAssertFalse(AuditRules.isBehind(CGRect(x: 10, y: 90, width: 50, height: 40), bar: bar))
        XCTAssertFalse(AuditRules.isBehind(CGRect(x: 10, y: 50, width: 0, height: 0), bar: bar))
        XCTAssertFalse(AuditRules.isBehind(CGRect(x: 10, y: 50, width: 50, height: 20), bar: .zero))
    }

    func testARowFullyBehindABarIsToldFromTheBarsOwnElement() {
        let bar = CGRect(x: 0, y: 0, width: 400, height: 100)
        let title = CGRect(x: 100, y: 50, width: 200, height: 30)
        let row = CGRect(x: 10, y: 20, width: 380, height: 40)
        XCTAssertTrue(bar.contains(row) && AuditRules.isBehind(row, bar: bar))
        XCTAssertFalse(AuditRules.isOwn(row, ownFrames: [title]))
        XCTAssertTrue(AuditRules.isOwn(title.offsetBy(dx: 0.5, dy: -1), ownFrames: [title]))
        XCTAssertFalse(AuditRules.isOwn(title.offsetBy(dx: 0, dy: 2), ownFrames: [title]))
    }

    func testOnlyAContrastFindingWithoutAnElementIsExcusedWhileTheCompactDrawerIsClosed() {
        let window = CGRect(x: 0, y: 0, width: 402, height: 874)
        let closed = CGRect(x: -330, y: 0, width: 330, height: 874)
        func isExcused(
            _ type: XCUIAccessibilityAuditType = .contrast, hasElement: Bool = false,
            layout: AuditRules.Layout = .compact, drawer: CGRect? = closed
        ) -> Bool {
            AuditRules.isContrastWithoutAnElementInTheClosedDrawer(
                type: type, hasElement: hasElement, layout: layout, drawer: drawer, window: window)
        }
        XCTAssertTrue(isExcused())
        XCTAssertFalse(isExcused(hasElement: true), "a finding that names an element is judged")
        XCTAssertFalse(isExcused(drawer: CGRect(x: 0, y: 0, width: 330, height: 874)), "an open drawer is judged")
        XCTAssertFalse(
            isExcused(drawer: CGRect(x: -200, y: 0, width: 330, height: 874)), "a drawer mid-slide is judged")
        XCTAssertFalse(isExcused(drawer: nil), "without a drawer the rule does not apply")
        XCTAssertFalse(isExcused(.dynamicType), "another audit type is judged")
        XCTAssertFalse(isExcused(layout: .sidebar), "a sidebar is not judged by this rule")
    }
}
