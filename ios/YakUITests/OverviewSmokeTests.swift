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
        let plus = app.buttons["composer.plus"]
        XCTAssertTrue(plus.waitForExistence(timeout: 15))
        plus.tap()
        let addItem = app.buttons["composer.plusMenu.addSubThread"]
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
        // The keyboard covers the footer rows, so it goes away before the audit looks at them.
        app.otherElements["addSubThread.sheet"].scrollViews.firstMatch.swipeDown()
        // The audit judges what is on screen, so the sheet is pulled to its large size where its footer is not cut.
        let grabber = app.staticTexts["Add a sub-thread"].coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0))
        let top = app.windows.firstMatch.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.05))
        grabber.press(forDuration: 0.1, thenDragTo: top)
        let footer = app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", "Runs on")).firstMatch
        XCTAssertTrue(footer.waitForExistence(timeout: 15))
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
        // The page is brought up so that no text is cut by the floating bar or the top edge.
        let window = app.windows.firstMatch
        window.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.7))
            .press(forDuration: 0.1, thenDragTo: window.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.45)))
        try audit()
    }

    func testLongPressOnAFailedSubThreadOffersItsActions() throws {
        try launch(scenario: "overview-stack")
        if !app.otherElements["drawer"].exists {
            let threads = app.buttons["threads.button"]
            if threads.waitForExistence(timeout: 10) { threads.tap() }
        }
        let failed = app.descendants(matching: .any)["drawer.row.thread:q7adapt0"]
        if !failed.waitForExistence(timeout: 10) {
            let toggle = app.buttons["drawer.toggle.thread:s7ackr00"]
            XCTAssertTrue(toggle.waitForExistence(timeout: 10))
            toggle.tap()
        }
        XCTAssertTrue(failed.waitForExistence(timeout: 10))
        // The menu reads the stack the drawer fetched when the parent opened, so the press repeats until it has the
        // actions.
        let drop = app.buttons["Drop it"]
        for _ in 0..<8 where !drop.exists {
            failed.press(forDuration: 1.0)
            if !drop.waitForExistence(timeout: 2) { app.tap() }
        }
        XCTAssertTrue(drop.exists)
        let takeOver = app.descendants(matching: .any).matching(NSPredicate(format: "label CONTAINS %@", "Take over"))
        XCTAssertTrue(takeOver.firstMatch.waitForExistence(timeout: 5), app.debugDescription)
        XCTAssertTrue(app.buttons["Split it up"].exists)
        app.tap()
        let working = app.descendants(matching: .any)["drawer.row.thread:b4ckf111"]
        XCTAssertTrue(working.waitForExistence(timeout: 5))
        working.press(forDuration: 1.0)
        XCTAssertTrue(app.buttons["Open"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["Drop it"].exists)
        app.tap()
        try audit()
    }

    func testSharingWithEveryoneUpdatesThePeopleRow() throws {
        try launch(scenario: "overview-private")
        openThread("s7ackr00")
        let title = app.descendants(matching: .any)["thread.title"]
        XCTAssertTrue(title.waitForExistence(timeout: 15))
        title.tap()
        XCTAssertTrue(app.staticTexts["Shared with 1 person"].waitForExistence(timeout: 15))
        XCTAssertTrue(app.buttons["overview.shareButton"].exists)
        let change = app.buttons["overview.share"]
        for _ in 0..<6 where !change.isHittable { app.descendants(matching: .any)["overview"].swipeUp() }
        change.tap()
        let everyone = app.buttons["share.mode.everyone"]
        XCTAssertTrue(everyone.waitForExistence(timeout: 10))
        everyone.tap()
        app.buttons["share.save"].tap()
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: everyone)], timeout: 15)
        XCTAssertTrue(app.staticTexts["Shared with the team"].waitForExistence(timeout: 10))
        // The audit judges what is on screen, so the page goes back to the top where no row is cut by the bar.
        for _ in 0..<4 { app.descendants(matching: .any)["overview"].swipeDown() }
        try audit()
    }

    private func audit() throws {
        let auditBars = AuditRules.Bars(app)
        try app.performAccessibilityAudit { [self, auditBars] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                // The system caps the size of text in its bars.
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
            }
            // An element entirely outside the screen shows no pixels to measure.
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            // Text behind a bar is covered by the bar.
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
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
