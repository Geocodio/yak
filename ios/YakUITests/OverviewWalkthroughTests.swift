import XCTest

/// The quality-bar walkthrough of the Overview: one screenshot per state in `$YAK_SHOT_DIR`. Run it with
/// `bundle exec fastlane walkthrough only:OverviewWalkthroughTests`.
@MainActor final class OverviewWalkthroughTests: WalkthroughCase {
    func testOverview() throws {
        try openOverview("overview-stack")
        XCTAssertTrue(app.staticTexts["Sandbox running"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.descendants(matching: .any)["overview.copyBranch"].waitForExistence(timeout: 15))
        try shootOverview("overview")
    }

    func testOverviewLoading() throws {
        try openOverview("overview-slow")
        XCTAssertTrue(app.descendants(matching: .any)["overview.skeleton"].waitForExistence(timeout: 5))
        try shootOverview("overview-loading")
        XCTAssertTrue(app.descendants(matching: .any)["overview.slow"].waitForExistence(timeout: 12))
        // The late answers arrive a few seconds after the slow notice and change the page while an audit runs, so the
        // audit waits for them and covers the settled page.
        XCTAssertTrue(app.staticTexts["Pull request"].waitForExistence(timeout: 30))
        pause(1)
        // The page is taller than the screen; its end is shown, then lifted a little so no heading sits at the bar's edge.
        scrollPage(toEnd: true)
        let page = app.descendants(matching: .any)["overview"]
        page.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.4))
            .press(forDuration: 0.1, thenDragTo: page.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.5)))
        try shootOverview("overview-slow-answered")
    }

    func testOverviewEmpty() throws {
        try openOverview("overview-new-thread")
        XCTAssertTrue(app.staticTexts["Links appear once Yak creates a branch."].waitForExistence(timeout: 15))
        XCTAssertFalse(app.descendants(matching: .any)["overview.group.subThreads"].exists)
        try shootOverview("overview-empty")
    }

    func testOverviewRepositoryFailed() throws {
        try openOverview("overview-no-sub-threads")
        XCTAssertTrue(app.buttons["overview.retry.22"].waitForExistence(timeout: 15))
        try shootOverview("overview-repository-failed")
    }

    func testOverviewSettingUp() throws {
        try openOverview("overview-setting-up")
        XCTAssertTrue(app.staticTexts["example/web"].waitForExistence(timeout: 15))
        try shootOverview("overview-setting-up")
    }

    func testOverviewError() throws {
        try openOverview("overview-broken")
        XCTAssertTrue(app.descendants(matching: .any)["overview.error"].firstMatch.waitForExistence(timeout: 20))
        try shootOverview("overview-error")
    }

    func testOverviewOffline() throws {
        try openOverview("overview-stack")
        XCTAssertTrue(app.descendants(matching: .any)["overview.copyBranch"].waitForExistence(timeout: 15))
        try stub.setOnline(false)
        XCTAssertTrue(app.descendants(matching: .any)["overview.offline"].waitForExistence(timeout: 40))
        try shootOverview("overview-offline")
    }

    func testOverviewSetupOutput() throws {
        try openOverview("overview-no-sub-threads")
        let row = app.buttons["overview.repository.22"]
        XCTAssertTrue(row.waitForExistence(timeout: 15))
        row.tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview.setupOutput.text"].waitForExistence(timeout: 10))
        try shootOverview("overview-setup-output")
    }

    func testOverviewRecipient() throws {
        try openOverview("overview-recipient")
        XCTAssertTrue(app.descendants(matching: .any)["overview.visibility"].waitForExistence(timeout: 15))
        XCTAssertFalse(app.buttons["overview.share"].exists)
        try shootOverview("overview-recipient")
    }

    // MARK: Sub-threads

    func testSubThreadsGroup() throws {
        try openOverview("overview-stack")
        XCTAssertTrue(app.descendants(matching: .any)["overview.child.q7adapt0"].waitForExistence(timeout: 15))
        try shootOverview("overview-subthreads")
    }

    func testSubThreadMenu() throws {
        try openOverview("overview-stack")
        let menu = app.buttons["overview.child.q7adapt0.menu"]
        XCTAssertTrue(menu.waitForExistence(timeout: 15))
        menu.tap()
        XCTAssertTrue(app.buttons["Drop it"].waitForExistence(timeout: 5))
        // The menu is the system's own surface; the audit cannot judge text on it.
        try shoot("overview-child-menu", audits: false)
    }

    func testDropConfirmation() throws {
        try openOverview("overview-stack")
        try chooseFromMenu(of: "q7adapt0", "Drop it")
        XCTAssertTrue(app.staticTexts["The sub-thread closes and its work is not used."].waitForExistence(timeout: 5))
        try shoot("overview-drop-confirm", audits: false)
    }

    func testStopAllConfirmation() throws {
        try openOverview("overview-stack")
        try scrollToStopAll().tap()
        XCTAssertTrue(app.staticTexts["Stop all sub-threads?"].waitForExistence(timeout: 5))
        try shoot("overview-stop-confirm", audits: false)
    }

    func testStoppedStack() throws {
        try openOverview("overview-stopped")
        XCTAssertTrue(
            app.staticTexts["This stack was stopped, so it cannot start sub-threads."].waitForExistence(timeout: 15))
        try shootOverview("overview-stopped")
    }

    func testFullStack() throws {
        try openOverview("overview-full")
        XCTAssertTrue(app.descendants(matching: .any)["overview.addRefusal"].waitForExistence(timeout: 15))
        try shootOverview("overview-full")
    }

    func testChildOverview() throws {
        try openOverview("overview-stack")
        let child = app.descendants(matching: .any)["overview.child.b4ckf111"]
        XCTAssertTrue(child.waitForExistence(timeout: 15))
        child.tap()
        XCTAssertTrue(app.descendants(matching: .any)["thread.title"].waitForExistence(timeout: 15))
        app.descendants(matching: .any)["thread.title"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview.parent"].waitForExistence(timeout: 15))
        try shootOverview("overview-child")
    }

    func testSubThreadMessages() throws {
        try openOverview("overview-stack")
        let folded = app.buttons["overview.messages"]
        XCTAssertTrue(folded.waitForExistence(timeout: 15))
        for _ in 0..<6 where !folded.isHittable { app.descendants(matching: .any)["overview"].swipeUp() }
        folded.tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview.message.51"].waitForExistence(timeout: 5))
        // The page is taller than the screen; the lower groups are brought up so the audit sees them on screen.
        scrollPage(toEnd: true)
        try shootOverview("overview-messages")
    }

    func testActionRefused() throws {
        try openOverview("overview-refusals")
        try chooseFromMenu(of: "q7adapt0", "Drop it")
        app.buttons["Drop it"].firstMatch.tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview.actionLine"].waitForExistence(timeout: 10))
        // The audit runs once the dialog's dimming is gone.
        XCTAssertTrue(app.buttons["Drop it"].firstMatch.waitForNonExistence(timeout: 5))
        pause(1)
        // The lower groups are brought up for the audit and put back for the screenshot.
        scrollPage(toEnd: true)
        try auditOverview("overview-refused")
        scrollPage(toEnd: false)
        try shoot("overview-refused", audits: false)
    }

    func testActionBusy() throws {
        try openOverview("overview-busy")
        try chooseFromMenu(of: "q7adapt0", "Take over")
        Thread.sleep(forTimeInterval: 1)
        try shootOverview("overview-busy")
    }

    // MARK: Add a sub-thread

    func testAddSubThreadSheet() throws {
        try openAddSheet("overview-stack")
        XCTAssertTrue(app.textFields["addSubThread.name"].exists)
        try shoot("add-sub-thread")
    }

    func testAddSubThreadProblems() throws {
        try openAddSheet("overview-stack")
        app.buttons["addSubThread.add"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["addSubThread.name.error"].waitForExistence(timeout: 5))
        try shoot("add-sub-thread-problems")
    }

    func testAddSubThreadRefused() throws {
        try openAddSheet("overview-refusals")
        let name = app.textFields["addSubThread.name"]
        name.tap()
        name.typeText("check-the-cache")
        let brief = app.textFields["addSubThread.brief"]
        brief.tap()
        brief.typeText("Look at the cache keys.")
        app.buttons["addSubThread.add"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["addSubThread.problem"].waitForExistence(timeout: 10))
        try shoot("add-sub-thread-refused")
    }

    func testAddSubThreadKeyboard() throws {
        try openAddSheet("overview-stack")
        XCTAssertTrue(app.keyboards.firstMatch.waitForExistence(timeout: 10))
        try shoot("add-sub-thread-keyboard")
    }

    /// A full stack disables the Overview's Add, so the sheet opens from the top menu and shows the server's sentence.
    func testAddSubThreadFull() throws {
        try openOverview("overview-full")
        let add = app.buttons["overview.addSubThread"]
        XCTAssertTrue(add.waitForExistence(timeout: 15))
        XCTAssertFalse(add.isEnabled)
        app.buttons["thread.menu"].tap()
        let item = app.buttons["thread.menu.addSubThread"]
        XCTAssertTrue(item.waitForExistence(timeout: 5))
        item.tap()
        XCTAssertTrue(app.descendants(matching: .any)["addSubThread.refused"].waitForExistence(timeout: 15))
        try shoot("add-sub-thread-full")
    }

    func testAddSubThreadOffline() throws {
        try openOverview("overview-stack")
        XCTAssertTrue(app.descendants(matching: .any)["overview.copyBranch"].waitForExistence(timeout: 15))
        try stub.setOnline(false)
        // The sheet reads a stack the app fetched in the last ten seconds as it is, so it waits for the offline line.
        XCTAssertTrue(app.descendants(matching: .any)["overview.offline"].waitForExistence(timeout: 40))
        let add = app.buttons["overview.addSubThread"]
        for _ in 0..<6 where !add.isHittable { app.descendants(matching: .any)["overview"].swipeUp() }
        add.tap()
        XCTAssertTrue(app.descendants(matching: .any)["addSubThread.offline"].waitForExistence(timeout: 20))
        XCTAssertTrue(app.buttons["addSubThread.add"].isEnabled)
        try shoot("add-sub-thread-offline")
    }

    func testTopMenuWithSubThread() throws {
        try openOverview("overview-stack")
        app.buttons["thread.menu"].tap()
        XCTAssertTrue(app.buttons["thread.menu.addSubThread"].waitForExistence(timeout: 5))
        try shoot("thread-menu-sub-thread", audits: false)
    }

    func testPlusMenuWithSubThread() throws {
        try start("overview-stack", paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:s7ackr00"]
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
        let plus = app.buttons["composer.plus"]
        XCTAssertTrue(plus.waitForExistence(timeout: 15))
        plus.tap()
        XCTAssertTrue(app.buttons["composer.plusMenu.addSubThread"].waitForExistence(timeout: 5))
        try shoot("plus-menu-sub-thread")
    }

    private func openAddSheet(_ scenario: String) throws {
        try openOverview(scenario)
        let add = app.buttons["overview.addSubThread"]
        XCTAssertTrue(add.waitForExistence(timeout: 15))
        for _ in 0..<6 where !add.isHittable { app.descendants(matching: .any)["overview"].swipeUp() }
        add.tap()
        XCTAssertTrue(app.textFields["addSubThread.name"].waitForExistence(timeout: 10))
    }

    // MARK: Side panel

    func testOverviewSidePanel() throws {
        try openPanel("overview-stack")
        if app.descendants(matching: .any)["overview.panel"].exists {
            XCTAssertTrue(app.buttons["overview.panel.close"].exists)
        }
        XCTAssertTrue(app.descendants(matching: .any)["overview.copyBranch"].waitForExistence(timeout: 15))
        try shootOverview("overview-panel")
    }

    func testOverviewPanelOnDuo() throws {
        try openPanel("overview-stack")
        XCTAssertTrue(app.descendants(matching: .any)["overview.copyBranch"].waitForExistence(timeout: 15))
        try shootOverview("overview-panel-duo")
    }

    /// Below 760 points the Overview is the pane it always was; the real narrow window is Split View on the iPad,
    /// checked by hand, so here the width the device gives decides what is asserted.
    func testOverviewPanelBecomesAPane() throws {
        try start("overview-stack", paired: true)
        try openRootThread()
        let isWide = app.windows.firstMatch.frame.width >= 760
        openOverviewFromTitle()
        let identifier = isWide ? "overview.panel" : "overview"
        XCTAssertTrue(app.descendants(matching: .any)[identifier].waitForExistence(timeout: 10))
        try shootOverview("overview-panel-narrow")
    }

    private func openRootThread() throws {
        if !app.otherElements["drawer"].waitForExistence(timeout: 10), app.buttons["threads.button"].exists {
            app.buttons["threads.button"].tap()
        }
        let row = app.descendants(matching: .any)["drawer.row.thread:s7ackr00"]
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
    }

    private func openOverviewFromTitle() {
        let title = app.descendants(matching: .any)["thread.title"]
        if title.waitForExistence(timeout: 15) { title.tap() } else { app.typeKey("i", modifierFlags: .command) }
    }

    private func openPanel(_ scenario: String) throws {
        try start(scenario, paired: true)
        try openRootThread()
        openOverviewFromTitle()
        // The panel needs 760 points beside the conversation; a window narrower than that shows the pane instead.
        let panel = app.descendants(matching: .any)["overview.panel"]
        let pane = app.descendants(matching: .any)["overview"]
        XCTAssertTrue(panel.waitForExistence(timeout: 10) || pane.waitForExistence(timeout: 2))
    }

    // MARK: Share sheet

    func testShareSheet() throws {
        try openShare("overview-private")
        try shoot("share")
    }

    func testSharePeople() throws {
        try openShare("overview-private")
        XCTAssertTrue(app.buttons["share.person.7"].waitForExistence(timeout: 5))
        try shoot("share-people")
    }

    func testShareNoOne() throws {
        try openShare("overview-private")
        let second = app.buttons["share.person.7"]
        XCTAssertTrue(second.waitForExistence(timeout: 5))
        second.tap()
        XCTAssertTrue(app.descendants(matching: .any)["share.pickSomeone"].waitForExistence(timeout: 5))
        try shoot("share-pick-someone")
    }

    func testShareLoading() throws {
        try openShare("overview-slow", waitsForForm: false)
        XCTAssertTrue(app.descendants(matching: .any)["share.loading"].waitForExistence(timeout: 5))
        try shoot("share-loading")
    }

    func testShareError() throws {
        try openShare("overview-broken", waitsForForm: false)
        XCTAssertTrue(app.descendants(matching: .any)["share.error"].waitForExistence(timeout: 30))
        try shoot("share-error")
    }

    func testShareSaving() throws {
        try openShare("overview-busy")
        app.buttons["share.mode.everyone"].tap()
        app.buttons["share.save"].tap()
        // The first answers are the server's busy answer, so the sheet stays while the app retries.
        pause(1)
        try shootOverview("share-saving")
    }

    private func openShare(_ scenario: String, waitsForForm: Bool = true) throws {
        try openOverview(scenario)
        let change = app.buttons["overview.share"]
        XCTAssertTrue(change.waitForExistence(timeout: 20))
        // A slow scenario adds rows above the button when its answers arrive, so a tap that missed is tried again.
        let sheet = app.descendants(matching: .any)["share.sheet"]
        for _ in 0..<3 where !sheet.exists {
            // The bar's Share button needs no scrolling, which leaves the page behind the sheet where it was.
            let barButton = app.buttons["overview.shareButton"]
            if barButton.exists {
                barButton.tap()
            } else {
                for _ in 0..<6 where !change.isHittable { app.descendants(matching: .any)["overview"].swipeUp() }
                change.tap()
            }
            _ = sheet.waitForExistence(timeout: 4)
        }
        XCTAssertTrue(sheet.exists)
        if waitsForForm { XCTAssertTrue(app.buttons["share.mode.everyone"].waitForExistence(timeout: 15)) }
    }

    // MARK: Drawer sub-threads

    func testDrawerChildMenu() throws {
        try openDrawerChildren("overview-stack")
        let failed = app.descendants(matching: .any)["drawer.row.thread:q7adapt0"]
        pause(2)
        failed.press(forDuration: 1.0)
        XCTAssertTrue(app.buttons["Drop it"].waitForExistence(timeout: 5))
        // The menu is the system's own surface; the audit cannot judge text on it.
        try shoot("drawer-child-menu", audits: false)
    }

    func testDrawerChildMenuLoading() throws {
        try openDrawerChildren("overview-slow")
        let failed = app.descendants(matching: .any)["drawer.row.thread:q7adapt0"]
        failed.press(forDuration: 1.0)
        XCTAssertTrue(app.buttons["Checking what this sub-thread allows"].waitForExistence(timeout: 5))
        try shoot("drawer-child-menu-loading", audits: false)
    }

    func testDrawerChildSwipe() throws {
        try openDrawerChildren("overview-stack")
        pause(2)
        // A short drag from inside the row reveals the actions; a fast swipe would close the drawer.
        let row = app.descendants(matching: .any)["drawer.row.thread:q7adapt0"]
        row.coordinate(withNormalizedOffset: CGVector(dx: 0.85, dy: 0.5))
            .press(forDuration: 0.1, thenDragTo: row.coordinate(withNormalizedOffset: CGVector(dx: 0.6, dy: 0.5)))
        XCTAssertTrue(app.buttons["Drop it"].waitForExistence(timeout: 5))
        try shootOverview("drawer-child-swipe")
    }

    func testDrawerChildSteps() throws {
        try openDrawerChildren("overview-stack")
        XCTAssertTrue(app.staticTexts["Writing the backfill script"].exists)
        try shoot("drawer-child-steps")
    }

    private func openDrawerChildren(_ scenario: String) throws {
        try start(scenario, paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let failed = app.descendants(matching: .any)["drawer.row.thread:q7adapt0"]
        if !failed.waitForExistence(timeout: 10) {
            let toggle = app.buttons["drawer.toggle.thread:s7ackr00"]
            XCTAssertTrue(toggle.waitForExistence(timeout: 10))
            toggle.tap()
        }
        XCTAssertTrue(failed.waitForExistence(timeout: 10))
    }

    /// Scrolls the page to its end, where every row is above the floating bar, or back to its top.
    private func scrollPage(toEnd: Bool) {
        let page = app.descendants(matching: .any)["overview"]
        for _ in 0..<4 { toEnd ? page.swipeUp(velocity: .fast) : page.swipeDown(velocity: .fast) }
    }

    private func pause(_ seconds: TimeInterval) {
        let settle = XCTestExpectation(description: "pause")
        settle.isInverted = true
        wait(for: [settle], timeout: seconds)
    }

    // MARK: Helpers

    private func chooseFromMenu(of childID: String, _ item: String) throws {
        let menu = app.buttons["overview.child.\(childID).menu"]
        XCTAssertTrue(menu.waitForExistence(timeout: 15))
        menu.tap()
        // A row with a subtitle reads "Take over, You drive it".
        let button = app.buttons.matching(NSPredicate(format: "label BEGINSWITH %@", item)).firstMatch
        XCTAssertTrue(button.waitForExistence(timeout: 5))
        button.tap()
    }

    private func scrollToStopAll() throws -> XCUIElement {
        let stop = app.buttons["overview.stopAll"]
        XCTAssertTrue(stop.waitForExistence(timeout: 15))
        for _ in 0..<6 where !stop.isHittable { app.descendants(matching: .any)["overview"].swipeUp() }
        return stop
    }

    /// Plays the scenario, opens the root thread from the drawer and the Overview from the title.
    private func openOverview(_ scenario: String) throws {
        try start(scenario, paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:s7ackr00"]
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
        let title = app.descendants(matching: .any)["thread.title"]
        if title.waitForExistence(timeout: 15) {
            title.tap()
        } else {
            // The short landscape layout hides the title; Command I opens the Overview.
            app.typeKey("i", modifierFlags: .command)
        }
        XCTAssertTrue(app.descendants(matching: .any)["overview"].waitForExistence(timeout: 10))
    }

    /// A screenshot, then the audit without the findings about text in the navigation bar, whose size the system caps.
    /// `auditFirst` audits before the screenshot, for a line that leaves by itself after six seconds.
    private func shootOverview(_ name: String, auditFirst: Bool = false) throws {
        if auditFirst { try auditOverview(name) }
        try shoot(name, audits: false)
        if !auditFirst { try auditOverview(name) }
    }

    private func auditOverview(_ name: String) throws {
        let auditBars = AuditRules.Bars(app)
        // Read before the audit starts, because the audit removes elements from the tree while it runs.
        // A bottom toolbar can have a frame that covers the content, so a toolbar only counts when it leaves the
        // centre of the window free.
        let centre = CGPoint(x: auditBars.window.midX, y: auditBars.window.midY)
        let barFrames = (
            app.navigationBars.allElementsBoundByIndex
                + app.toolbars.allElementsBoundByIndex.filter { !$0.frame.contains(centre) }
        ).map(\.frame)
        try app.performAccessibilityAudit { [self, auditBars] issue in
            // The system caps the size of text in its bars.
            if let element = issue.element, barFrames.contains(where: { $0.contains(element.frame) }),
                [.dynamicType, .textClipped].contains(issue.auditType)
            {
                return true
            }
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
            if let directory = self.environment["YAK_SHOT_DIR"] {
                let file = URL(fileURLWithPath: "\(directory)/\(name)-audit.png")
                try? self.upright(XCUIScreen.main.screenshot()).write(to: file)
            }
            XCTFail("audit [\(name)]: \(issue.compactDescription) | \(issue.element?.debugDescription ?? "no element")")
            return true
        }
    }
}
