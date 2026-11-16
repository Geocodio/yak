import XCTest

/// The quality-bar walkthrough of the Preview pane: one screenshot per state in `$YAK_SHOT_DIR`. Run it with
/// `bundle exec fastlane walkthrough only:PreviewWalkthroughTests`.
@MainActor final class PreviewWalkthroughTests: WalkthroughCase {
    private func show(_ scenario: String) throws {
        try start(scenario, paired: true)
        PreviewScreen(app: app).open()
    }

    private func card(_ kind: String) -> XCUIElement { app.descendants(matching: .any)["preview.card.\(kind)"] }

    private func shootCard(_ name: String, _ kind: String) throws {
        XCTAssertTrue(card(kind).waitForExistence(timeout: 15), name)
        try shoot(name, audits: false)
    }

    func testPreview() throws {
        try show("preview-ready")
        XCTAssertTrue(app.webViews.staticTexts["Path: /"].waitForExistence(timeout: 15))
        try shoot("preview", audits: false)
    }

    func testPreviewChecking() throws {
        try start("preview-slow", paired: true)
        PreviewScreen(app: app).open()
        XCTAssertTrue(card("checking").waitForExistence(timeout: 5))
        try shoot("preview-checking", audits: false)
        XCTAssertTrue(app.staticTexts["This is taking longer than usual"].waitForExistence(timeout: 12))
        try shoot("preview-checking-slow", audits: false)
    }

    func testPreviewWaking() throws {
        try show("preview-waking")
        try shootCard("preview-waking", "waking")
    }

    func testPreviewStarting() throws {
        try show("preview-starting")
        try shootCard("preview-starting", "waking")
    }

    func testPreviewWaiting() throws {
        try show("preview-waiting-for-slot")
        try shootCard("preview-waiting", "waiting")
    }

    func testPreviewFailed() throws {
        try show("preview-failing")
        try shootCard("preview-failed", "failing")
    }

    func testPreviewFixing() throws {
        try show("preview-fixing")
        try shootCard("preview-fixing", "setup-running")
    }

    func testPreviewNoManifest() throws {
        try show("preview-no-manifest")
        try shootCard("preview-no-manifest", "no-manifest")
    }

    func testPreviewSetupEnded() throws {
        try show("preview-setup-ended")
        try shootCard("preview-setup-ended", "setup-ended")
    }

    func testPreviewInvalidManifest() throws {
        try show("preview-invalid-manifest")
        try shootCard("preview-invalid-manifest", "invalid-manifest")
    }

    func testPreviewDestroyed() throws {
        try show("preview-destroyed")
        try shootCard("preview-destroyed", "destroyed")
    }

    func testPreviewReadOnly() throws {
        try show("preview-read-only")
        try shootCard("preview-read-only", "destroyed")
    }

    func testPreviewRestoring() throws {
        try show("preview-destroyed")
        let restore = app.buttons["preview.restore"]
        XCTAssertTrue(restore.waitForExistence(timeout: 15))
        restore.tap()
        try shootCard("preview-restoring", "restoring")
    }

    func testPreviewRestoreFailed() throws {
        try show("preview-restore-failed")
        try shootCard("preview-restore-failed", "restore-failed")
    }

    func testPreviewClosed() throws {
        try show("preview-closed")
        try shootCard("preview-closed", "closed")
    }

    func testPreviewLocalMachine() throws {
        try show("preview-local-machine")
        try shootCard("preview-local-machine", "local-machine")
    }

    func testPreviewUnknown() throws {
        try show("preview-unknown")
        try shootCard("preview-unknown", "unknown")
    }

    func testPreviewSignInFailed() throws {
        try show("preview-sign-in-refused")
        try shootCard("preview-sign-in-failed", "sign-in-failed")
    }

    func testPreviewPageDown() throws {
        try show("preview-page-down")
        try shootCard("preview-page-down", "problem")
    }

    func testPreviewOffline() throws {
        try show("preview-ready")
        XCTAssertTrue(app.webViews.staticTexts["Path: /"].waitForExistence(timeout: 15))
        try stub.setOnline(false)
        XCTAssertTrue(app.descendants(matching: .any)["preview.offline"].waitForExistence(timeout: 40))
        try shoot("preview-offline", audits: false)
    }

    func testPreviewThreadOffline() throws {
        try start("preview-ready", paired: true)
        PreviewScreen(app: app).waitForDrawer()
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
        XCTAssertTrue(app.buttons["pane.menu"].waitForExistence(timeout: 15))
        try stub.setOnline(false)
        PreviewScreen(app: app).open()
        XCTAssertTrue(card("problem").waitForExistence(timeout: 60))
        try shoot("preview-thread-offline", audits: false)
    }

    func testPreviewAddress() throws {
        try show("preview-ready")
        XCTAssertTrue(app.webViews.staticTexts["Path: /"].waitForExistence(timeout: 15))
        app.buttons["preview.address"].tap()
        XCTAssertTrue(app.textFields["preview.address.field"].waitForExistence(timeout: 5))
        try shoot("preview-address", audits: false)
    }

    func testPreviewLoading() throws {
        try show("preview-slow")
        try shoot("preview-loading", audits: false)
    }

    func testPreviewMenu() throws {
        try show("preview-ready")
        XCTAssertTrue(app.webViews.staticTexts["Path: /"].waitForExistence(timeout: 15))
        app.buttons["thread.menu"].tap()
        XCTAssertTrue(app.buttons["Copy address"].waitForExistence(timeout: 5))
        try shoot("preview-menu", audits: false)
    }

    func testPreviewActivity() throws {
        try show("preview-working")
        XCTAssertTrue(app.buttons["activity.strip"].waitForExistence(timeout: 10))
        try shoot("preview-activity", audits: false)
    }

    func testPreviewQuestionChip() throws {
        try show("preview-question")
        XCTAssertTrue(app.buttons["question.chip"].waitForExistence(timeout: 10))
        try shoot("preview-question-chip", audits: false)
    }
}
