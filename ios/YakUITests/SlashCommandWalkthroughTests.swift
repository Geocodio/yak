import XCTest

/// The commands menu in every state, one screenshot each in `$YAK_SHOT_DIR`. Run it with
/// `bundle exec fastlane walkthrough only:SlashCommandWalkthroughTests/<test>`; the smoke tests hold the audits.
@MainActor final class SlashCommandWalkthroughTests: WalkthroughCase {
    private func openField(_ scenario: String) throws -> XCUIElement {
        try start(scenario, paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: Wait.appear) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: Wait.appear))
        field.tap()
        return field
    }

    func testTypedSlash() throws {
        try openField("slash-commands").typeText("/")
        XCTAssertTrue(app.descendants(matching: .any)["composer.slash"].waitForExistence(timeout: Wait.appear))
        try shoot("slash-typed", audits: false)
    }

    func testTypedFilter() throws {
        try openField("slash-commands").typeText("/re")
        XCTAssertTrue(
            app.descendants(matching: .any)["composer.slash.row.review-pr"].waitForExistence(timeout: Wait.appear))
        try shoot("slash-filtered", audits: false)
    }

    func testNoMatch() throws {
        try openField("slash-none").typeText("/xyz")
        XCTAssertTrue(app.descendants(matching: .any)["composer.slash.noMatch"].waitForExistence(timeout: Wait.appear))
        try shoot("slash-no-match", audits: false)
    }

    func testOlderServer() throws {
        try openField("slash-older").typeText("/")
        Thread.sleep(forTimeInterval: 1)
        XCTAssertFalse(app.descendants(matching: .any)["composer.slash"].exists)
        try shoot("slash-older-server", audits: false)
    }
}
