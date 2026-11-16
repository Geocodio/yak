import XCTest

/// The quality-bar walkthrough of the push screens: one screenshot per state in `$YAK_SHOT_DIR`, as in
/// `ThreadWalkthroughTests`. Run it with `bundle exec fastlane walkthrough only:PushWalkthroughTests`.
@MainActor final class PushWalkthroughTests: WalkthroughCase {
    // MARK: Explanation after pairing

    /// Pairs through the link and waits for the explanation.
    private func pairToExplanation(_ arguments: [String] = [], scenario: String = "paired") throws {
        try start(
            scenario,
            extraArguments: ["-yakTestNotifications", "notDetermined", "-yakTestHoldExplanation", "YES"] + arguments)
        app.open(stub.pairingLink)
        let pair = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Pair with'")).firstMatch
        XCTAssertTrue(pair.waitForExistence(timeout: 10))
        pair.tap()
        XCTAssertTrue(app.descendants(matching: .any)["notify.screen"].waitForExistence(timeout: 15))
    }

    func testExplanation() throws {
        try pairToExplanation()
        try shoot("notify")
    }

    func testExplanationAsking() throws {
        try pairToExplanation(["-yakTestNotificationRequest", "slow"])
        app.buttons["notify.turnOn"].tap()
        try shoot("notify-asking")
    }

    func testExplanationOn() throws {
        try pairToExplanation()
        app.buttons["notify.turnOn"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["notify.on"].waitForExistence(timeout: 10))
        try shoot("notify-on")
    }

    func testExplanationOnOffline() throws {
        try pairToExplanation()
        try stub.setOnline(false)
        Thread.sleep(forTimeInterval: 2)
        app.buttons["notify.turnOn"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["notify.on"].waitForExistence(timeout: 10))
        try shoot("notify-on-offline")
    }

    func testExplanationOff() throws {
        try pairToExplanation(["-yakTestNotificationRequest", "denied"])
        app.buttons["notify.turnOn"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["notify.off"].waitForExistence(timeout: 10))
        try shoot("notify-off")
    }

    func testExplanationFailed() throws {
        try pairToExplanation(["-yakTestNotificationRequest", "failed"])
        app.buttons["notify.turnOn"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["notify.off"].waitForExistence(timeout: 10))
        try shoot("notify-failed")
    }

    // MARK: Settings

    /// Opens Settings on a paired phone. `scenario` plays before the launch; `then` plays after the drawer shows,
    /// so a slow or broken scenario affects Settings only.
    private func openSettings(
        _ scenario: String = "paired", then later: String? = nil, notifications: String = "authorized",
        extraArguments: [String] = []
    ) throws {
        try start(
            scenario, paired: true,
            extraArguments: ["-yakTestNotifications", notifications] + extraArguments)
        if let later {
            XCTAssertTrue(
                app.buttons["drawer.account"].waitForExistence(timeout: 15)
                    || app.buttons["threads.button"].waitForExistence(timeout: 5))
            try stub.play(later)
        }
        openAccount()
        XCTAssertTrue(app.descendants(matching: .any)["settings.screen"].waitForExistence(timeout: 15))
    }

    private var instanceRow: XCUIElement {
        app.descendants(matching: .any).matching(
            NSPredicate(format: "identifier BEGINSWITH 'settings.instance.'")
        ).firstMatch
    }

