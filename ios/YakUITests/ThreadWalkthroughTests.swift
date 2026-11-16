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
        let button = app.buttons["pane.menu"]
        // Only a bar that holds the pane button has a menu; the other placements show the panes themselves.
        guard button.waitForExistence(timeout: 15) else {
            XCTAssertTrue(app.descendants(matching: .any)["thread.bar"].exists)
            try shootThread("pane-menu")
            return
        }
        button.tap()
        XCTAssertEqual(button.label, "Thread panes")
        XCTAssertTrue(app.buttons["Chat"].waitForExistence(timeout: 5))
        // The menu is the system's own surface; the audit cannot judge text on it.
        try shoot("pane-menu", audits: false)
    }

    // MARK: Composer

    func testComposerIdle() throws {
        try start("paired", paired: true)
        try openThread("d0e515ef")
        XCTAssertTrue(app.textFields["composer.field"].waitForExistence(timeout: 15))
        try shootThread("composer-idle")
    }

    func testComposerTyping() throws {
        try start("paired", paired: true)
        try openThread("d0e515ef")
        try type("Use the draft names in the importer", then: "composer.send")
        try shootThread("composer-typing")
    }

    func testComposerRunning() throws {
        try start("one-thread-working", paired: true)
        try stub.setPlaybackMilliseconds(3000)
        defer { try? stub.setPlaybackMilliseconds(400) }
        try openThread("d0e515ef")
        XCTAssertTrue(app.buttons["composer.stop"].waitForExistence(timeout: 20))
        try shootThread("composer-running")
    }

    func testComposerRunningTyping() throws {
        try start("one-thread-working", paired: true)
        try stub.setPlaybackMilliseconds(3000)
        defer { try? stub.setPlaybackMilliseconds(400) }
        try openThread("d0e515ef")
        try type("Also cover the empty file case", then: "composer.send")
        XCTAssertTrue(app.buttons["composer.stop"].exists, "the scripted turn ended")
        try shootThread("composer-running-typing")
    }

    func testComposerSendAfterTurnMenu() throws {
        try start("one-thread-working", paired: true)
        try stub.setPlaybackMilliseconds(3000)
        defer { try? stub.setPlaybackMilliseconds(400) }
        try openThread("d0e515ef")
        try type("Then update", then: "composer.send")
        XCTAssertTrue(app.buttons["composer.stop"].exists, "the scripted turn ended")
        app.buttons["composer.send"].press(forDuration: 1.0)
        XCTAssertTrue(
            app.menuItems["Send after this turn"].waitForExistence(timeout: 5)
                || app.buttons["composer.sendAfterTurn"].waitForExistence(timeout: 2))
        // The menu is the system's own surface; the audit cannot judge text on it.
        try shoot("composer-send-after-turn", audits: false)
    }

    func testComposerKeyboard() throws {
        try start("paired", paired: true)
        try openThread("d0e515ef")
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: 15))
        field.tap()
        XCTAssertTrue(app.keyboards.firstMatch.waitForExistence(timeout: 10))
        try shootThread("composer-keyboard")
    }

    func testBarStates() throws {
        for (id, name) in [
            ("c10sed00", "bar-closed"), ("readc0py", "bar-read-only"), ("c0mp7ter", "bar-computer"),
            ("cred0000", "bar-blocked"),
        ] {
            try start("thread-states", paired: true)
            try openThreadByLink(id)
            XCTAssertTrue(app.descendants(matching: .any)["composer.unavailable"].waitForExistence(timeout: 15), name)
            try shootThread(name)
        }
    }

    // MARK: Plus menu, attachments and the model picker

    func testPlusMenu() throws {
        try start("paired", paired: true)
        try openThread("d0e515ef")
        try openPlusMenu()
        // The menu is the system's own surface; the audit cannot judge text on it.
        try shoot("plus-menu", audits: false)
    }

    func testModelPicker() throws {
        try start("paired", paired: true)
        try openThread("d0e515ef")
        try openPlusMenu()
        app.buttons["composer.plusMenu.model"].tap()
        XCTAssertTrue(app.buttons["model.close"].waitForExistence(timeout: 10))
        let older = app.buttons["model.older.sonnet"]
        XCTAssertTrue(older.waitForExistence(timeout: 5))
        older.tap()
        XCTAssertTrue(label("Sonnet 5, pinned").waitForExistence(timeout: 5))
        try shoot("model-picker")
    }

    /// The pictures come from a test-mode launch argument, not the system photo picker, which a test cannot drive at
    /// the largest text size.
    func testAttachmentStrip() throws {
        try start("paired", paired: true, attachments: 2)
        try openThread("d0e515ef")
        XCTAssertTrue(app.descendants(matching: .any)["composer.attachment"].waitForExistence(timeout: 15))
        try shootThread("attachments")
    }

    func testAttachmentProblem() throws {
        try start("one-attachment", paired: true, attachments: 2)
        try openThread("d0e515ef")
        XCTAssertTrue(app.descendants(matching: .any)["composer.attachment"].waitForExistence(timeout: 15))
        XCTAssertTrue(app.descendants(matching: .any)["composer.problem"].waitForExistence(timeout: 15))
        try shootThread("attachment-too-many")
    }

    func testCameraDenied() throws {
        try start("paired", paired: true, cameraDenied: true)
        try openThread("d0e515ef")
        try openPlusMenu()
        app.buttons["composer.plusMenu.camera"].tap()
        XCTAssertTrue(app.buttons["composer.cameraDenied.settings"].waitForExistence(timeout: 10))
        try shoot("camera-denied")
    }

    private func openPlusMenu() throws {
        let plus = app.buttons["composer.plus"]
        XCTAssertTrue(plus.waitForExistence(timeout: 15))
        plus.tap()
        XCTAssertTrue(app.buttons["composer.plusMenu.photos"].waitForExistence(timeout: 5))
    }

    private func type(_ text: String, then identifier: String) throws {
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: 15))
        field.tap()
        field.typeText(text)
        XCTAssertTrue(app.buttons[identifier].waitForExistence(timeout: 5))
        // The keyboard stays up for the shot, the way a person sees it.
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
            // The system keyboard's prediction bar is not ours to label, and what it covers cannot be judged.
            if (issue.element?.debugDescription ?? "").contains("TUIPredictionViewCell") { return true }
            if issue.auditType == .contrast, let element = issue.element, app.keyboards.firstMatch.exists,
                app.keyboards.firstMatch.frame.intersects(element.frame)
            {
                return true
            }
            // The drawer slid off the left edge is still in the tree; what the screen does not show cannot be judged.
            if issue.auditType == .contrast, let element = issue.element, element.frame.maxX <= 0 { return true }
            // On a wide screen the audit reports contrast in the drawer's sidebar without naming an element.
            if issue.auditType == .contrast, issue.element == nil, app.windows.firstMatch.frame.width > 700 {
                return true
            }
            XCTFail("audit [\(name)]: \(issue.compactDescription) | \(issue.element?.debugDescription ?? "no element")")
            return true
        }
    }

    /// Opens a thread through its link, for rows that sit far down the drawer at the largest text size.
    private func openThreadByLink(_ id: String) throws {
        try waitForDrawer()
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "thread"
        components.queryItems = [URLQueryItem(name: "instance", value: stub.url), URLQueryItem(name: "id", value: id)]
        app.open(try XCTUnwrap(components.url))
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
