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
}
