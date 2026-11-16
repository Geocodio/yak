import XCTest

/// Smoke tests of the Tasks list, the task screen and the Observations list against the stub server.
@MainActor final class ChangesSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launch()
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func openDrawerIfNeeded() {
        if app.otherElements["drawer"].waitForExistence(timeout: 10) { return }
        app.buttons["threads.button"].tap()
    }

    /// A task row of the drawer opens the native task screen, not a web page, and says it is a task.
    func testADrawerTaskRowOpensTheNativeTaskScreen() throws {
        try launch(scenario: "changes")
        openDrawerIfNeeded()
        let row = element("drawer.row.task:k3m9p2qa")
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        XCTAssertTrue(element("drawer.taskLabel").exists)
        row.tap()
        XCTAssertTrue(element("task.decision.approve").waitForExistence(timeout: 15))
        XCTAssertFalse(app.webViews.firstMatch.exists && app.otherElements["SFSafariView"].exists)
    }

    func testApprovingATaskShowsTheDecision() throws {
        try launch(scenario: "changes")
        openDrawerIfNeeded()
        app.buttons["drawer.tile.tasks"].tap()
        let row = element("tasks.row.k3m9p2qa")
        XCTAssertTrue(row.waitForExistence(timeout: 15))
        row.tap()
        let approve = element("task.decision.approve")
        XCTAssertTrue(approve.waitForExistence(timeout: 15))
        approve.tap()
        XCTAssertTrue(app.staticTexts["Approved by Test person"].waitForExistence(timeout: 10))
        try audit()
    }

    func testObservationsListShowsTheOutcomes() throws {
        try launch(scenario: "changes")
        openDrawerIfNeeded()
        app.buttons["drawer.tile.observations"].tap()
        XCTAssertTrue(element("observations.row.302").waitForExistence(timeout: 15))
        try audit()
    }

    private func audit() throws {
        try app.performAccessibilityAudit { [self] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
            }
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
