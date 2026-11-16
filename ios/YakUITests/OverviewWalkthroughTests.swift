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
        try shootOverview("overview-slow")
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
        // A sheet covers the page; the audit cannot judge text under its dimming.
        try shoot("overview-setup-output", audits: false)
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
        try shootOverview("overview-messages")
    }

    func testActionRefused() throws {
        try openOverview("overview-refusals")
        try chooseFromMenu(of: "q7adapt0", "Drop it")
        app.buttons["Drop it"].firstMatch.tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview.actionLine"].waitForExistence(timeout: 10))
        try shootOverview("overview-refused", auditFirst: true)
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

    private func openAddSheet(_ scenario: String) throws {
        try openOverview(scenario)
        let add = app.buttons["overview.add"]
        XCTAssertTrue(add.waitForExistence(timeout: 15))
        for _ in 0..<6 where !add.isHittable { app.descendants(matching: .any)["overview"].swipeUp() }
        add.tap()
        XCTAssertTrue(app.textFields["addSubThread.name"].waitForExistence(timeout: 10))
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
        try shoot("drawer-child-swipe", audits: false)
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
        try app.performAccessibilityAudit { [app] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            // A disabled control is dimmed on purpose, as in the other walkthroughs.
            if issue.auditType == .contrast, issue.element?.debugDescription.contains("Disabled") == true {
                return true
            }
            if issue.auditType == .contrast, issue.element == nil, app.windows.firstMatch.frame.width > 700 {
                return true
            }
            XCTFail("audit [\(name)]: \(issue.compactDescription) | \(issue.element?.debugDescription ?? "no element")")
            return true
        }
    }
}
