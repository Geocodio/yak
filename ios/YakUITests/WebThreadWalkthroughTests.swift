import XCTest

/// The quality-bar walkthrough of the thread's web conversation: one screenshot per state in `$YAK_SHOT_DIR`. The
/// page is the stub's test page, not the real thread page. Run it with
/// `bundle exec fastlane walkthrough only:WebThreadWalkthroughTests`.
@MainActor final class WebThreadWalkthroughTests: WalkthroughCase {
    private func open(_ scenario: String) throws {
        try start(scenario, paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        if !app.buttons["pane.menu"].exists { row.tap() }
    }

    func testWebConversation() throws {
        try open("embed-basic")
        XCTAssertTrue(app.staticTexts["Ready"].waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(app.textFields["composer.field"].waitForExistence(timeout: Wait.appear))
        try shootThread("web-thread")
    }

    func testWebConversationLoadingAndSlow() throws {
        try open("embed-slow")
        XCTAssertTrue(app.descendants(matching: .any)["thread.loading"].waitForExistence(timeout: Wait.appear))
        try shootThread("web-thread-loading")
        XCTAssertTrue(app.descendants(matching: .any)["thread.slow"].waitForExistence(timeout: Wait.appear))
        try shootThread("web-thread-slow")
    }

    func testWebConversationFailed() throws {
        try open("embed-failed")
        XCTAssertTrue(app.descendants(matching: .any)["thread.error"].waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(app.buttons["thread.retry"].exists)
        try shootThread("web-thread-failed")
    }

    /// The end of the page above the composer or the home indicator, and a `focus` target in view.
    func testWebConversationScrolledToTheBottom() throws {
        try open("embed-basic")
        XCTAssertTrue(app.staticTexts["Ready"].waitForExistence(timeout: Wait.firstLoad))
        let page = app.webViews["thread.web.page"]
        for _ in 0..<8 { page.swipeUp(velocity: .fast) }
        try shootThread("web-thread-bottom")
    }

    func testWebConversationServerNeedsUpdate() throws {
        try open("embed-unsupported")
        XCTAssertTrue(app.staticTexts["Update needed"].waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(app.buttons["thread.openInBrowser"].exists)
        try shootThread("web-thread-needs-update")
    }

    func testWaitingMessages() throws {
        try open("embed-working")
        try stub.setPlaybackMilliseconds(1000)
        defer { try? stub.setPlaybackMilliseconds(400) }
        XCTAssertTrue(app.buttons["composer.stop"].waitForExistence(timeout: Wait.firstLoad))
        try stub.setPlaybackMilliseconds(600_000)
        let messages = [
            "Also cover the empty file case",
            "Then update the docs and the changelog so the retry behaviour is described for people who run the import by hand",
            "Run the full suite once more", "Leave the old migration alone", "Open the pull request as a draft",
        ]
        let texts = app.buttons.matching(NSPredicate(format: "identifier BEGINSWITH %@", "queued.text."))
        for (index, text) in messages.enumerated() {
            let field = app.textFields["composer.field"]
            field.tap()
            field.typeText(text)
            app.buttons["composer.send"].tap()
            let count = index + 1
            let shown = NSPredicate { _, _ in texts.count == min(count, 3) || (count > 3 && texts.count >= 3) }
            wait(for: [XCTNSPredicateExpectation(predicate: shown, object: nil)], timeout: Wait.appear)
            if [1, 3, 5].contains(count) { try shootThread("queued-\(count)") }
        }
    }
}
