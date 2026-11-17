import XCTest

/// The quality-bar walkthrough of the lock, Settings rows, tips and debug menu: one screenshot per state in
/// `$YAK_SHOT_DIR`, as in `ThreadWalkthroughTests`.
@MainActor final class SettingsPrivacyWalkthroughTests: WalkthroughCase {
    private func startLocked(biometry: String = "faceID", authentication: String, extra: [String] = []) throws {
        try start(
            "paired", paired: true,
            extraArguments: [
                "-yakTestAppLock", "on", "-yakTestBiometry", biometry, "-yakTestAuthentication", authentication,
            ] + extra)
        XCTAssertTrue(app.descendants(matching: .any)["lock.screen"].waitForExistence(timeout: 30))
        // The automatic ask answers first; the shot waits for the idle button.
        Thread.sleep(forTimeInterval: 1)
    }

    // MARK: Lock screen

    func testLockScreen() throws {
        for (biometry, name) in [
            ("faceID", "lock-face-id"), ("touchID", "lock-touch-id"), ("opticID", "lock-optic-id"),
            ("none", "lock-passcode"), ("other", "lock-device-unlock"),
        ] {
            try startLocked(biometry: biometry, authentication: "cancel")
            try shoot(name)
            app.terminate()
        }
    }

    func testLockAuthenticating() throws {
        try startLocked(authentication: "cancel,slow")
        app.buttons["lock.unlock"].tap()
        try shoot("lock-authenticating")
    }

    func testLockFailed() throws {
        try startLocked(authentication: "cancel,fail")
        app.buttons["lock.unlock"].tap()
        XCTAssertTrue(label("could not confirm").waitForExistence(timeout: 10))
        try shoot("lock-failed")
    }

    func testLockPasscodeRemoved() throws {
        try startLocked(biometry: "noPasscode", authentication: "cancel")
        try shoot("lock-passcode-removed")
    }

    func testAppSwitcherCover() throws {
        try startLocked(authentication: "cancel,succeed", extra: ["-yakTestCoverWhenActive", "YES"])
        app.buttons["lock.unlock"].tap()
        openAccount()
        XCTAssertTrue(app.descendants(matching: .any)["lock.cover"].waitForExistence(timeout: 10))
        try shoot("cover-over-sheet", audits: false)
    }

    // MARK: Tips

    /// Opens the thread `d0e515ef` from the drawer.
    private func openThread() throws {
        if !app.otherElements["drawer"].exists, app.buttons["threads.button"].waitForExistence(timeout: 5) {
            app.buttons["threads.button"].tap()
        }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: 30))
        row.tap()
    }

    func testTips() throws {
        try start("one-thread-working", paired: true, extraArguments: ["-yakTestTips", "show"])
        try stub.setPlaybackMilliseconds(1000)
        defer { try? stub.setPlaybackMilliseconds(400) }
        try openThread()
        XCTAssertTrue(app.buttons["composer.stop"].waitForExistence(timeout: 30))
        try stub.setPlaybackMilliseconds(600_000)
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: 30))
        // The tip is the system popover, so its shot skips the audit. With every tip forced on, the pane tip's popover shows over the field first; its close button dismisses it.
        let close = app.buttons["Close"]
        if close.waitForExistence(timeout: 3) { close.tap() }
        field.tap()
        field.typeText("Next")
        XCTAssertTrue(label("Hold to send later").waitForExistence(timeout: 10))
        try shoot("tip-send-after-turn", audits: false)
        app.terminate()
        try start("paired", paired: true, extraArguments: ["-yakTestTips", "show"])
        try openThread()
        let menu = app.buttons["pane.menu"]
        guard menu.waitForExistence(timeout: 30) else { throw XCTSkip("the thread did not open") }
        menu.tap()
        guard app.buttons.matching(identifier: "pane.menu.chat").count > 0,
            app.descendants(matching: .any).matching(NSPredicate(format: "identifier BEGINSWITH 'pane.menu.'")).count
                > 1
        else { throw XCTSkip("needs two panes") }
        app.tap()
        XCTAssertTrue(label("Press and slide").waitForExistence(timeout: 10))
        try shoot("tip-pane-slide", audits: false)
    }
}
