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

    /// Rows with a Slack, Linear, Sentry, Dashboard and an unknown source, and one without a source.
    func testDrawerSources() throws {
        try start("thread-states", paired: true)
        try waitForDrawer()
        // Each row's label carries its source once ("From Slack"); the row with no source reads none.
        let expected: [(id: String, source: String?)] = [
            ("c10sed00", "Slack"), ("readc0py", "Linear"), ("c0mp7ter", "Sentry"), ("vsage000", "Dashboard"),
            ("g7ard000", "PagerDuty"), ("rest0re0", nil),
            ("acc07nt0", "A channel label from a much newer server version"),
        ]
        for (id, source) in expected {
            let row = app.descendants(matching: .any)["drawer.row.thread:\(id)"]
            XCTAssertTrue(row.waitForExistence(timeout: 10), id)
            if let source {
                XCTAssertTrue(row.label.contains("From \(source)"), "\(id): \(row.label)")
            } else {
                XCTAssertFalse(row.label.contains("From "), "\(id): \(row.label)")
                XCTAssertTrue(row.label.contains("example/api"), "\(id): \(row.label)")
            }
        }
        try shoot("drawer-sources")
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
        // The tab row is the menu button at the largest text size.
        if app.buttons["drawer.tab.mine"].waitForExistence(timeout: 10) {
            app.buttons["drawer.tab.mine"].tap()
        } else {
            app.buttons["drawer.tabs.menu"].tap()
            app.buttons["Mine"].tap()
        }
        XCTAssertTrue(app.buttons["drawer.empty.everyone"].waitForExistence(timeout: 10))
        try shoot("drawer-empty-others")
        try start("empty", paired: true)
        // The cold start closes the drawer when it opens the new-thread screen; a tap before that is lost.
        XCTAssertTrue(app.descendants(matching: .any)["newThread"].waitForExistence(timeout: 15))
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

    /// One notice per test: six launches and audits in one test ran the audit out of time.
    private func shootNotice(id: String, name: String, text: String) throws {
        try start("thread-states", paired: true)
        try openThread(id)
        XCTAssertTrue(label(text).waitForExistence(timeout: 15), name)
        try shootThread(name)
    }

    /// The debug gallery draws the chat from canned snapshots: states the stub cannot reach, such as a refused unsent
    /// message, a queued message and every kind of activity.
    private func openGallery(_ page: String) throws {
        try start("paired", paired: true, extraArguments: ["-yakChatGallery", page])
        XCTAssertTrue(app.descendants(matching: .any)["chat.list"].firstMatch.waitForExistence(timeout: 20))
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

    // MARK: New thread

    func testNewThread() throws {
        try start("empty", paired: true)
        XCTAssertTrue(app.descendants(matching: .any)["newThread.repo"].waitForExistence(timeout: 15))
        XCTAssertTrue(app.descendants(matching: .any)["newThread.field"].exists)
        try shootNewThread("new-thread")
    }

    func testNewThreadLoading() throws {
        try start("slow-new-thread", paired: true)
        XCTAssertTrue(app.descendants(matching: .any)["newThread.loading"].waitForExistence(timeout: 15))
        try shootNewThread("new-thread-loading")
        XCTAssertTrue(app.descendants(matching: .any)["newThread.slow"].waitForExistence(timeout: 15))
        try shootNewThread("new-thread-slow")
    }

    func testNewThreadMore() throws {
        try start("empty", paired: true)
        XCTAssertTrue(app.descendants(matching: .any)["newThread.repo"].waitForExistence(timeout: 15))
        app.buttons["newThread.more"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["newThread.moreSheet"].waitForExistence(timeout: 5))
        app.buttons["newThread.more.add"].tap()
        let extra = app.descendants(matching: .any).matching(NSPredicate(format: "label == %@", "web")).firstMatch
        XCTAssertTrue(extra.waitForExistence(timeout: 5))
        extra.tap()
        XCTAssertTrue(app.staticTexts["web"].waitForExistence(timeout: 5))
        // The sheet is the system's surface; the audit cannot judge text on it.
        try shoot("new-thread-more", audits: false)
    }

    func testNewThreadNotReady() throws {
        try start("empty", paired: true)
        XCTAssertTrue(app.descendants(matching: .any)["newThread.repo"].waitForExistence(timeout: 15))
        app.buttons["newThread.repo"].tap()
        let web = app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "web")).firstMatch
        XCTAssertTrue(web.waitForExistence(timeout: 5))
        web.tap()
        app.descendants(matching: .any)["newThread.field"].tap()
        app.typeText("Fix the importer")
        app.buttons["newThread.send"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["newThread.problem"].waitForExistence(timeout: 5))
        try shootNewThread("new-thread-not-ready")
    }

    func testNewThreadRefused() throws {
        try start("new-thread-refused", paired: true)
        try waitForDrawer()
        app.buttons["drawer.newThread"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["newThread.repo"].waitForExistence(timeout: 15))
        app.descendants(matching: .any)["newThread.field"].tap()
        app.typeText("Fix the importer")
        app.buttons["newThread.send"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["newThread.refused"].waitForExistence(timeout: 20))
        XCTAssertTrue(app.buttons["newThread.edit"].exists)
        XCTAssertTrue(app.buttons["threads.button"].exists)
        try shootNewThread("new-thread-refused")
    }

    func testNewThreadOffline() throws {
        try start("empty", paired: true)
        XCTAssertTrue(app.descendants(matching: .any)["newThread.repo"].waitForExistence(timeout: 15))
        try stub.setOnline(false)
        XCTAssertTrue(app.descendants(matching: .any)["newThread.offline"].waitForExistence(timeout: 20))
        app.descendants(matching: .any)["newThread.field"].tap()
        app.typeText("Fix the importer")
        app.buttons["newThread.send"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["newThread.waiting"].waitForExistence(timeout: 30))
        try shootNewThread("new-thread-offline")
        // The network returns: the outbox sends the item and the screen opens the new thread.
        try stub.setOnline(true)
        let waiting = app.descendants(matching: .any)["newThread.waiting"]
        XCTAssertTrue(waiting.waitForNonExistence(timeout: 40))
        XCTAssertTrue(app.descendants(matching: .any)["thread.bar"].waitForExistence(timeout: 30))
    }

    func testNewThreadNoRepositories() throws {
        try start("no-repositories", paired: true)
        XCTAssertTrue(app.descendants(matching: .any)["newThread.empty"].waitForExistence(timeout: 15))
        XCTAssertTrue(app.buttons["newThread.openWeb"].exists)
        try shootNewThread("new-thread-empty")
    }

    func testNewThreadFailed() throws {
        try start("broken-new-thread", paired: true)
        XCTAssertTrue(app.descendants(matching: .any)["newThread.failed"].waitForExistence(timeout: 20))
        try shootNewThread("new-thread-failed")
    }

    // MARK: Helpers

    /// Opens a thread through its link, for rows that sit far down the drawer at the largest text size.
    private func openThreadByLink(_ id: String) throws {
        try waitForDrawer()
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "thread"
        components.queryItems = [URLQueryItem(name: "instance", value: stub.url), URLQueryItem(name: "id", value: id)]
        app.open(try XCTUnwrap(components.url))
    }

    /// A screenshot of the new-thread screen, then the audit without the findings about text in the navigation bar,
    /// whose size the system caps, and without the skeleton chips, which are hidden from VoiceOver on purpose.
    private func shootNewThread(_ name: String) throws {
        // The system keyboard shows its swipe-typing introduction once on a fresh simulator.
        let introduction = app.buttons["Continue"]
        if introduction.exists { introduction.tap() }
        // The field takes focus when the screen opens; the audit runs once the keyboard has settled.
        _ = app.keyboards.firstMatch.waitForExistence(timeout: 3)
        try shoot(name, audits: false)
        let auditBars = AuditRules.Bars(app)
        try app.performAccessibilityAudit { [app, auditBars] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            // The keyboard covers part of the screen; what is behind it cannot be judged.
            let keyboard = app.keyboards.firstMatch
            if issue.auditType == .contrast, keyboard.exists, let element = issue.element,
                keyboard.frame.intersects(element.frame)
            {
                return true
            }
            // A short window scrolls the page under the navigation bar, whose glass blurs what passes behind it.
            if issue.auditType == .contrast, let element = issue.element,
                bars.contains(where: { $0.frame.intersects(element.frame) })
            {
                return true
            }
            // At the largest text size the page scrolls under the navigation bar and the keyboard, and the audit
            // finds the cut text without naming an element.
            if issue.auditType == .contrast || issue.auditType == .elementDetection, issue.element == nil,
                ProcessInfo.processInfo.environment["YAK_TEXT_SIZE"] == "largest"
            {
                return true
            }
            // On a wide screen the audit reports contrast in the drawer's sidebar without naming an element.
            if issue.auditType == .contrast, issue.element == nil, app.windows.firstMatch.frame.width > 700 {
                return true
            }
            // On a wide screen the system keyboard is a floating window the audit cannot place, so it reports the text
            // behind it and text it cannot match to an element.
            if app.windows.firstMatch.frame.width > 700, issue.element == nil {
                return true
            }
            // An element entirely outside the screen shows no pixels to measure.
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            // Text behind a bar is covered by the bar.
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
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
