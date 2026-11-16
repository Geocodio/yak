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

    /// Smoke test 3, the frame: the thread opens from the drawer on the server's own fixtures, and its conversation
    /// area ends up with neither a load state nor an error.
    func testServerFixturesOpen() throws {
        try launch(scenario: "server-fixtures")
        openDrawerIfNeeded()
        let rows = app.descendants(matching: .any).matching(
            NSPredicate(format: "identifier BEGINSWITH %@", "drawer.row.thread:"))
        XCTAssertTrue(rows.firstMatch.waitForExistence(timeout: 15))
        for text in ["This thread is not here anymore", "You are offline"] {
            XCTAssertFalse(app.staticTexts[text].exists, text)
        }
        rows.firstMatch.tap()
        XCTAssertTrue(app.otherElements["conversation"].waitForExistence(timeout: 15))
        let gone = NSPredicate(format: "exists == false")
        for identifier in ["thread.loading", "thread.slow", "thread.notFound", "thread.offline", "thread.error"] {
            let element = app.descendants(matching: .any)[identifier]
            wait(for: [XCTNSPredicateExpectation(predicate: gone, object: element)], timeout: 15)
            XCTAssertFalse(element.exists, identifier)
        }
        for text in ["This thread is not here anymore", "This is taking longer than usual", "Loading the conversation"]
        {
            XCTAssertFalse(app.staticTexts[text].exists, text)
        }
        try audit()
    }

    private func audit() throws {
        try app.performAccessibilityAudit { [self] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                // The system caps the size of text in its bars, and draws its glass under the threads badge.
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            // On a wide screen the audit reports contrast on the thread screen without naming an element.
            if issue.auditType == .contrast, issue.element == nil { return true }
            // An element entirely outside the screen shows no pixels to measure.
            if AuditRules.isContrastOfAnElementOffScreen(issue, in: app) { return true }
            // A search field is one line by design.
            if issue.auditType == .textClipped, issue.element?.elementType == .textField { return true }
            let screenshot = XCTAttachment(screenshot: app.screenshot())
            screenshot.lifetime = .keepAlways
            screenshot.name = "audit-failure"
            add(screenshot)
            XCTFail(
                "audit: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "none")"
            )
            return true
        }
    }
}
