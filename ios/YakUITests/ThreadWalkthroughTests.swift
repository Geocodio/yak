import XCTest

/// The quality-bar walkthrough of the thread screens: the same conventions as `WalkthroughTests`, one screenshot per
/// state in `$YAK_SHOT_DIR`. Run it with `bundle exec fastlane walkthrough only:ThreadWalkthroughTests`.
@MainActor final class ThreadWalkthroughTests: WalkthroughCase {
    // MARK: Drawer

    func testDrawer() throws {
        try start("paired", paired: true)
        try waitForDrawer()
        XCTAssertTrue(app.descendants(matching: .any)["drawer.row.thread:d0e515ef"].waitForExistence(timeout: 10))
        try shoot("drawer")
    }

    func testDrawerSubThreadsOpen() throws {
        try start("paired", paired: true)
        try waitForDrawer()
        let toggle = app.buttons["drawer.toggle.thread:a1b2c3d4"]
        XCTAssertTrue(scrollToFind(toggle, in: "drawer.list"))
        toggle.tap()
        let child = app.descendants(matching: .any)["drawer.row.thread:e5f6g7h8"]
        // At the largest text size the new rows are below the fold.
        XCTAssertTrue(scrollToFind(child, in: "drawer.list"))
        try shoot("drawer-sub-threads")
    }

    func testDrawerLoading() throws {
        try start("slower", paired: true)
        XCTAssertTrue(app.descendants(matching: .any)["drawer.loading"].waitForExistence(timeout: 10))
        try shoot("drawer-loading")
        XCTAssertTrue(label("This is taking longer than usual").waitForExistence(timeout: 12))
        try shoot("drawer-slow")
    }

    func testDrawerEmpty() throws {
        try start("team-only", paired: true)
        try waitForDrawer()
        XCTAssertTrue(app.descendants(matching: .any)["drawer.row.thread:d0e515ef"].waitForExistence(timeout: 10))
        // The first landing is Everyone; Mine has nothing, and the empty state offers what is left.
        app.buttons["drawer.filter"].tap()
        app.buttons["Mine"].tap()
        XCTAssertTrue(app.buttons["drawer.empty.everyone"].waitForExistence(timeout: 10))
        try shoot("drawer-empty-others")
        try start("empty", paired: true)
        if app.buttons["threads.button"].waitForExistence(timeout: 10) {
            app.buttons["threads.button"].tap()
        } else {
            // A wide screen has no threads button; Command backslash shows the sidebar.
            app.typeKey("\\", modifierFlags: .command)
        }
        XCTAssertTrue(app.buttons["drawer.empty.start"].waitForExistence(timeout: 10))
        try shoot("drawer-empty")
    }

    func testDrawerNoResults() throws {
        try start("paired", paired: true)
        try waitForDrawer()
        let search = app.textFields["drawer.search"]
        search.tap()
        search.typeText("webhook retry")
        XCTAssertTrue(app.buttons["drawer.noResults.widen"].waitForExistence(timeout: 10))
        try shoot("drawer-no-results")
    }

    func testDrawerOffline() throws {
        try start("paired", paired: true)
        try waitForDrawer()
        XCTAssertTrue(app.descendants(matching: .any)["drawer.row.thread:d0e515ef"].waitForExistence(timeout: 10))
        try stub.setOnline(false)
        XCTAssertTrue(app.descendants(matching: .any)["drawer.offlineNotice"].waitForExistence(timeout: 20))
        try shoot("drawer-offline")
    }

    func testDrawerError() throws {
        try start("broken", paired: true)
        XCTAssertTrue(app.buttons["Retry"].waitForExistence(timeout: 15))
        try shoot("drawer-error")
    }

    func testInstanceSwitcher() throws {
        try start("paired", paired: true)
        try waitForDrawer()
        app.buttons["drawer.switcher"].tap()
        XCTAssertTrue(app.buttons["switcher.add"].waitForExistence(timeout: 5))
        XCTAssertTrue(scrollToFind(app.buttons["switcher.add"], in: "switcher"))
        // The sheet is the system's translucent surface; the audit cannot judge text on it.
        try shoot("drawer-switcher", audits: false)
    }

    func testFirstLandingOnEveryone() throws {
        try start("team-only", paired: true)
        try waitForDrawer()
        XCTAssertTrue(app.descendants(matching: .any)["drawer.row.thread:d0e515ef"].waitForExistence(timeout: 10))
        try shoot("drawer-everyone")
    }

    // MARK: Helpers

    /// At the largest text size rows and buttons sit below the fold; the container scrolls down and then back up
    /// until the middle of the element is on screen above the bottom bar, where a tap lands on it.
    private func scrollToFind(_ element: XCUIElement, in container: String) -> Bool {
        let scroller = app.descendants(matching: .any)[container]
        let bottomEdge =
            container == "drawer.list" ? app.textFields["drawer.search"].frame.minY : app.windows.firstMatch.frame.maxY
        func isReachable() -> Bool { element.isHittable && element.frame.midY < bottomEdge }
        for _ in 0..<8 where !isReachable() {
            scroller.swipeUp()
        }
        for _ in 0..<16 where !isReachable() {
            scroller.swipeDown()
        }
        return isReachable()
    }

    /// The cold start of these scenarios lands on the drawer; a compact thread screen opens it with the threads button.
    private func waitForDrawer() throws {
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) {
            app.buttons["threads.button"].tap()
        }
        XCTAssertTrue(app.otherElements["drawer"].waitForExistence(timeout: 10))
    }
}
