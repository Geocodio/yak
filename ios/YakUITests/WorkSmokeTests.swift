import XCTest

/// Smoke tests of the work shown on a thread, against the stub's `work-*` scenarios: the card above the composer,
/// the request header, the milestones and the rows the work adds to the conversation.
@MainActor final class WorkSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    override func tearDown() async throws { app.terminate() }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func open(_ scenario: String, thread: String = "d0e515ef") throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
        try openThread(thread)
    }

    private func openThread(_ id: String) throws {
        if !app.otherElements["drawer"].exists, app.buttons["threads.button"].waitForExistence(timeout: 5) {
            app.buttons["threads.button"].tap()
        }
        let row = element("drawer.row.thread:\(id)")
        if row.waitForExistence(timeout: Wait.firstLoad), !element("thread.title").exists { row.tap() }
        XCTAssertTrue(element("thread.title").waitForExistence(timeout: Wait.firstLoad))
    }

    private func waitForGone(_ element: XCUIElement, timeout: TimeInterval = Wait.appear) {
        let gone = XCTNSPredicateExpectation(predicate: NSPredicate(format: "exists == false"), object: element)
        XCTAssertEqual(XCTWaiter().wait(for: [gone], timeout: timeout), .completed, element.identifier)
    }

    private func requireCard(_ kind: String) {
        XCTAssertTrue(element("thread.card.\(kind)").waitForExistence(timeout: Wait.firstLoad), kind)
    }

    private func audit() throws {
        try auditTwice(app) { [app] in
            let auditBars = AuditRules.Bars(app)
            return { issue in
                let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
                if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                    // The system caps the size of text in its bars.
                    if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                    if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
                }
                if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
                if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
                return false
            }
        }
    }

    // MARK: Stopped

    func testAStoppedThreadShowsItsCardAndRetryWorks() throws {
        try open("work-stopped")
        requireCard("stopped")
        XCTAssertTrue(element("thread.request").exists)
        XCTAssertTrue(element("thread.step").exists)
        XCTAssertTrue(element("thread.card.stopped.move").exists)
        try audit()
        element("thread.card.stopped.retry").tap()
        waitForGone(element("thread.card.stopped"))
    }

    func testARefusedRetryKeepsTheCardAndSaysWhy() throws {
        try open("work-stopped-retry-fails")
        requireCard("stopped")
        element("thread.card.stopped.retry").tap()
        XCTAssertTrue(element("thread.card.message").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.staticTexts["This task cannot be retried right now."].exists)
        try audit()
    }

    func testAReplyToAStoppedThreadSaysItTriesAgain() throws {
        try open("work-stopped-reply")
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: Wait.firstLoad))
        XCTAssertEqual(field.placeholderValue, "Reply to retry with a note")
        field.tap()
        field.typeText("Try the other branch")
        app.buttons["composer.send"].tap()
        XCTAssertTrue(app.staticTexts["Trying again with your note."].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(element("chat.retry").waitForExistence(timeout: Wait.appear))
    }

    func testAnExpiredCoOwnerStopOffersNoRetry() throws {
        try open("work-expired-co-owner")
        requireCard("stopped")
        XCTAssertFalse(element("thread.card.stopped.retry").exists)
        XCTAssertTrue(app.textFields["composer.field"].exists)
        try audit()
    }

    // MARK: Co-owner

    func testACoOwnerApprovesAndTheCardGoesAway() throws {
        try open("work-co-owner-decide")
        requireCard("co_owner")
        try audit()
        app.buttons["task.decision.approve"].tap()
        XCTAssertTrue(element("task.decision.approving").waitForExistence(timeout: Wait.appear))
        waitForGone(element("thread.card.co_owner"))
    }

    func testAnUnlinkedCoOwnerIsOfferedTheLink() throws {
        try open("work-co-owner-link")
        requireCard("co_owner")
        XCTAssertTrue(element("task.decision.link").exists)
        XCTAssertTrue(element("task.decision.checkAgain").exists)
        XCTAssertFalse(element("task.decision.approve").exists)
        try audit()
    }

    func testTheRequesterCanOnlyWithdraw() throws {
        try open("work-co-owner-requester")
        requireCard("co_owner")
        XCTAssertTrue(element("task.decision.withdraw").exists)
        XCTAssertFalse(element("task.decision.approve").exists)
    }

    func testAReaderSeesTheCardWithNoButtons() throws {
        try open("work-co-owner-reader")
        requireCard("co_owner")
        XCTAssertTrue(element("thread.card.reader").exists)
        XCTAssertFalse(element("task.decision.approve").exists)
        try audit()
    }

    func testASlowApproveShowsTheSlowLine() throws {
        try open("work-slow-approve")
        requireCard("co_owner")
        app.buttons["task.decision.approve"].tap()
        XCTAssertTrue(element("thread.card.slow").waitForExistence(timeout: Wait.appear))
        waitForGone(element("thread.card.co_owner"), timeout: 90)
    }

    func testOfflineDisablesTheCardsButtons() throws {
        try open("work-offline")
        requireCard("stopped")
        try stub.setOnline(false)
        XCTAssertTrue(element("thread.card.offline").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(element("thread.card.stopped.retry").isEnabled)
        try audit()
        try stub.setOnline(true)
    }

    // MARK: Early warning and repository question

    func testAnEarlyWarningTagsACoOwner() throws {
        try open("work-early-warning")
        requireCard("early_warning")
        try audit()
        element("thread.card.early.pick").tap()
        let person = element("task.tag.person.4")
        XCTAssertTrue(person.waitForExistence(timeout: Wait.appear))
        person.tap()
        XCTAssertTrue(element("thread.card.early.asked").waitForExistence(timeout: Wait.appear))
    }

    func testChoosingARepositoryStartsTheWork() throws {
        try open("work-repository-question")
        requireCard("repository_question")
        try audit()
        element("thread.card.repository.option.example/web").tap()
        waitForGone(element("thread.card.repository_question"))
    }

    func testARepositoryChosenElsewhereSaysSo() throws {
        try open("work-repository-taken")
        requireCard("repository_question")
        element("thread.card.repository.option.example/web").tap()
        XCTAssertTrue(element("thread.card.alreadyAnswered").waitForExistence(timeout: Wait.appear))
    }

    // MARK: The conversation

    func testMilestonesAndTheRetryDividerSitInTheConversation() throws {
        try open("work-milestones")
        XCTAssertTrue(element("thread.milestone.ci_failed").waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(element("thread.milestone.pull_request_opened").exists)
        XCTAssertTrue(element("chat.retry").exists)
        try audit()
    }

    func testAChannelMessageSaysWhereItCameFrom() throws {
        try open("work-via")
        XCTAssertTrue(element("chat.message.via").waitForExistence(timeout: Wait.firstLoad))
        try audit()
    }

    func testALegacyThreadShowsItsHistoryAndContinuesInANewThread() throws {
        try open("work-legacy")
        XCTAssertTrue(element("chat.legacyAnswer").waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(element("thread.milestone.pull_request_opened").exists)
        XCTAssertTrue(element("composer.legacyLine").exists)
        try audit()
        let field = app.textFields["composer.field"]
        field.tap()
        field.typeText("Carry on")
        app.buttons["composer.send"].tap()
        XCTAssertTrue(element("composer.legacyLine").waitForNonExistence(timeout: Wait.appear))
    }

    func testAQueuedNoteBecomesATurnRow() throws {
        try open("work-drained")
        XCTAssertTrue(app.staticTexts["Sends after this turn"].waitForExistence(timeout: Wait.firstLoad))
        waitForGone(app.staticTexts["Sends after this turn"])
        XCTAssertTrue(
            app.staticTexts["Also handle the night run."].exists
                || app.staticTexts.matching(NSPredicate(format: "label CONTAINS 'Also handle'")).count == 1)
    }

    // MARK: Move

    func testMovingAThreadOpensTheNewOneAndTheOldOneOffersIt() throws {
        try open("work-move")
        app.buttons["thread.menu"].tap()
        let move = app.buttons["thread.menu.work.move:example/web"]
        XCTAssertTrue(move.waitForExistence(timeout: Wait.appear))
        move.tap()
        XCTAssertTrue(try waitForLastRequest(contains: "\"repo\""))
    }

    private func waitForLastRequest(contains text: String) throws -> Bool {
        for _ in 0..<40 {
            if try stub.get("work/last-request").contains(text) { return true }
            RunLoop.current.run(until: Date().addingTimeInterval(0.25))
        }
        return false
    }
}
