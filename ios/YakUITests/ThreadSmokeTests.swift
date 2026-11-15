import XCTest

/// Smoke tests of the thread screens against the stub server. `launch(scenario:)` plays a scenario and launches
/// paired; the helpers are shared by the later tests of this file.
@MainActor final class ThreadSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        // A landscape walkthrough can leave the simulator turned.
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launch()
    }

    private func openDrawerIfNeeded() {
        if app.otherElements["drawer"].exists { return }
        let threads = app.buttons["threads.button"]
        if threads.waitForExistence(timeout: 5) { threads.tap() }
    }

    func testColdStartWithOneThreadThatNeedsYouOpensIt() throws {
        try launch(scenario: "question-open")
        XCTAssertTrue(app.staticTexts["Retry failed rows when the import times out"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.otherElements["conversation"].waitForExistence(timeout: 10))
        try audit()
    }

    func testDrawerNestsSubThreadsAndOpensAThread() throws {
        try launch(scenario: "paired")
        openDrawerIfNeeded()
        XCTAssertTrue(app.descendants(matching: .any)["drawer.row.thread:d0e515ef"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.descendants(matching: .any)["drawer.row.thread:a1b2c3d4"].exists)
        // The header shows the stored instance, which `me` renamed after the launch.
        let switcher = app.buttons["drawer.switcher"]
        let renamed = NSPredicate(format: "label == %@", "Switch instance. Now: Example Yak")
        wait(for: [XCTNSPredicateExpectation(predicate: renamed, object: switcher)], timeout: 10)
        XCTAssertFalse(app.descendants(matching: .any)["drawer.row.thread:e5f6g7h8"].exists)
        app.buttons["drawer.toggle.thread:a1b2c3d4"].tap()
        let child = app.descendants(matching: .any)["drawer.row.thread:e5f6g7h8"]
        XCTAssertTrue(child.waitForExistence(timeout: 5))
        try audit()
        child.tap()
        XCTAssertTrue(app.staticTexts["Split the billing job"].waitForExistence(timeout: 10))
        try audit()
    }

    private func audit() throws {
        try app.performAccessibilityAudit { [self] issue in
            // A bare age ("5h") is a few points wide; on a wide screen the audit measures it against the sidebar's
            // edge instead of its own background, though it is the primary label color on a plain surface.
            if issue.auditType == .contrast, let label = issue.element?.label,
                label.wholeMatch(of: /\d+[mhd]/) != nil
            {
                return true
            }
            // A search field is one line by design.
            if issue.auditType == .textClipped, issue.element?.elementType == .textField { return true }
            let screenshot = XCTAttachment(screenshot: app.screenshot())
            screenshot.lifetime = .keepAlways
            screenshot.name = "audit-failure"
            add(screenshot)
            XCTFail("audit: \(issue.compactDescription) | \(issue.detailedDescription)")
            return true
        }
    }
}
