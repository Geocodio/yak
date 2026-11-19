import XCTest

/// The quality-bar walkthrough of the work shown on a thread: one screenshot per state in `$YAK_SHOT_DIR`. Run it with
/// `bundle exec fastlane walkthrough only:WorkWalkthroughTests`.
@MainActor final class WorkWalkthroughTests: WalkthroughCase {
    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func open(_ scenario: String) throws {
        try start(scenario, paired: true)
        if !app.otherElements["drawer"].exists, app.buttons["threads.button"].waitForExistence(timeout: 5) {
            app.buttons["threads.button"].tap()
        }
        let row = element("drawer.row.thread:d0e515ef")
        if row.waitForExistence(timeout: 60), !element("thread.title").exists { row.tap() }
        XCTAssertTrue(element("thread.title").waitForExistence(timeout: 60))
    }

    /// A thread opens with one pane; on a wide window the second one is the person's choice, made on the pane's item.
    private func openSecondPane(_ pane: String) throws {
        let row = app.buttons["pane.row.\(pane)"]
        let item = row.exists ? row : app.buttons["pane.strip.\(pane)"]
        XCTAssertTrue(item.waitForExistence(timeout: 30))
        item.press(forDuration: 1.2)
        let open = app.buttons["pane.second.open"]
        XCTAssertTrue(open.waitForExistence(timeout: 10))
        open.tap()
    }

    private func card(_ kind: String, _ scenario: String, name: String, audits: Bool = false) throws {
        try open(scenario)
        XCTAssertTrue(element("thread.card.\(kind)").waitForExistence(timeout: 60))
        try shoot(name, audits: audits)
    }

    func testStopped() throws { try card("stopped", "work-stopped", name: "work-stopped") }
    func testExpiredCoOwner() throws { try card("stopped", "work-expired-co-owner", name: "work-expired-co-owner") }
    func testCoOwnerAsked() throws { try card("co_owner", "work-co-owner-decide", name: "work-co-owner-asked") }
    func testCoOwnerNotLinked() throws { try card("co_owner", "work-co-owner-link", name: "work-co-owner-not-linked") }
    func testCoOwnerRequester() throws {
        try card("co_owner", "work-co-owner-requester", name: "work-co-owner-requester")
    }
    func testCoOwnerReader() throws { try card("co_owner", "work-co-owner-reader", name: "work-co-owner-reader") }
    func testEarlyWarning() throws { try card("early_warning", "work-early-warning", name: "work-early-warning") }
    func testRepositoryQuestion() throws {
        try card("repository_question", "work-repository-question", name: "work-repository-question")
    }

    func testRepositoryTakenElsewhere() throws {
        try card("repository_question", "work-repository-taken", name: "work-repository-taken-before")
        element("thread.card.repository.option.example/web").tap()
        XCTAssertTrue(element("thread.card.alreadyAnswered").waitForExistence(timeout: 30))
        try shoot("work-repository-taken", audits: false)
    }

    func testWaitingForSetup() throws { try card("stopped", "work-setup", name: "work-waiting-for-setup") }
    func testCancelled() throws { try card("stopped", "work-cancelled", name: "work-cancelled") }
    func testCoOwnerNoAccess() throws {
        try card("co_owner", "work-co-owner-no-access", name: "work-co-owner-no-access")
    }
    func testCoOwnerCheckFailed() throws {
        try card("co_owner", "work-co-owner-check-failed", name: "work-co-owner-check-failed")
    }

    func testRequestChangesSheet() throws {
        try card("co_owner", "work-co-owner-decide", name: "work-request-changes-before")
        app.buttons["task.decision.requestChanges"].tap()
        XCTAssertTrue(element("task.requestChanges.field").waitForExistence(timeout: 30))
        try shoot("work-request-changes", audits: false)
    }

    func testTagSheet() throws {
        try card("early_warning", "work-early-warning", name: "work-tag-before")
        element("thread.card.early.pick").tap()
        XCTAssertTrue(element("task.tag.person.4").waitForExistence(timeout: 30))
        try shoot("work-tag-sheet", audits: false)
    }

