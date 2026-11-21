import XCTest

/// The thread's conversation as the web page, with the native composer under it. The stub serves a test page that
/// speaks the script bridge; it is not the real thread page.
@MainActor final class WebThreadSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String, extraArguments: [String] = []) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"] + extraArguments
        app.launchRetryingOnce()
    }

    private func openThread(_ id: String = "d0e515ef") {
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:\(id)"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
    }

    private func page(_ text: String) -> XCUIElement { app.staticTexts[text] }

    private func waitForPage(_ threadID: String = "d0e515ef") {
        XCTAssertTrue(page("Stub conversation \(threadID)").waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(page("Ready").waitForExistence(timeout: Wait.appear))
    }

    private func embedState() throws -> String { try stub.get("embed/state") }

    func testAThreadOpensAsAWebConversation() throws {
        try launch(scenario: "embed-basic")
        openThread()
        waitForPage()
        XCTAssertTrue(app.webViews["thread.web.page"].exists, "the conversation is not a web view")
        XCTAssertTrue(app.textFields["composer.field"].waitForExistence(timeout: Wait.appear))
        let gone = NSPredicate(format: "exists == false")
        for identifier in ["thread.loading", "thread.slow", "thread.error"] {
            let element = app.descendants(matching: .any)[identifier]
            wait(for: [XCTNSPredicateExpectation(predicate: gone, object: element)], timeout: Wait.appear)
        }
        try audit()
    }

    func testSendingAMessageShowsItOnceInThePage() throws {
        try launch(scenario: "embed-basic")
        openThread()
        waitForPage()
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: Wait.appear))
        field.tap()
        field.typeText("Please add a retry")
        app.buttons["composer.send"].tap()
        // The page was told to refresh, and the unsent row gave way to the sent message.
        XCTAssertTrue(page("Refreshed 1 time").waitForExistence(timeout: Wait.appear))
        let rows = app.staticTexts.matching(NSPredicate(format: "label CONTAINS %@", "Please add a retry"))
        XCTAssertTrue(rows.firstMatch.waitForExistence(timeout: Wait.appear))
        XCTAssertEqual(rows.count, 1)
    }

    func testALinkTapOpensTheBrowserSheet() throws {
        try launch(scenario: "embed-basic")
        openThread()
        waitForPage()
        app.buttons["Open a link"].tap()
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        XCTAssertTrue(sheet.wait(for: .runningForeground, timeout: Wait.appear))
    }

    func testOpenPaneFromThePageSelectsFiles() throws {
        try launch(scenario: "embed-basic")
        openThread()
        waitForPage()
        app.buttons["Open the files pane"].tap()
        let files = app.descendants(matching: .any)["files"]
        XCTAssertTrue(files.waitForExistence(timeout: Wait.appear))
        wait(
            for: [XCTNSPredicateExpectation(predicate: NSPredicate(format: "isHittable == true"), object: files)],
            timeout: Wait.appear)
    }

    func testAnExpiredSessionRenewsItself() throws {
        try launch(scenario: "embed-expired-once")
        openThread()
        waitForPage()
        XCTAssertTrue(try embedState().contains("mints=2 redeems=2"))
    }

    func testASlowPageShowsTheSlowLineThenThePage() throws {
        try launch(scenario: "embed-slow")
        openThread()
        XCTAssertTrue(app.descendants(matching: .any)["thread.slow"].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.staticTexts["This is taking longer than usual"].exists)
        waitForPage()
        let gone = NSPredicate(format: "exists == false")
        wait(
            for: [XCTNSPredicateExpectation(predicate: gone, object: app.descendants(matching: .any)["thread.slow"])],
            timeout: Wait.appear)
    }

    func testAnOlderServerShowsTheNativeChat() throws {
        try launch(scenario: "embed-unsupported")
        openThread()
        XCTAssertTrue(app.descendants(matching: .any)["conversation"].waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(app.textFields["composer.field"].waitForExistence(timeout: Wait.appear))
        // Only the web page has a web view and the stub page's text.
        XCTAssertFalse(app.webViews["thread.web.page"].exists)
        XCTAssertFalse(page("Stub conversation d0e515ef").exists)
        XCTAssertTrue(try embedState().contains("mints=0"))
    }

    func testSwitchingThreadsReusesThePage() throws {
        try launch(scenario: "embed-basic")
        openThread()
        waitForPage()
        app.buttons["threads.button"].tap()
        let other = app.descendants(matching: .any)["drawer.row.thread:a1b2c3d4"]
        XCTAssertTrue(other.waitForExistence(timeout: Wait.appear))
        other.tap()
        XCTAssertTrue(page("Stub conversation a1b2c3d4").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(try embedState().contains("mints=1 redeems=1 loads=1"))
    }

    func testThePageIsHiddenWhileTheAppIsLocked() throws {
        try launch(
            scenario: "embed-basic",
            extraArguments: [
                "-yakTestAppLock", "on", "-yakTestAuthentication", "cancel,succeed,cancel,succeed",
                "-yakTestAppLockLimit", "0",
            ])
        let lock = app.descendants(matching: .any)["lock.screen"]
        XCTAssertTrue(lock.waitForExistence(timeout: Wait.appear))
        app.buttons["lock.unlock"].tap()
        openThread()
        waitForPage()
        let pageView = app.webViews["thread.web.page"]
        XCTAssertTrue(pageView.isHittable)
        XCUIDevice.shared.press(.home)
        let isBackgrounded = NSPredicate(format: "state != %d", XCUIApplication.State.runningForeground.rawValue)
        wait(for: [XCTNSPredicateExpectation(predicate: isBackgrounded, object: app)], timeout: Wait.appear)
        app.activate()
        XCTAssertTrue(lock.waitForExistence(timeout: Wait.appear))
        // The page is hidden, not only covered: none of its content is in the accessibility tree while locked.
        let gone = NSPredicate(format: "exists == false")
        wait(
            for: [XCTNSPredicateExpectation(predicate: gone, object: page("Stub conversation d0e515ef"))],
            timeout: Wait.appear)
        XCTAssertFalse(pageView.exists)
        app.buttons["lock.unlock"].tap()
        XCTAssertTrue(page("Stub conversation d0e515ef").waitForExistence(timeout: Wait.appear))
        wait(
            for: [XCTNSPredicateExpectation(predicate: NSPredicate(format: "isHittable == true"), object: pageView)],
            timeout: Wait.appear)
    }

    // MARK: Waiting messages

    private var queuedList: XCUIElement { app.descendants(matching: .any)["queued.list"] }

    /// A message sent while Yak works is saved as a waiting message; the page leaves it out, so a chip above the
    /// composer shows it, and withdrawing it removes the chip.
    func testAMessageSentWhileYakWorksShowsAChipThatCanBeWithdrawn() throws {
        try launch(scenario: "embed-working")
        try stub.setPlaybackMilliseconds(1000)
        defer { try? stub.setPlaybackMilliseconds(400) }
        openThread()
        waitForPage()
        XCTAssertTrue(app.buttons["composer.stop"].waitForExistence(timeout: Wait.appear), "the turn never started")
        try stub.setPlaybackMilliseconds(600_000)
        XCTAssertFalse(queuedList.exists)
        let field = app.textFields["composer.field"]
        field.tap()
        field.typeText("Also cover the empty file case")
        app.buttons["composer.send"].tap()
        XCTAssertTrue(queuedList.waitForExistence(timeout: Wait.appear))
        let text = app.buttons.matching(NSPredicate(format: "identifier BEGINSWITH %@", "queued.text.")).firstMatch
        XCTAssertTrue(text.exists)
        XCTAssertEqual(text.label, "Also cover the empty file case")
        XCTAssertTrue(app.staticTexts["Steering"].exists)
        try audit()
        let menu = app.buttons.matching(NSPredicate(format: "identifier BEGINSWITH %@", "queued.menu.")).firstMatch
        menu.tap()
        let remove = app.buttons["Remove"]
        XCTAssertTrue(remove.waitForExistence(timeout: Wait.appear))
        remove.tap()
        wait(
            for: [XCTNSPredicateExpectation(predicate: NSPredicate(format: "exists == false"), object: queuedList)],
            timeout: Wait.appear)
    }

    private func audit() throws { try auditThreadScreen(app) }
}
