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
}
