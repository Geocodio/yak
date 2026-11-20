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
}
