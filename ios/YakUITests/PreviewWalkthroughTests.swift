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
        try shootPreview(name)
    }

    /// The shot, then an audit with the narrow rules the thread screen uses; the page's own content is the page's.
    private func shootPreview(_ name: String) throws {
        try shoot(name, audits: false)
        let keyboard = keyboardFrame()
        let auditBars = AuditRules.Bars(app)
        let page = AuditRules.Page(app)
        try app.performAccessibilityAudit { [app, auditBars, page, keyboard] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                // The system caps the size of text in its bars.
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
            }
            if AuditRules.isInsidePage(issue, page: page) { return true }
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
            if AuditRules.isContrastBehindTheKeyboard(issue, keyboard: keyboard) { return true }
            // The system keyboard's prediction bar is not ours to label.
            if (issue.compactDescription + issue.detailedDescription).contains("TUIPredictionViewCell") { return true }
            XCTFail(
                "audit [\(name)]: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "no element")"
            )
            return true
        }
    }

    /// Turns the device and turns it back when the test ends, so the next case starts upright.
    private func turnToLandscape() {
        XCUIDevice.shared.orientation = .landscapeLeft
        addTeardownBlock { XCUIDevice.shared.orientation = .portrait }
    }

    func testPreview() throws {
        try show("preview-ready")
        XCTAssertTrue(app.webViews.staticTexts["Path: /"].waitForExistence(timeout: 15))
        // A wide window shows the system's pane strip or the pane row, and the preview's bar row.
        let isWide = app.windows.firstMatch.frame.width > 700
        if isWide {
            XCTAssertTrue(
                app.buttons["pane.strip.preview"].exists || app.buttons["pane.row.preview"].exists
                    || app.buttons["pane.menu"].exists)
        }
        XCTAssertTrue(app.buttons["preview.address"].exists)
        XCTAssertTrue(app.buttons["preview.reload"].exists)
        try shootPreview("preview")
    }

    func testPreviewLandscape() throws {
        turnToLandscape()
        try show("preview-ready")
        XCTAssertTrue(app.webViews.staticTexts["Path: /"].waitForExistence(timeout: 45))
        XCTAssertTrue(app.descendants(matching: .any)["thread.strip"].exists)
        XCTAssertTrue(app.buttons["preview.reload"].exists)
        XCTAssertTrue(app.buttons["preview.address"].exists)
        try shootPreview("preview-landscape")
    }

    func testPreviewActivityLandscape() throws {
        turnToLandscape()
        try show("preview-working")
        XCTAssertTrue(app.buttons["activity.strip"].waitForExistence(timeout: 10))
        try shootPreview("preview-activity-landscape")
    }

    func testPreviewQuestionChipLandscape() throws {
        turnToLandscape()
        try show("preview-question")
        XCTAssertTrue(app.buttons["question.chip"].waitForExistence(timeout: 10))
        try shootPreview("preview-question-chip-landscape")
    }

    func testPreviewChecking() throws {
        try start("preview-slow", paired: true)
        PreviewScreen(app: app).open()
        XCTAssertTrue(card("checking").waitForExistence(timeout: 5))
        try shootPreview("preview-checking")
        XCTAssertTrue(app.staticTexts["This is taking longer than usual"].waitForExistence(timeout: 12))
        try shootPreview("preview-checking-slow")
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
        // At the largest text size the button starts under the bottom bar; scroll the card until it clears the bar.
        let bar = app.buttons["preview.address"]
        for _ in 0..<4 where bar.exists && restore.frame.maxY > bar.frame.minY { card("destroyed").swipeUp() }
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
        try shootPreview("preview-offline")
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
        try shootPreview("preview-thread-offline")
    }

    func testPreviewAddress() throws {
        try show("preview-ready")
        XCTAssertTrue(app.webViews.staticTexts["Path: /"].waitForExistence(timeout: 15))
        app.buttons["preview.address"].tap()
        XCTAssertTrue(app.textFields["preview.address.field"].waitForExistence(timeout: 5))
        try shootPreview("preview-address")
    }

    func testPreviewLoading() throws {
        try show("preview-slow")
        try shootPreview("preview-loading")
    }

    func testPreviewMenu() throws {
        try show("preview-ready")
        XCTAssertTrue(app.webViews.staticTexts["Path: /"].waitForExistence(timeout: 15))
        app.buttons["thread.menu"].tap()
        XCTAssertTrue(app.buttons["Copy address"].waitForExistence(timeout: 5))
        // The menu is the system's own surface; the audit cannot judge text on it.
        try shoot("preview-menu", audits: false)
    }

    func testPreviewActivity() throws {
        try show("preview-working")
        XCTAssertTrue(app.buttons["activity.strip"].waitForExistence(timeout: 10))
        try shootPreview("preview-activity")
    }

    func testPreviewQuestionChip() throws {
        try show("preview-question")
        XCTAssertTrue(app.buttons["question.chip"].waitForExistence(timeout: 10))
        try shootPreview("preview-question-chip")
    }
}
