import XCTest

/// Push against the stub: the explanation after pairing, Settings and push taps.
@MainActor final class PushSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
    }

    private func launch(paired: Bool = false, notifications: String = "notDetermined", extra: [String] = []) {
        app.launchArguments =
            ["-yakTestInstance", stub.url, "-yakTestNotifications", notifications] + extra
        if paired { app.launchArguments += ["-yakTestToken", "stub-token"] }
        app.launch()
    }

    private func pair() {
        app.open(stub.pairingLink)
        let pair = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Pair with'")).firstMatch
        XCTAssertTrue(pair.waitForExistence(timeout: 10))
        pair.tap()
    }

    private func audit() throws {
        try app.performAccessibilityAudit()
    }

    func testPairingOffersNotificationsAndTurningThemOnRegisters() throws {
        try stub.play("paired")
        launch()
        pair()
        let screen = app.descendants(matching: .any)["notify.screen"]
        XCTAssertTrue(screen.waitForExistence(timeout: 10))
        XCTAssertTrue(
            app.staticTexts.containing(NSPredicate(format: "label BEGINSWITH 'Paired with'")).firstMatch.exists)
        XCTAssertTrue(try stub.pushState()["tokens"] is [String: Any])
        XCTAssertTrue((try stub.pushState()["tokens"] as? [String: Any])?.isEmpty ?? false)
        app.buttons["notify.turnOn"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["notify.on"].waitForExistence(timeout: 10))
        try audit()
        let registered = XCTNSPredicateExpectation(
            predicate: NSPredicate { _, _ in
                guard let tokens = try? self.stub.pushState()["tokens"] as? [String: Any],
                    let alert = tokens["alert"] as? [String: String]
                else { return false }
                return alert["environment"] == "production"
            }, object: nil)
        XCTAssertEqual(XCTWaiter().wait(for: [registered], timeout: 10), .completed)
        XCTAssertTrue(
            app.buttons["threads.button"].waitForExistence(timeout: 10)
                || app.buttons["drawer.account"].waitForExistence(timeout: 10))
    }

    private func openSettings() {
        let account = app.buttons["drawer.account"]
        if !account.waitForExistence(timeout: 15) {
            app.buttons["threads.button"].tap()
        }
        XCTAssertTrue(account.waitForExistence(timeout: 10))
        account.tap()
        XCTAssertTrue(app.descendants(matching: .any)["settings.screen"].waitForExistence(timeout: 10))
    }

    func testSettingsSwitchesSaveAndShowPushState() throws {
        try stub.play("paired")
        launch(paired: true, notifications: "authorized")
        openSettings()
        let row = app.descendants(matching: .any).matching(
            NSPredicate(format: "identifier BEGINSWITH 'settings.instance.'")
        ).firstMatch
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        let pushOn = NSPredicate(format: "label CONTAINS ', push on'")
        let registered = XCTNSPredicateExpectation(predicate: pushOn, object: row)
        XCTAssertEqual(XCTWaiter().wait(for: [registered], timeout: 10), .completed)
        let finished = app.switches["settings.switch.turn_finished"]
        XCTAssertTrue(finished.waitForExistence(timeout: 10))
        finished.tap()
        let saved = XCTNSPredicateExpectation(
            predicate: NSPredicate { _, _ in
                let preferences = (try? self.stub.pushState()["preferences"] as? [String: Bool]) ?? [:]
                return preferences["turn_finished"] == false
            }, object: nil)
        XCTAssertEqual(XCTWaiter().wait(for: [saved], timeout: 10), .completed)
        try audit()
        try stub.play("notifications-failing")
        let usage = app.switches["settings.switch.stack_usage"]
        XCTAssertTrue(usage.waitForExistence(timeout: 10))
        let valueBefore = usage.value as? String
        usage.tap()
        XCTAssertTrue(
            app.staticTexts.containing(NSPredicate(format: "label BEGINSWITH 'Could not save'")).firstMatch
                .waitForExistence(timeout: 10))
        XCTAssertEqual(usage.value as? String, valueBefore, "the switch returns to its earlier value")
        try audit()
    }

    func testABuildWithoutPushSaysWhy() throws {
        try stub.play("push-other-build")
        launch(paired: true, notifications: "authorized")
        openSettings()
        let row = app.descendants(matching: .any).matching(
            NSPredicate(format: "identifier BEGINSWITH 'settings.instance.'")
        ).firstMatch
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        let explained = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "label CONTAINS 'No push. This build has no key for it.'"), object: row)
        XCTAssertEqual(XCTWaiter().wait(for: [explained], timeout: 10), .completed, row.label)
        XCTAssertTrue(
            app.staticTexts.containing(NSPredicate(format: "label CONTAINS 'cannot send notifications to this build'"))
                .firstMatch.waitForExistence(timeout: 10))
        let tokens = try XCTUnwrap(try stub.pushState()["tokens"] as? [String: Any])
        XCTAssertTrue(tokens.isEmpty)
    }

    func testABuildWithoutPushSkipsTheExplanation() throws {
        try stub.play("push-other-build")
        launch()
        pair()
        XCTAssertTrue(
            app.buttons["threads.button"].waitForExistence(timeout: 15)
                || app.buttons["drawer.account"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.descendants(matching: .any)["notify.screen"].waitForExistence(timeout: 5))
        let tokens = try XCTUnwrap(try stub.pushState()["tokens"] as? [String: Any])
        XCTAssertTrue(tokens.isEmpty)
    }

    func testAPushTapOpensTheThreadFromAColdLaunch() throws {
        try stub.play("question-open")
        launch(paired: true, notifications: "authorized", extra: ["-yakTestPushTap", try stub.pushPayload("scenario-push-alert-question")])
        XCTAssertTrue(
            app.staticTexts["Retry failed rows when the import times out"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.otherElements["conversation"].waitForExistence(timeout: 10))
        try audit()
    }

    func testAPushTapWhileRunningOpensTheThread() throws {
        try stub.play("paired")
        launch(paired: true, notifications: "authorized")
        XCTAssertTrue(
            app.buttons["drawer.account"].waitForExistence(timeout: 15)
                || app.buttons["threads.button"].waitForExistence(timeout: 5))
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "test-push"
        components.percentEncodedQueryItems = [
            URLQueryItem(
                name: "payload",
                value: try stub.pushPayload("scenario-push-alert-question")
                    .addingPercentEncoding(withAllowedCharacters: .alphanumerics))
        ]
        app.open(try XCTUnwrap(components.url))
        XCTAssertTrue(
            app.staticTexts["Retry failed rows when the import times out"].waitForExistence(timeout: 10))
    }
}
