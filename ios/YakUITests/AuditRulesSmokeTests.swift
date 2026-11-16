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
}