    /// Moving the thread opens the new one; the old one then offers it in a notice.
    func testMoved() throws {
        try open("work-move")
        app.buttons["thread.menu"].tap()
        XCTAssertTrue(app.buttons["thread.menu.work.move:example/web"].waitForExistence(timeout: 30))
        app.buttons["thread.menu.work.move:example/web"].tap()
        Thread.sleep(forTimeInterval: 4)
        try shoot("work-moved-new", audits: false)
        if app.buttons["threads.button"].exists { app.buttons["threads.button"].tap() }
        let old = element("drawer.row.thread:d0e515ef")
        if old.waitForExistence(timeout: 30) { old.tap() }
        XCTAssertTrue(element("notice.moved.open").waitForExistence(timeout: 30))
        try shoot("work-moved", audits: false)
    }

    func testStoppedRetryRefused() throws {
        try card("stopped", "work-stopped-retry-fails", name: "work-stopped-retry-fails-before")
        element("thread.card.stopped.retry").tap()
        XCTAssertTrue(element("thread.card.message").waitForExistence(timeout: 30))
        try shoot("work-stopped-failed", audits: false)
    }

    func testApproveInFlightAndSlow() throws {
        try card("co_owner", "work-slow-approve", name: "work-approve-before")
        app.buttons["task.decision.approve"].tap()
        XCTAssertTrue(element("task.decision.approving").waitForExistence(timeout: 30))
        try shoot("work-approve-sending", audits: false)
        XCTAssertTrue(element("thread.card.slow").waitForExistence(timeout: 30))
        try shoot("work-approve-slow", audits: false)
    }

    func testOffline() throws {
        try card("stopped", "work-offline", name: "work-offline-before")
        try stub.setOnline(false)
        XCTAssertTrue(element("thread.card.offline").waitForExistence(timeout: 30))
        try shoot("work-offline", audits: false)
        try stub.setOnline(true)
    }

    func testThreadMenu() throws {
        try open("work-move")
        app.buttons["thread.menu"].tap()
        XCTAssertTrue(app.buttons["thread.menu.work.move:example/web"].waitForExistence(timeout: 30))
        try shoot("work-menu", audits: false)
    }

    func testMilestones() throws {
        try open("work-milestones")
        XCTAssertTrue(element("thread.milestone.walkthrough_ready").waitForExistence(timeout: 60))
        try shoot("work-milestones", audits: false)
    }

    func testVia() throws {
        try open("work-via")
        XCTAssertTrue(element("chat.message.via").waitForExistence(timeout: 60))
        try shoot("work-via", audits: false)
    }

    func testLegacy() throws {
        try open("work-legacy")
        XCTAssertTrue(element("chat.legacyAnswer").waitForExistence(timeout: 60))
        Thread.sleep(forTimeInterval: 3)
        try shoot("work-legacy", audits: false)
    }

    func testOverviewRequest() throws {
        try open("work-stopped")
        element("thread.title").tap()
        XCTAssertTrue(element("overview.group.request").waitForExistence(timeout: 60))
        try shoot("work-overview-request", audits: false)
    }

    /// A stopped thread, Retry, and the work going on; the run is recorded by hand for the pull request.
    func testStoppedRetryWorking() throws {
        try open("work-stopped")
        XCTAssertTrue(element("thread.card.stopped").waitForExistence(timeout: 60))
        Thread.sleep(forTimeInterval: 3)
        element("thread.card.stopped.retry").tap()
        let gone = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "exists == false"), object: element("thread.card.stopped"))
        XCTAssertEqual(XCTWaiter().wait(for: [gone], timeout: 30), .completed)
        Thread.sleep(forTimeInterval: 4)
    }

    /// iPad: the card in Chat with the Overview at the side, then Chat hidden behind another pane with the chip.
    func testPadChatAndChip() throws {
        try XCTSkipUnless(UIDevice.current.userInterfaceIdiom == .pad, "The side panel is for a wide window")
        try card("co_owner", "work-co-owner-decide", name: "work-pad-chat-before")
        try openSecondPane("overview")
        XCTAssertTrue(element("overview.group.request").waitForExistence(timeout: 30))
        try shoot("work-pad-chat", audits: false)
    }

    func testPadPreviewChip() throws {
        try XCTSkipUnless(UIDevice.current.userInterfaceIdiom == .pad, "The chip is for a wide window")
        try card("co_owner", "work-co-owner-decide", name: "work-pad-chat-before")
        for pane in ["terminal", "files", "overview"] where !element("activity.needsYou").exists {
            let row = app.buttons["pane.row.\(pane)"]
            if row.exists { row.tap() }
            _ = element("activity.needsYou").waitForExistence(timeout: 6)
        }
        try shoot("work-pad-preview-chip", audits: false)
    }
}
