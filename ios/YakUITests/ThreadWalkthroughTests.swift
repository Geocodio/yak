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

    // MARK: Chat

    /// Opens `d0e515ef` of a scenario and waits for the chat to show.
    private func openChat(_ scenario: String, thread: String = "d0e515ef", holdAfter: Int? = nil) throws {
        try start(scenario, paired: true)
        // The playback starts when the thread opens, so the hold set here applies from its first event.
        try stub.hold(after: holdAfter)
        try openThread(thread)
        XCTAssertTrue(app.descendants(matching: .any)["conversation"].waitForExistence(timeout: 15))
    }

    func testChatStreaming() throws {
        // The stub plays the first deltas and holds, so the reply is half written.
        try openChat("one-thread-working", holdAfter: 7)
        XCTAssertTrue(label("Looking at the importer.").waitForExistence(timeout: 20))
        try shootThread("chat-streaming")
    }

    func testChatWorking() throws {
        // Held after the tool calls start and before their results: the current step is tinted and timed.
        try openChat("one-thread-working", holdAfter: 8)
        XCTAssertTrue(app.descendants(matching: .any)["step.current"].waitForExistence(timeout: 20))
        try shootThread("chat-working")
    }

    func testChatFinished() throws {
        try openChat("paired")
        XCTAssertTrue(label("Done. Failed rows now retry three times.").waitForExistence(timeout: 15))
        try shootThread("chat-finished")
    }

    func testChatToolsExpanded() throws {
        try openChat("paired")
        let group = app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "Read 1 file, Ran 1 command"))
            .firstMatch
        XCTAssertTrue(group.waitForExistence(timeout: 15))
        group.tap()
        XCTAssertTrue(label("Ran php artisan test").waitForExistence(timeout: 5))
        try shootThread("chat-tools-expanded")
    }

    func testChatHistory() throws {
        try openChat("long-history")
        XCTAssertTrue(label("Step 1200.").waitForExistence(timeout: 20))
        // Fast swipes reach the first loaded row, where the older page is asked for and takes a few seconds.
        let loading = label("Loading earlier messages")
        let list = app.descendants(matching: .any)["conversation"].firstMatch
        var isLoading = false
        for _ in 0..<80 where !isLoading {
            list.swipeDown(velocity: .fast)
            isLoading = loading.waitForExistence(timeout: 0.5)
        }
        XCTAssertTrue(isLoading)
        // The row that says so sits just above the first loaded one.
        list.swipeDown()
        try shootThread("chat-history-loading")
    }

    func testChatEmpty() throws {
        try openChat("thread-states", thread: "n0events")
        XCTAssertTrue(label("No messages yet").waitForExistence(timeout: 15))
        try shootThread("chat-empty")
    }

    func testChatNotices() throws {
        let notices: [(id: String, name: String, text: String)] = [
            ("vsage000", "notice-usage-limit", "Usage limit reached"),
            ("g7ard000", "notice-guard", "Claude stopped after 50 steps"),
            ("rest0re0", "notice-restore-failed", "Rebuilding the sandbox stopped at step 2"),
            ("acc07nt0", "notice-account", "The account needs attention"),
            ("cred0000", "notice-credential", "A credential was exposed"),
            ("wa17ng00", "notice-waiting", "Waiting for a free sandbox"),
        ]
        for notice in notices {
            // Each notice starts from a fresh launch, so the drawer is the same on every pass and in every orientation.
            app.terminate()
            try start("thread-states", paired: true)
            try openThread(notice.id)
            XCTAssertTrue(label(notice.text).waitForExistence(timeout: 15), notice.name)
            try shootThread(notice.name)
        }
    }

    func testChatOffline() throws {
        try openChat("paired")
        XCTAssertTrue(label("Done. Failed rows now retry three times.").waitForExistence(timeout: 15))
        try stub.setOnline(false)
        XCTAssertTrue(label("Offline. Showing a saved copy").waitForExistence(timeout: 60))
        try shootThread("chat-offline")
    }

    func testChatJumpToLatest() throws {
        try openChat("long-history")
        XCTAssertTrue(label("Step 1200.").waitForExistence(timeout: 20))
        let list = app.descendants(matching: .any)["conversation"].firstMatch
        for _ in 0..<3 { list.swipeDown() }
        XCTAssertTrue(app.descendants(matching: .any)["chat.jumpToLatest"].firstMatch.waitForExistence(timeout: 5))
        try shootThread("chat-jump-to-latest")
        app.descendants(matching: .any)["chat.jumpToLatest"].firstMatch.tap()
        XCTAssertTrue(label("Step 1200.").waitForExistence(timeout: 5))
    }

    /// The debug gallery draws the chat from canned snapshots: states the stub cannot reach, such as a refused unsent
    /// message, a queued message and every kind of activity.
    private func openGallery(_ page: String) throws {
        try start("paired", paired: true, extraArguments: ["-yakChatGallery", page])
        XCTAssertTrue(app.descendants(matching: .any)["chat.list"].firstMatch.waitForExistence(timeout: 20))
    }

    func testChatMessages() throws {
        try openGallery("messages-a")
        XCTAssertTrue(app.descendants(matching: .any)["message.menu.u3"].waitForExistence(timeout: 10))
        try shootThread("chat-queued")
    }

    func testChatUnsent() throws {
        try openGallery("messages-b")
        XCTAssertTrue(label("This one takes a while.").waitForExistence(timeout: 10))
        try shootThread("chat-unsent-states")
    }

    func testChatRefused() throws {
        try openGallery("messages-c")
        XCTAssertTrue(app.buttons["unsent.edit"].waitForExistence(timeout: 10))
        try shootThread("chat-unsent")
    }

    func testChatUnsentPhoto() throws {
        try openGallery("messages-d")
        XCTAssertTrue(label("Here is the photo.").waitForExistence(timeout: 10))
        try shootThread("chat-unsent-photo")
    }

    func testChatActivity() throws {
        try openGallery("activity")
        XCTAssertTrue(app.descendants(matching: .any)["step.current"].waitForExistence(timeout: 10))
        try shootThread("chat-activity")
    }

    func testChatCards() throws {
        try openGallery("activity-b")
        XCTAssertTrue(label("The turn failed").waitForExistence(timeout: 10))
        try shootThread("chat-cards")
    }

    func testChatActivityOpen() throws {
        try openGallery("activity-open")
        XCTAssertTrue(label("Ran php artisan test").waitForExistence(timeout: 10))
        try shootThread("chat-activity-open")
    }

    func testChatHistoryFailed() throws {
        try openGallery("history-failed")
        XCTAssertTrue(app.buttons["chat.history.retry"].waitForExistence(timeout: 10))
        try shootThread("chat-history-failed")
    }

    func testChatNoticeWithGuard() throws {
        try openGallery("notice")
        XCTAssertTrue(app.buttons["notice.continue"].waitForExistence(timeout: 10))
        try shootThread("chat-notice-continue")
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
            // On a wide screen the audit reports contrast in the drawer's sidebar without naming an element.
            if issue.auditType == .contrast, issue.element == nil, app.windows.firstMatch.frame.width > 700 {
                return true
            }
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
