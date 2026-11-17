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
        try app.performAccessibilityAudit { issue in
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
}
