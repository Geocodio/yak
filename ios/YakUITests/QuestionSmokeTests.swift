import XCTest

/// Smoke tests of the question card against the stub server's `question-open` and `question-closed` scenarios.
@MainActor final class QuestionSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func labelled(_ text: String) -> XCUIElement {
        app.descendants(matching: .any).matching(NSPredicate(format: "label CONTAINS %@", text)).firstMatch
    }

    /// Answers the first question with its first option, the second with both, and waits for the review.
    private func answerBothQuestions() {
        XCTAssertTrue(element("question.card").waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(app.staticTexts["Question 1 of 2"].waitForExistence(timeout: Wait.appear))
        app.buttons["question.option.0"].tap()
        XCTAssertTrue(app.staticTexts["Question 2 of 2"].waitForExistence(timeout: Wait.appear))
        app.buttons["question.option.0"].tap()
        app.buttons["question.option.1"].tap()
        let next = app.buttons["question.next"]
        XCTAssertTrue(next.isEnabled)
        next.tap()
        XCTAssertTrue(element("question.review").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(labelled("Mark the row as failed").exists)
        XCTAssertTrue(labelled("The owner, The channel").exists)
    }

    private func waitForGone(_ element: XCUIElement, timeout: TimeInterval = 10) {
        let gone = XCTNSPredicateExpectation(predicate: NSPredicate(format: "exists == false"), object: element)
        XCTAssertEqual(XCTWaiter().wait(for: [gone], timeout: timeout), .completed, element.identifier)
    }

    func testAnsweringATwoQuestionCardSendsAndRemovesTheCard() throws {
        try launch(scenario: "question-open")
        answerBothQuestions()
        try audit()
        app.buttons["question.send"].tap()
        waitForGone(element("question.card"))
        try audit()
    }

    func testARetryAfterAFailedSendKeepsTheAnswersAndSends() throws {
        try launch(scenario: "question-retry")
        answerBothQuestions()
        app.buttons["question.send"].tap()
        XCTAssertTrue(element("question.error").waitForExistence(timeout: Wait.appear))
        // The first send failed: the answers are still on the review, and the button now says Retry.
        XCTAssertTrue(labelled("Mark the row as failed").exists)
        XCTAssertTrue(app.buttons["Retry"].exists)
        app.buttons["question.send"].tap()
        waitForGone(element("question.card"))
        XCTAssertTrue(try stub.get("question/last-request").contains("Mark the row as failed"))
    }

    func testAClosedQuestionShowsTheClosedState() throws {
        try launch(scenario: "question-closed")
        answerBothQuestions()
        app.buttons["question.send"].tap()
        XCTAssertTrue(app.staticTexts["This question was closed."].waitForExistence(timeout: Wait.appear))
        // The answers stay in view until the card is dismissed.
        XCTAssertTrue(labelled("The owner, The channel").exists)
        XCTAssertTrue(try stub.get("question/last-request").contains("Mark the row as failed"))
        try audit()
        app.buttons["question.dismiss"].tap()
        waitForGone(element("question.card"))
    }

    private func audit() throws {
        let auditBars = AuditRules.Bars(app)
        try app.performAccessibilityAudit { [self, auditBars] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                // The system caps the size of text in its bars, and draws its glass under the threads badge.
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            // An element entirely outside the screen shows no pixels to measure.
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            // Text behind a bar is covered by the bar.
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
            let screenshot = XCTAttachment(screenshot: app.screenshot())
            screenshot.lifetime = .keepAlways
            screenshot.name = "audit-failure"
            add(screenshot)
            XCTFail(
                "audit: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "none")"
            )
            return true
        }
    }
}
