import XCTest

/// The quality-bar walkthrough of the thread question card: one screenshot per state in `$YAK_SHOT_DIR`, each
/// followed by the accessibility audit. Run it with `bundle exec fastlane walkthrough only:QuestionWalkthroughTests`.
@MainActor final class QuestionWalkthroughTests: WalkthroughCase {
    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func open(_ scenario: String) throws {
        try start(scenario, paired: true)
        XCTAssertTrue(element("question.card").waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(app.staticTexts["Question 1 of 2"].waitForExistence(timeout: Wait.appear))
    }

    /// Picks the first option, then both options of the second question, and waits for the review.
    private func answerBoth() {
        app.buttons["question.option.0"].tap()
        XCTAssertTrue(app.staticTexts["Question 2 of 2"].waitForExistence(timeout: Wait.appear))
        app.buttons["question.option.0"].tap()
        app.buttons["question.option.1"].tap()
        app.buttons["question.next"].tap()
        XCTAssertTrue(element("question.review").waitForExistence(timeout: Wait.appear))
    }

    func testQuestionFirst() throws {
        try open("question-open")
        try shoot("question-first")
    }

    func testQuestionMultiSelect() throws {
        try open("question-open")
        app.buttons["question.option.0"].tap()
        XCTAssertTrue(app.staticTexts["Question 2 of 2"].waitForExistence(timeout: Wait.appear))
        app.buttons["question.option.0"].tap()
        app.buttons["question.option.1"].tap()
        try shoot("question-multi-select")
    }

    func testQuestionTyping() throws {
        try open("question-open")
        app.buttons["question.typeAnswer"].tap()
        let field =
            app.textViews["question.field"].exists ? app.textViews["question.field"] : app.textFields["question.field"]
        XCTAssertTrue(field.waitForExistence(timeout: Wait.appear))
        field.typeText("Mark it failed and tell the on-call person")
        XCTAssertTrue(app.keyboards.firstMatch.waitForExistence(timeout: Wait.appear))
        try shoot("question-typing")
    }

    func testQuestionReview() throws {
        try open("question-open")
        answerBoth()
        try shoot("question-review")
    }

    func testQuestionSendFailed() throws {
        try open("question-retry")
        answerBoth()
        app.buttons["question.send"].tap()
        XCTAssertTrue(element("question.error").waitForExistence(timeout: Wait.appear))
        try shoot("question-send-failed")
    }

    func testQuestionClosed() throws {
        try open("question-closed")
        answerBoth()
        app.buttons["question.send"].tap()
        XCTAssertTrue(element("question.closed").waitForExistence(timeout: Wait.appear))
        try shoot("question-closed")
    }
}
