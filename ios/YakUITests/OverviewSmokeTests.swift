import XCTest

/// Smoke tests of the Overview against the stub server's `overview-*` scenarios.
@MainActor final class OverviewSmokeTests: XCTestCase {
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

    private func openThread(_ id: String) {
        if !app.otherElements["drawer"].exists {
            let threads = app.buttons["threads.button"]
            if threads.waitForExistence(timeout: 10) { threads.tap() }
        }
        let row = app.descendants(matching: .any)["drawer.row.thread:\(id)"]
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
    }

    func testOverviewOpensFromTheTitleAndShowsTheGroups() throws {
        try launch(scenario: "overview-stack")
        openThread("s7ackr00")
        let title = app.descendants(matching: .any)["thread.title"]
        XCTAssertTrue(title.waitForExistence(timeout: 15))
        title.tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview"].waitForExistence(timeout: 10))
        let kinds = ["subThreads", "status", "links", "access", "people"]
        let groups = kinds.map { app.descendants(matching: .any)["overview.group.\($0)"] }
        for group in groups { XCTAssertTrue(group.waitForExistence(timeout: 15), group.identifier) }
        let tops = groups.map(\.frame.minY)
        XCTAssertEqual(tops, tops.sorted())
        XCTAssertTrue(app.staticTexts["Sandbox running"].exists)
        XCTAssertTrue(app.staticTexts["2 of 4 slots in use"].exists)
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
            if issue.auditType == .contrast, issue.element == nil { return true }
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
