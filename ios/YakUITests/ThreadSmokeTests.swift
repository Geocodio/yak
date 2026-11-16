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

    /// Smoke test 5, the composer's half: a send while a turn runs steers, and a long press on Send queues. The
    /// "Sends after this turn" line under the bubble belongs to the chat.
    func testSendWhileATurnRunsSteersAndLongPressQueues() throws {
        try XCTSkipIf(UIDevice.current.userInterfaceIdiom != .phone, "iPhone only")
        try launch(scenario: "one-thread-working")
        // The turn runs from the third scripted event to the last. Playback starts fast so the turn is running, then
        // holds once the app shows it, so a slow machine cannot end the turn during the test. The next launch's
        // scenario cancels the held playback.
        try stub.setPlaybackMilliseconds(1000)
        defer { try? stub.setPlaybackMilliseconds(400) }
        openDrawerIfNeeded()
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
        XCTAssertTrue(app.buttons["composer.stop"].waitForExistence(timeout: 30), "the scripted turn never started")
        try stub.setPlaybackMilliseconds(600_000)
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: 15))
        field.tap()
        field.typeText("Also cover the empty file case")
        let send = app.buttons["composer.send"]
        XCTAssertTrue(send.waitForExistence(timeout: 5))
        XCTAssertTrue(app.buttons["composer.stop"].exists, "the scripted turn ended before the send")
        send.tap()
        waitForLastMessage(containing: "steer")
        XCTAssertTrue(stub.lastMessage().contains("Also cover the empty file case"))
        field.tap()
        field.typeText("Then update the docs")
        XCTAssertTrue(send.waitForExistence(timeout: 5))
        XCTAssertTrue(app.buttons["composer.stop"].exists, "the scripted turn ended early, before the long press")
        send.press(forDuration: 1.0)
        let afterTurn = app.descendants(matching: .any).matching(identifier: "composer.sendAfterTurn").firstMatch
        XCTAssertTrue(afterTurn.waitForExistence(timeout: 15), app.debugDescription)
        // The menu opens with an animation; its item has no frame until it settles.
        wait(
            for: [XCTNSPredicateExpectation(predicate: NSPredicate(format: "isHittable == true"), object: afterTurn)],
            timeout: 15)
        afterTurn.tap()
        waitForLastMessage(containing: "after_turn")
        XCTAssertTrue(stub.lastMessage().contains("Then update the docs"))
    }

    /// Smoke test 6, the composer's half: a send while offline is kept and goes out when the connection returns.
    /// The "Not sent" line under the bubble belongs to the chat.
    func testADroppedConnectionShowsOfflineAndRecovers() throws {
        try XCTSkipIf(UIDevice.current.userInterfaceIdiom != .phone, "iPhone only")
        try launch(scenario: "paired")
        openDrawerIfNeeded()
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: 15))
        try stub.setOnline(false)
        field.tap()
        field.typeText("Also update the changelog")
        let offline = app.staticTexts["Offline. Showing a saved copy"]
        XCTAssertTrue(offline.waitForExistence(timeout: 30), app.debugDescription)
        app.buttons["composer.send"].tap()
        XCTAssertFalse(stub.lastMessage().contains("Also update the changelog"))
        try stub.setOnline(true)
        waitForLastMessage(containing: "Also update the changelog", timeout: 20)
        let gone = XCTNSPredicateExpectation(predicate: NSPredicate(format: "exists == false"), object: offline)
        XCTAssertEqual(XCTWaiter().wait(for: [gone], timeout: 20), .completed)
    }

    private func waitForLastMessage(containing text: String, timeout: TimeInterval = 10) {
        let arrived = XCTNSPredicateExpectation(
            predicate: NSPredicate { [stub] _, _ in stub.lastMessage().contains(text) }, object: nil)
        XCTAssertEqual(
            XCTWaiter().wait(for: [arrived], timeout: timeout), .completed,
            "the stub never took a message with \(text)")
    }

    private func audit() throws {
        let auditBars = AuditRules.Bars(app)
        try app.performAccessibilityAudit { [self, auditBars] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                // The system caps the size of text in its bars, and draws its glass under the threads badge.
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            // On a wide screen the audit reports contrast on the thread screen without naming an element.
            if issue.auditType == .contrast, issue.element == nil { return true }
            // An element entirely outside the screen shows no pixels to measure.
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            // Text behind a bar is covered by the bar.
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
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
