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

    private func launch(scenario: String, extraArguments: [String] = []) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"] + extraArguments
        app.launchRetryingOnce()
    }

    private func openDrawerIfNeeded() {
        if app.otherElements["drawer"].exists { return }
        let threads = app.buttons["threads.button"]
        if threads.waitForExistence(timeout: 5) { threads.tap() }
    }

    func testColdStartWithOneThreadThatNeedsYouOpensIt() throws {
        try launch(scenario: "question-open")
        XCTAssertTrue(
            app.staticTexts["Retry failed rows when the import times out"].waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(app.descendants(matching: .any)["conversation"].waitForExistence(timeout: Wait.appear))
        try audit()
    }

    func testDrawerNestsSubThreadsAndOpensAThread() throws {
        try launch(scenario: "paired")
        openDrawerIfNeeded()
        XCTAssertTrue(
            app.descendants(matching: .any)["drawer.row.thread:d0e515ef"].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.descendants(matching: .any)["drawer.row.thread:a1b2c3d4"].exists)
        // The header shows the stored instance, which `me` renamed after the launch.
        let switcher = app.buttons["drawer.switcher"]
        let renamed = NSPredicate(format: "label == %@", "Switch instance. Now: Example Yak")
        wait(for: [XCTNSPredicateExpectation(predicate: renamed, object: switcher)], timeout: Wait.appear)
        XCTAssertFalse(app.descendants(matching: .any)["drawer.row.thread:e5f6g7h8"].exists)
        app.buttons["drawer.toggle.thread:a1b2c3d4"].tap()
        let child = app.descendants(matching: .any)["drawer.row.thread:e5f6g7h8"]
        XCTAssertTrue(child.waitForExistence(timeout: Wait.appear))
        try audit()
        child.tap()
        XCTAssertTrue(app.staticTexts["Split the billing job"].waitForExistence(timeout: Wait.appear))
        try audit()
    }

    /// Smoke test 3, the frame: the drawer lists the server's own thread fixtures, with neither a load state nor an
    /// error. The fixtures' ids are placeholders, so the thread page itself is not opened here.
    func testServerFixturesFillTheDrawer() throws {
        try launch(scenario: "server-fixtures")
        openDrawerIfNeeded()
        let rows = app.descendants(matching: .any).matching(
            NSPredicate(format: "identifier BEGINSWITH %@", "drawer.row.thread:"))
        XCTAssertTrue(rows.firstMatch.waitForExistence(timeout: Wait.firstLoad))
        for text in ["This thread is not here anymore", "You are offline"] {
            XCTAssertFalse(app.staticTexts[text].exists, text)
        }
        try audit()
    }

    /// Smoke test 5, the composer's half: a send while a turn runs steers, and a long press on Send queues.
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
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        XCTAssertTrue(
            app.buttons["composer.stop"].waitForExistence(timeout: Wait.appear), "the scripted turn never started")
        try stub.setPlaybackMilliseconds(600_000)
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: Wait.appear))
        field.tap()
        field.typeText("Also cover the empty file case")
        let send = app.buttons["composer.send"]
        XCTAssertTrue(send.waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.buttons["composer.stop"].exists, "the scripted turn ended before the send")
        send.tap()
        waitForLastMessage(containing: "steer")
        XCTAssertTrue(stub.lastMessage().contains("Also cover the empty file case"))
        field.tap()
        field.typeText("Then update the docs")
        XCTAssertTrue(send.waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.buttons["composer.stop"].exists, "the scripted turn ended early, before the long press")
        send.press(forDuration: 1.0)
        let afterTurn = app.descendants(matching: .any).matching(identifier: "composer.sendAfterTurn").firstMatch
        XCTAssertTrue(afterTurn.waitForExistence(timeout: Wait.appear), app.debugDescription)
        // The menu opens with an animation; its item has no frame until it settles.
        wait(
            for: [XCTNSPredicateExpectation(predicate: NSPredicate(format: "isHittable == true"), object: afterTurn)],
            timeout: Wait.appear)
        afterTurn.tap()
        waitForLastMessage(containing: "after_turn")
        XCTAssertTrue(stub.lastMessage().contains("Then update the docs"))
    }

    /// Smoke test 7: the pane button switches panes by a tap and by press and slide, a pane the thread does not have is
    /// not offered, and a pane that is not shown is absent from the screen. The wide layouts (iPad, the Duo) show the
    /// panes as a strip or a row instead of the button, so this runs on the narrow iPhone layout only.
    func testPaneButtonSwitchesPanesAndAHiddenPaneIsAbsent() throws {
        try XCTSkipIf(UIDevice.current.userInterfaceIdiom != .phone, "iPhone only")
        try launch(scenario: "paired")
        openDrawerIfNeeded()
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        let conversation = app.descendants(matching: .any)["conversation"]
        XCTAssertTrue(conversation.waitForExistence(timeout: Wait.appear))
        let menu = app.buttons["pane.menu"]
        if !menu.waitForExistence(timeout: Wait.appear) {
            let isStripOrRow = app.buttons["pane.strip.chat"].exists || app.buttons["pane.row.chat"].exists
            try XCTSkipIf(isStripOrRow, "This layout shows the panes as a strip or a row, not the pane button")
            XCTFail("the thread has neither the pane button nor a strip of panes")
        }
        XCTAssertEqual(menu.value as? String, "Chat")
        func item(_ name: String) -> XCUIElement {
            app.buttons.matching(NSPredicate(format: "label == %@", name)).firstMatch
        }
        // A tap opens the menu with the panes this thread's show grants (preview: false in the scenario), and no other.
        menu.tap()
        for name in ["Chat", "Files", "Terminal", "Overview"] {
            XCTAssertTrue(item(name).waitForExistence(timeout: Wait.appear), name)
        }
        XCTAssertFalse(item("Preview").exists, "the thread has no preview, so the menu must not offer it")
        let overviewFrame = item("Overview").frame
        item("Files").tap()
        XCTAssertEqual(menu.value as? String, "Files")
        // The chat is not shown: Files is on screen and the chat cannot be reached.
        XCTAssertTrue(app.descendants(matching: .any)["files"].waitForExistence(timeout: Wait.appear))
        wait(
            for: [
                XCTNSPredicateExpectation(predicate: NSPredicate(format: "isHittable == false"), object: conversation)
            ],
            timeout: Wait.appear)
        // Press, slide to Overview and release in one motion; the menu opens where it did before.
        let origin = app.coordinate(withNormalizedOffset: .zero)
        menu.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.5)).press(
            forDuration: 0.8,
            thenDragTo: origin.withOffset(CGVector(dx: overviewFrame.midX, dy: overviewFrame.midY)))
        let shown = NSPredicate(format: "value == %@", "Overview")
        wait(for: [XCTNSPredicateExpectation(predicate: shown, object: menu)], timeout: Wait.appear)
        XCTAssertEqual(menu.value as? String, "Overview")
        menu.tap()
        item("Chat").tap()
        XCTAssertTrue(conversation.waitForExistence(timeout: Wait.appear))
        try audit()
    }

    /// A `/clear` starts a new thread, and the screen follows it.
    func testAClearOpensTheNewThread() throws {
        try XCTSkipIf(UIDevice.current.userInterfaceIdiom != .phone, "iPhone only")
        try launch(scenario: "paired")
        openDrawerIfNeeded()
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: Wait.appear))
        field.tap()
        field.typeText("/clear")
        app.buttons["composer.send"].tap()
        XCTAssertTrue(app.staticTexts["A fresh start"].waitForExistence(timeout: Wait.appear), app.debugDescription)
        // The old thread's title is not on the thread screen any more; the title element reads the new one.
        let title = app.descendants(matching: .any).matching(identifier: "thread.title").firstMatch
        XCTAssertTrue(title.waitForExistence(timeout: Wait.appear))
        wait(
            for: [
                XCTNSPredicateExpectation(
                    predicate: NSPredicate(format: "label CONTAINS %@", "A fresh start"), object: title)
            ],
            timeout: Wait.appear)
        try audit()
    }

    /// Smoke test 6, the composer's half: a send while offline is kept and goes out when the connection returns. The
    /// Chat pane says it shows what was loaded while the connection is down.
    func testADroppedConnectionShowsOfflineAndRecovers() throws {
        try XCTSkipIf(UIDevice.current.userInterfaceIdiom != .phone, "iPhone only")
        try launch(scenario: "paired")
        openDrawerIfNeeded()
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.staticTexts["Ready"].waitForExistence(timeout: Wait.firstLoad))
        try stub.setOnline(false)
        field.tap()
        field.typeText("Also update the changelog")
        let offline = app.staticTexts["Offline. Showing what was loaded"]
        XCTAssertTrue(offline.waitForExistence(timeout: Wait.appear), app.debugDescription)
        app.buttons["composer.send"].tap()
        XCTAssertFalse(stub.lastMessage().contains("Also update the changelog"))
        try stub.setOnline(true)
        waitForLastMessage(containing: "Also update the changelog", timeout: Wait.appear)
        let gone = XCTNSPredicateExpectation(predicate: NSPredicate(format: "exists == false"), object: offline)
        XCTAssertEqual(XCTWaiter().wait(for: [gone], timeout: Wait.appear), .completed)
    }

    private func waitForLastMessage(containing text: String, timeout: TimeInterval = Wait.appear) {
        let arrived = XCTNSPredicateExpectation(
            predicate: NSPredicate { [stub] _, _ in stub.lastMessage().contains(text) }, object: nil)
        XCTAssertEqual(
            XCTWaiter().wait(for: [arrived], timeout: timeout), .completed,
            "the stub never took a message with \(text)")
    }

    private func audit() throws { try auditThreadScreen(app) }
}
