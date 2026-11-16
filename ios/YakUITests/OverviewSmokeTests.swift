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

    func testStopAllAsksFirstAndHidesItself() throws {
        try launch(scenario: "overview-stack")
        openThread("s7ackr00")
        let title = app.descendants(matching: .any)["thread.title"]
        XCTAssertTrue(title.waitForExistence(timeout: 15))
        title.tap()
        let stop = app.buttons["overview.stopAll"]
        XCTAssertTrue(stop.waitForExistence(timeout: 15))
        for _ in 0..<6 where !stop.isHittable { app.descendants(matching: .any)["overview"].swipeUp() }
        stop.tap()
        XCTAssertTrue(app.staticTexts["Stop all sub-threads?"].waitForExistence(timeout: 5))
        app.buttons["Stop all"].tap()
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: stop)], timeout: 10)
        XCTAssertTrue(
            app.staticTexts["This stack was stopped, so it cannot start sub-threads."].waitForExistence(timeout: 10))
        XCTAssertTrue(try stub.lastOverviewRequest().hasPrefix("POST threads/s7ackr00/stack/stop"))
        try audit()
    }

    func testAddingASubThreadSendsOnceAndShowsIt() throws {
        try launch(scenario: "overview-stack")
        openThread("s7ackr00")
        let menu = app.buttons["thread.menu"]
        XCTAssertTrue(menu.waitForExistence(timeout: 15))
        menu.tap()
        let addItem = app.buttons["thread.menu.addSubThread"]
        XCTAssertTrue(addItem.waitForExistence(timeout: 5))
        addItem.tap()
        let name = app.textFields["addSubThread.name"]
        XCTAssertTrue(name.waitForExistence(timeout: 10))
        name.tap()
        name.typeText("check-the-cache")
        let brief = app.textFields["addSubThread.brief"]
        brief.tap()
        brief.typeText("Look at the cache keys.")
        app.segmentedControls["addSubThread.kind"].buttons["Research"].tap()
        try audit()
        app.buttons["addSubThread.add"].tap()
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: name)], timeout: 15)
        let request = try stub.lastOverviewRequest()
        XCTAssertTrue(request.contains("\"kind\":\"research\""), request)
        XCTAssertTrue(request.contains("send_key"), request)
        let title = app.descendants(matching: .any)["thread.title"]
        XCTAssertTrue(title.waitForExistence(timeout: 10))
        title.tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview.child.n3wch11d"].waitForExistence(timeout: 15))
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
