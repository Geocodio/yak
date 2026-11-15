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
        // The scripted turn must outlast the typing.
        try stub.setPlaybackMilliseconds(3000)
        defer { try? stub.setPlaybackMilliseconds(400) }
        // The scripted turn must outlast the typing.
        try stub.setPlaybackMilliseconds(3000)
        defer { try? stub.setPlaybackMilliseconds(400) }
        openDrawerIfNeeded()
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: 15))
        // The scripted turn runs for a few seconds and the stub's refresh after a delivery ends it, so the long press
        // goes first and the texts are short.
        field.tap()
        field.typeText("Docs too")
        let sendAfter = app.buttons["composer.send"]
        XCTAssertTrue(sendAfter.waitForExistence(timeout: 5))
        XCTAssertTrue(app.buttons["composer.stop"].exists, "the scripted turn ended before the long press")
        sendAfter.press(forDuration: 1.0)
        let afterTurn = app.descendants(matching: .any).matching(identifier: "composer.sendAfterTurn").firstMatch
        XCTAssertTrue(afterTurn.waitForExistence(timeout: 5), app.debugDescription)
        // The menu opens with an animation; its item has no frame until it settles.
        wait(
            for: [XCTNSPredicateExpectation(predicate: NSPredicate(format: "isHittable == true"), object: afterTurn)],
            timeout: 5)
        afterTurn.tap()
        waitForLastMessage(containing: "after_turn")
        XCTAssertTrue(stub.lastMessage().contains("Docs too"))
        XCTAssertNotEqual(field.value as? String, "Docs too")
        field.tap()
        field.typeText("Add tests")
        let send = app.buttons["composer.send"]
        XCTAssertTrue(send.waitForExistence(timeout: 5))
        send.tap()
        waitForLastMessage(containing: "steer")
        XCTAssertTrue(stub.lastMessage().contains("Add tests"))
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
        app.buttons["composer.send"].tap()
        XCTAssertFalse(stub.lastMessage().contains("Also update the changelog"))
        try stub.setOnline(true)
        waitForLastMessage(containing: "Also update the changelog", timeout: 20)
    }

    private func waitForLastMessage(containing text: String, timeout: TimeInterval = 10) {
        let deadline = Date().addingTimeInterval(timeout)
        while Date() < deadline, !stub.lastMessage().contains(text) { Thread.sleep(forTimeInterval: 0.3) }
        XCTAssertTrue(stub.lastMessage().contains(text), "the stub never took a message with \(text)")
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