    /// Waits until `element`'s label contains `text`.
    private func waitForLabel(_ text: String, of element: XCUIElement, timeout: TimeInterval = 15) {
        let expectation = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "label CONTAINS %@", text), object: element)
        XCTAssertEqual(XCTWaiter().wait(for: [expectation], timeout: timeout), .completed, element.label)
    }

    private func waitForLoadedSwitches() {
        let usage = app.switches["settings.switch.pull_request"]
        XCTAssertTrue(usage.waitForExistence(timeout: 15))
        let enabled = XCTNSPredicateExpectation(predicate: NSPredicate(format: "isEnabled == true"), object: usage)
        XCTAssertEqual(XCTWaiter().wait(for: [enabled], timeout: 15), .completed)
    }

    /// Scrolls until `element` is on screen; Settings is longer than a phone at the largest text size.
    private func reveal(_ element: XCUIElement) {
        var swipes = 0
        while !element.isHittable, swipes < 8 {
            app.swipeUp()
            swipes += 1
        }
    }

    func testSettings() throws {
        try openSettings()
        waitForLabel(", push on", of: instanceRow)
        waitForLoadedSwitches()
        try shoot("settings")
    }

    func testSettingsLoading() throws {
        try openSettings(then: "slower")
        Thread.sleep(forTimeInterval: 1.5)
        try shoot("settings-loading")
        let slow = app.descendants(matching: .any)["settings.switches.slow"]
        XCTAssertTrue(slow.waitForExistence(timeout: 12))
        try shoot("settings-slow")
    }

    func testSettingsLoadError() throws {
        try openSettings(then: "broken")
        XCTAssertTrue(app.descendants(matching: .any)["settings.switches.error"].waitForExistence(timeout: 15))
        try shoot("settings-error")
    }

    func testSettingsOffline() throws {
        try start("paired", paired: true, extraArguments: ["-yakTestNotifications", "authorized"])
        XCTAssertTrue(
            app.buttons["drawer.account"].waitForExistence(timeout: 15)
                || app.buttons["threads.button"].waitForExistence(timeout: 5))
        try stub.setOnline(false)
        Thread.sleep(forTimeInterval: 2)
        openAccount()
        XCTAssertTrue(app.descendants(matching: .any)["settings.screen"].waitForExistence(timeout: 15))
        XCTAssertTrue(label("You are offline").waitForExistence(timeout: 15))
        try shoot("settings-offline")
    }

    func testSettingsSaving() throws {
        try openSettings()
        waitForLoadedSwitches()
        try stub.play("slow")
        app.switches["settings.switch.turn_finished"].tap()
        Thread.sleep(forTimeInterval: 1)
        try shoot("settings-saving")
    }

    func testSettingsSaveFailed() throws {
        try openSettings()
        waitForLoadedSwitches()
        try stub.play("notifications-failing")
        app.switches["settings.switch.pull_request"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["settings.switches.saveFailed"].waitForExistence(timeout: 15))
        try shoot("settings-save-failed")
    }

    func testSettingsNoKey() throws {
        try openSettings("push-unavailable")
        waitForLabel("No push. This Yak has no notification key.", of: instanceRow)
        try shoot("settings-no-key")
    }

    func testSettingsOtherBuild() throws {
        try openSettings("push-other-build")
        waitForLabel("No push. This build has no key for it.", of: instanceRow)
        try shoot("settings-other-build")
    }

    func testSettingsOtherEnvironment() throws {
        try openSettings("push-other-environment")
        waitForLabel("No push. This Yak sends to development builds only.", of: instanceRow)
        try shoot("settings-other-environment")
    }

    func testSettingsRetrying() throws {
        try openSettings("push-registration-failing")
        waitForLabel("keeps trying", of: instanceRow)
        try shoot("settings-retrying")
    }

    func testSettingsPermissionOff() throws {
        try openSettings(notifications: "denied")
        let button = app.descendants(matching: .any)["settings.openSystemSettings"]
        reveal(button)
        XCTAssertTrue(button.exists)
        try shoot("settings-permission-off")
    }

    func testSettingsPermissionNotAsked() throws {
        try openSettings(notifications: "notDetermined")
        let button = app.buttons["settings.permission"]
        reveal(button)
        XCTAssertTrue(button.exists)
        try shoot("settings-permission-turn-on")
    }

    func testSettingsLiveActivitiesOff() throws {
        try openSettings(extraArguments: ["-yakTestLiveActivities", "off"])
        let row = app.descendants(matching: .any)["settings.liveActivitiesOff"]
        reveal(row)
        XCTAssertTrue(row.exists)
        try shoot("settings-live-activities-off")
    }

    func testSettingsTestSending() throws {
        try openSettings()
        waitForLabel(", push on", of: instanceRow)
        try stub.play("slow")
        let test = app.buttons["settings.test"]
        reveal(test)
        test.tap()
        XCTAssertTrue(label("Sending").waitForExistence(timeout: 5))
        try shoot("settings-test-sending")
    }

    func testSettingsTestSent() throws {
        try openSettings()
        waitForLabel(", push on", of: instanceRow)
        let test = app.buttons["settings.test"]
        reveal(test)
        test.tap()
        XCTAssertTrue(label("Sent.").waitForExistence(timeout: 10))
        try shoot("settings-test-sent")
    }

    func testSettingsTestNoToken() throws {
        try openSettings(notifications: "notDetermined")
        waitForLoadedSwitches()
        let test = app.buttons["settings.test"]
        reveal(test)
        test.tap()
        XCTAssertTrue(app.descendants(matching: .any)["settings.test.failed"].waitForExistence(timeout: 10))
        try shoot("settings-test-no-token")
    }

    func testSettingsTestRejected() throws {
        try openSettings("push-test-rejected")
        waitForLabel(", push on", of: instanceRow)
        let test = app.buttons["settings.test"]
        reveal(test)
        test.tap()
        XCTAssertTrue(app.descendants(matching: .any)["settings.test.failed"].waitForExistence(timeout: 10))
        try shoot("settings-test-rejected")
    }

    func testSettingsRemoveConfirm() throws {
        try openSettings()
        waitForLabel(", push on", of: instanceRow)
        // The context menu offers Remove.
        instanceRow.press(forDuration: 1.2)
        let menuRemove = app.buttons["Remove"].firstMatch
        XCTAssertTrue(menuRemove.waitForExistence(timeout: 5))
        try shoot("settings-remove-menu", audits: false)
        menuRemove.tap()
        let confirm = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Remove'")).firstMatch
        XCTAssertTrue(confirm.waitForExistence(timeout: 5))
        try shoot("settings-remove-confirmation")
        // Cancelling leaves the instance in place; the swipe action offers Remove too.
        let cancel = app.buttons["Cancel"].firstMatch
        if cancel.exists { cancel.tap() } else { app.tap() }
        instanceRow.swipeLeft()
        let swipeRemove = app.buttons["Remove"].firstMatch
        XCTAssertTrue(swipeRemove.waitForExistence(timeout: 5))
        try shoot("settings-remove")
    }

    // MARK: Live Activities

    private static let liveActivityStates = [
        "working", "working-no-step", "needs-you", "finished", "failed", "stopped", "stale", "unknown-state",
        "private", "long-title",
    ]

    /// The debug gallery, one launch per state.
    func testLiveActivities() throws {
        for state in Self.liveActivityStates {
            app.terminate()
            app.launchEnvironment["YAK_TEST_GALLERY_STATE"] = state
            try start("paired", extraArguments: ["-yakTestScreen", "live-activity-gallery"])
            XCTAssertTrue(app.staticTexts["gallery.state"].waitForExistence(timeout: 10))
            try shoot("live-\(state)")
        }
    }
}
