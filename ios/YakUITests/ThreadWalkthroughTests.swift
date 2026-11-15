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

    // MARK: Thread screen

    func testThreadFrame() throws {
        try start("paired", paired: true)
        try openThread("d0e515ef")
        XCTAssertTrue(app.staticTexts["Retry failed rows when the import times out"].waitForExistence(timeout: 15))
        XCTAssertTrue(app.descendants(matching: .any)["thread.bar"].waitForExistence(timeout: 10))
        try shootThread("thread")
    }

    func testThreadLoading() throws {
        try start("slow-thread", paired: true)
        try openThread("d0e515ef")
        XCTAssertTrue(app.descendants(matching: .any)["thread.loading"].waitForExistence(timeout: 10))
        // The loading line is on screen, and the slow explanation is not yet.
        XCTAssertTrue(label("Loading the conversation").exists)
        XCTAssertFalse(app.descendants(matching: .any)["thread.slow"].exists)
        try shootThread("thread-loading")
        XCTAssertTrue(app.descendants(matching: .any)["thread.slow"].waitForExistence(timeout: 15))
        XCTAssertFalse(app.descendants(matching: .any)["thread.loading"].exists)
        try shootThread("thread-slow")
    }

    func testThreadMissing() throws {
        try start("thread-missing", paired: true)
        // A link to a thread that is gone lands on the same screen as its drawer row would.
        try waitForDrawer()
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "thread"
        components.queryItems = [
            URLQueryItem(name: "instance", value: stub.url), URLQueryItem(name: "id", value: "d0e515ef"),
        ]
        app.open(try XCTUnwrap(components.url))
        XCTAssertTrue(label("This thread is not here anymore").waitForExistence(timeout: 15))
        try shootThread("thread-missing")
    }

    func testThreadOfflineNoCopy() throws {
        try start("paired", paired: true)
        try waitForDrawer()
        XCTAssertTrue(app.descendants(matching: .any)["drawer.row.thread:d0e515ef"].waitForExistence(timeout: 10))
        try stub.setOnline(false)
        try openThread("d0e515ef")
        XCTAssertTrue(label("You are offline").waitForExistence(timeout: 60))
        try shootThread("thread-offline")
    }

    func testPaneMenu() throws {
        try start("paired", paired: true)
        try openThread("d0e515ef")
        let button = app.buttons["pane.button"]
        // Only a bar that holds the pane button has a menu; the other placements show the panes themselves.
        guard button.waitForExistence(timeout: 15) else {
            XCTAssertTrue(app.descendants(matching: .any)["thread.bar"].exists)
            try shootThread("pane-menu")
            return
        }
        button.tap()
        XCTAssertTrue(app.buttons["Chat"].waitForExistence(timeout: 5))
        // The menu is the system's own surface; the audit cannot judge text on it.
        try shoot("pane-menu", audits: false)
    }

    // MARK: Helpers

    /// A screenshot of a thread screen, then the audit without the findings about text in the navigation bar,
    /// whose size the system caps.
    private func shootThread(_ name: String) throws {
        try shoot(name, audits: false)
        try app.performAccessibilityAudit { [app] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                // The system caps the size of text in its bars, and draws its glass under the threads badge.
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            // At the largest text size the content scrolls under the bar's glass; the audit measures text there
            // against the glass, though it reads on the page once scrolled clear of it.
            let bar = app.descendants(matching: .any)["thread.bar"]
            if issue.auditType == .contrast, let element = issue.element, bar.exists,
                bar.frame.intersects(element.frame)
            {
                return true
            }
            // On a wide screen the audit reports contrast in the drawer's sidebar without naming an element.
            if issue.auditType == .contrast, issue.element == nil { return true }
            XCTFail("audit [\(name)]: \(issue.compactDescription) | \(issue.element?.debugDescription ?? "no element")")
            return true
        }
    }

    /// Opens a thread from the drawer's row, scrolling to it at the largest text size.
    private func openThread(_ id: String) throws {
        try waitForDrawer()
        let row = app.descendants(matching: .any)["drawer.row.thread:\(id)"]
        XCTAssertTrue(row.waitForExistence(timeout: 10) || scrollToFind(row, in: "drawer.list"))
        row.tap()
    }

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
