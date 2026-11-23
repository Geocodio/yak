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
        let idle = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "NOT (label CONTAINS 'Unlocking')"), object: app.buttons["lock.unlock"])
        XCTAssertEqual(XCTWaiter().wait(for: [idle], timeout: 10), .completed)
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
        try shoot("cover-over-sheet")
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
        // The screen under the tip is audited here, with the tip closed; the tip's own shots skip the audit.
        try shoot("tip-thread-under")
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

    // MARK: Settings rows and the debug menu

    /// Opens Settings and scrolls to a row.
    private func revealInSettings(_ identifier: String) {
        openAccount()
        let row = app.descendants(matching: .any)[identifier]
        for _ in 0..<8 where !row.exists || !row.isHittable { app.swipeUp() }
        XCTAssertTrue(row.exists, identifier)
    }

    func testSettingsPrivacy() throws {
        try start("paired", paired: true)
        revealInSettings("settings.thisDevice")
        try shoot("settings-privacy")
    }

    /// The offline copies row in its four states: with a count, the confirmation, cleared and empty.
    func testSettingsOfflineCopies() throws {
        try start("embed-basic", paired: true)
        try openThread()
        let page = app.staticTexts["Ready"]
        XCTAssertTrue(page.waitForExistence(timeout: 30))
        let row = app.descendants(matching: .any)["settings.offlineCopies"]
        for attempt in 1...4 {
            // Leaving the foreground saves the page's copy at once.
            XCUIDevice.shared.press(.home)
            let isBackgrounded = NSPredicate(format: "state != %d", XCUIApplication.State.runningForeground.rawValue)
            wait(for: [XCTNSPredicateExpectation(predicate: isBackgrounded, object: app)], timeout: 10)
            app.activate()
            let account = app.buttons["drawer.account"]
            if !account.isHittable { app.buttons["threads.button"].tap() }
            XCTAssertTrue(account.waitForExistence(timeout: 10))
            account.tap()
            let footer = app.descendants(matching: .any)["settings.offlineCopies.footer"]
            for _ in 0..<8 where !footer.exists || !footer.isHittable { app.swipeUp() }
            if row.isEnabled { break }
            XCTAssertLessThan(attempt, 4, "the copy was never saved")
            app.buttons["Close"].firstMatch.tap()
        }
        try shoot("settings-copies-row")
        // The audit's text size pass resets the list's scroll position.
        let footer = app.descendants(matching: .any)["settings.offlineCopies.footer"]
        for _ in 0..<8 where !footer.exists || !footer.isHittable { app.swipeUp() }
        app.swipeUp()
        row.tap()
        XCTAssertTrue(app.buttons["Clear 1 copy"].waitForExistence(timeout: 10))
        try shoot("settings-copies-confirm", audits: false)
        app.buttons["Clear 1 copy"].tap()
        let cleared = NSPredicate(format: "label == 'Clear offline copies, Cleared. No saved copies'")
        wait(for: [XCTNSPredicateExpectation(predicate: cleared, object: row)], timeout: 10)
        try shoot("settings-copies-cleared")
        app.buttons["Close"].firstMatch.tap()
        let account = app.buttons["drawer.account"]
        if !account.isHittable { app.buttons["threads.button"].tap() }
        XCTAssertTrue(account.waitForExistence(timeout: 10))
        account.tap()
        for _ in 0..<8 where !footer.exists || !footer.isHittable { app.swipeUp() }
        XCTAssertEqual(row.label, "Clear offline copies, No saved copies")
        try shoot("settings-copies-empty")
    }

    func testSettingsLockConfirming() throws {
        try start(
            "paired", paired: true, extraArguments: ["-yakTestAuthentication", "slow"])
        revealInSettings("settings.appLock")
        app.switches["settings.appLock"].coordinate(withNormalizedOffset: CGVector(dx: 0.93, dy: 0.5)).tap()
        try shoot("settings-lock-confirming")
    }

    func testSettingsNoPasscode() throws {
        try start("paired", paired: true, extraArguments: ["-yakTestBiometry", "noPasscode"])
        revealInSettings("settings.appLock")
        try shoot("settings-no-passcode")
    }

    func testSettingsSpotlightFailed() throws {
        try start("paired", paired: true, extraArguments: ["-yakTestSpotlight", "failing"])
        revealInSettings("settings.spotlightPrivate")
        app.switches["settings.spotlightPrivate"].coordinate(withNormalizedOffset: CGVector(dx: 0.93, dy: 0.5)).tap()
        XCTAssertTrue(app.descendants(matching: .any)["settings.spotlightPrivate.failed"].waitForExistence(timeout: 15))
        try shoot("settings-spotlight-failed")
    }

    func testLicenses() throws {
        try start("paired", paired: true)
        revealInSettings("settings.licenses")
        app.descendants(matching: .any)["settings.licenses"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["licenses.screen"].waitForExistence(timeout: 10))
        try shoot("licenses")
        app.descendants(matching: .any)["licenses.swift-markdown"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["license.text"].waitForExistence(timeout: 10))
        try shoot("license-text")
    }

    func testSettingsTestFlight() throws {
        try start("paired", paired: true, extraArguments: ["-yakTestBuildKind", "testFlight"])
        revealInSettings("settings.debug")
        try shoot("settings-debug-row")
    }

    func testSettingsAppStore() throws {
        try start("paired", paired: true, extraArguments: ["-yakTestBuildKind", "appStore"])
        revealInSettings("settings.licenses")
        XCTAssertFalse(app.descendants(matching: .any)["settings.debug"].exists)
        try shoot("settings-no-debug-row")
    }

    private func openDebug(_ extra: [String] = []) throws {
        try start("paired", paired: true, extraArguments: extra)
        revealInSettings("settings.debug")
        app.descendants(matching: .any)["settings.debug"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["debug.menu"].waitForExistence(timeout: 10))
    }

    func testDebugMenu() throws {
        try openDebug()
        try shoot("debug-menu")
    }

    func testDebugFaults() throws {
        try openDebug()
        for fault in ["slow", "verySlow", "offline", "serverError"] {
            app.descendants(matching: .any)["debug.fault.\(fault)"].tap()
            try shoot("debug-fault-\(fault)")
        }
    }

    func testDebugLog() throws {
        try openDebug(["-yakTestToken", "stub-token"])
        app.descendants(matching: .any)["debug.log"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["debug.log.screen"].waitForExistence(timeout: 10))
        try shoot("debug-log")
    }

    func testDebugLogEmpty() throws {
        try openDebug(["-yakTestLog", "empty"])
        app.descendants(matching: .any)["debug.log"].tap()
        XCTAssertTrue(label("No log lines").waitForExistence(timeout: 10))
        try shoot("debug-log-empty")
    }

    func testDebugLogFailed() throws {
        try openDebug(["-yakTestLog", "failed"])
        app.descendants(matching: .any)["debug.log"].tap()
        XCTAssertTrue(label("could not be read").waitForExistence(timeout: 10))
        try shoot("debug-log-failed")
    }

    // MARK: State gallery

    func testStateGallery() throws {
        try openDebug()
        app.descendants(matching: .any)["debug.gallery"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["gallery.screen"].waitForExistence(timeout: 10))
        let ids = [
            "pairing.unreachable", "pairing.serverTooOld", "pairing.appTooOld", "pairing.codeExpired",
            "pairing.codeInvalid", "pairing.denied", "threads.loading", "threads.slow", "threads.notFound",
            "threads.offline", "threads.serverError", "tasks.loading", "tasks.offline", "settings.unreachable",
            "settings.appTooOld", "settings.serverTooOld", "settings.tokenUnreadable", "settings.failed",
            "appLock.locked", "appLock.cover",
        ]
        for id in ids {
            let row = app.descendants(matching: .any)["gallery.\(id)"]
            for _ in 0..<10 where !row.exists || !row.isHittable { app.swipeUp() }
            XCTAssertTrue(row.exists, id)
            row.tap()
            XCTAssertTrue(app.buttons["gallery.close"].waitForExistence(timeout: 10), id)
            try shoot("gallery-\(id)")
            app.buttons["gallery.close"].tap()
            XCTAssertTrue(app.descendants(matching: .any)["gallery.screen"].waitForExistence(timeout: 10))
        }
    }
}
