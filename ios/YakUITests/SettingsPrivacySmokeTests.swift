import XCTest

/// App lock, Spotlight and the other privacy rows against the stub. The lock's answers come from the launch
/// arguments, so no test drives the system's authentication sheet.
@MainActor final class SettingsPrivacySmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
    }

    private func launch(_ extra: [String]) throws {
        try stub.play("paired")
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"] + extra
        app.launch()
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func openSettings() {
        let account = app.buttons["drawer.account"]
        if !account.waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(account.waitForExistence(timeout: 10))
        account.tap()
        XCTAssertTrue(element("settings.screen").waitForExistence(timeout: 30))
    }

    private func audit() throws {
        var findings: [String] = []
        let bars = AuditRules.Bars(app)
        try app.performAccessibilityAudit { issue in
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: bars) { return true }
            if AuditRules.isContrastOfTextBehindABar(issue, bars: bars) { return true }
            findings.append(
                "\(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "no element")"
            )
            return true
        }
        if !findings.isEmpty {
            let screenshot = XCTAttachment(screenshot: app.screenshot())
            screenshot.lifetime = .keepAlways
            screenshot.name = "audit-failure"
            add(screenshot)
            XCTFail("audit: " + findings.joined(separator: "\n"))
        }
    }

    func testAppLockAsksOnLaunchAndCoversSheets() throws {
        try launch([
            "-yakTestAppLock", "on", "-yakTestBiometry", "faceID",
            "-yakTestAuthentication", "cancel,succeed,cancel,succeed", "-yakTestAppLockLimit", "0",
        ])
        XCTAssertTrue(element("lock.screen").waitForExistence(timeout: 30))
        let unlock = app.buttons["lock.unlock"]
        let idle = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "label == 'Unlock with Face ID'"), object: unlock)
        _ = XCTWaiter().wait(for: [idle], timeout: 10)
        XCTAssertEqual(unlock.label, "Unlock with Face ID", app.debugDescription)
        try audit()
        app.buttons["lock.unlock"].tap()
        openSettings()
        XCUIDevice.shared.press(.home)
        app.activate()
        XCTAssertTrue(element("lock.screen").waitForExistence(timeout: 30))
        XCTAssertFalse(element("settings.screen").isHittable)
        app.buttons["lock.unlock"].tap()
        XCTAssertTrue(element("settings.screen").waitForExistence(timeout: 30))
        XCTAssertTrue(element("settings.screen").isHittable)
    }

    func testUnderFiveMinutesStaysOpen() throws {
        try launch(["-yakTestAppLock", "on", "-yakTestAuthentication", "cancel,succeed"])
        XCTAssertTrue(element("lock.screen").waitForExistence(timeout: 30))
        app.buttons["lock.unlock"].tap()
        openSettings()
        XCUIDevice.shared.press(.home)
        app.activate()
        XCTAssertTrue(element("settings.screen").waitForExistence(timeout: 30))
        XCTAssertFalse(element("lock.screen").waitForExistence(timeout: 2))
        XCTAssertTrue(element("settings.screen").isHittable)
    }

    /// Scrolls Settings until the row shows.
    private func reveal(_ identifier: String) {
        let row = element(identifier)
        for _ in 0..<8 where !row.exists || !row.isHittable { app.swipeUp() }
        XCTAssertTrue(row.isHittable, identifier)
    }

    /// Taps the switch itself, at the right edge of its row, not the label in the middle.
    private func flip(_ toggle: XCUIElement) {
        toggle.coordinate(withNormalizedOffset: CGVector(dx: 0.93, dy: 0.5)).tap()
    }

    func testTurningOnAppLockNeedsTheOwner() throws {
        try launch(["-yakTestBiometry", "touchID", "-yakTestAuthentication", "cancel,succeed"])
        openSettings()
        try audit()
        reveal("settings.appLock")
        let toggle = app.switches["settings.appLock"]
        XCTAssertTrue(toggle.label.contains("Require Touch ID"), toggle.label)
        flip(toggle)
        // The first answer is a cancel: the switch goes through "confirming" and returns to off.
        let transitioned = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "value == '1' OR enabled == false"), object: toggle)
        _ = XCTWaiter().wait(for: [transitioned], timeout: 2)
        let settled = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "value == '0' AND enabled == true"), object: toggle)
        XCTAssertEqual(XCTWaiter().wait(for: [settled], timeout: 10), .completed)
        XCTAssertEqual(toggle.value as? String, "0")
        XCTAssertTrue(toggle.isEnabled)
        XCTAssertFalse(element("settings.appLock.failed").exists)
        flip(toggle)
        let on = XCTNSPredicateExpectation(predicate: NSPredicate(format: "value == '1'"), object: toggle)
        XCTAssertEqual(XCTWaiter().wait(for: [on], timeout: 10), .completed)
        XCTAssertFalse(element("lock.screen").waitForExistence(timeout: 2))
        // Spotlight's switch shows off and disabled while the lock is on.
        let spotlight = app.switches["settings.spotlightPrivate"]
        XCTAssertFalse(spotlight.isEnabled)
    }
}
