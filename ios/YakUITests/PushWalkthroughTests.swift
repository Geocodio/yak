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
}
