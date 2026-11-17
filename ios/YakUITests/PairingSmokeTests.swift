import XCTest

@MainActor final class PairingSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
    }

    private func launch(paired: Bool = false, skipsWebSession: Bool = false) {
        app.launchArguments = ["-yakTestInstance", stub.url]
        if skipsWebSession { app.launchArguments += ["-yakTestSkipWebSession", "YES"] }
        if paired { app.launchArguments += ["-yakTestToken", "stub-token"] }
        app.launchRetryingOnce()
    }

    /// Opens the account sheet from the drawer, opening the drawer first when the thread screen shows.
    private func openAccount() {
        let account = app.buttons["drawer.account"]
        if !account.waitForExistence(timeout: 10) {
            app.buttons["threads.button"].tap()
        }
        XCTAssertTrue(account.waitForExistence(timeout: Wait.firstLoad))
        account.tap()
    }

    private func label(containing text: String) -> XCUIElement {
        app.staticTexts.containing(NSPredicate(format: "label CONTAINS %@", text)).firstMatch
    }

    func testOpeningAPairingLinkPairsAndShowsWhoIsSignedIn() throws {
        try stub.play("paired")
        launch()
        app.open(stub.pairingLink)
        let pair = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Pair with'")).firstMatch
        XCTAssertTrue(pair.waitForExistence(timeout: Wait.appear))
        pair.tap()
        XCTAssertTrue(label(containing: "Signed in as Test Person").waitForExistence(timeout: Wait.appear))
        try audit()
    }

    func testGoingOfflineShowsTheOfflineLineAndComingBackRemovesIt() throws {
        try stub.play("paired")
        launch(paired: true)
        let offline = label(containing: "You are offline")
        openAccount()
        XCTAssertTrue(label(containing: "Signed in as Test Person").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(offline.exists)
        try stub.setOnline(false)
        XCTAssertTrue(offline.waitForExistence(timeout: Wait.appear))
        try stub.setOnline(true)
        let gone = XCTNSPredicateExpectation(predicate: NSPredicate(format: "exists == false"), object: offline)
        XCTAssertEqual(XCTWaiter().wait(for: [gone], timeout: Wait.appear), .completed)
    }

    func testARevokedPhoneIsAskedToPairAgain() throws {
        try stub.play("revoked")
        launch(paired: true)
        XCTAssertTrue(app.buttons["Pair again"].waitForExistence(timeout: Wait.appear))
        try audit()
    }

    func testAServerThatIsTooNewShowsTheUpdateScreen() throws {
        try stub.play("server-too-new")
        launch()
        try typeAddressOnConnectScreen()
        XCTAssertTrue(app.staticTexts["The app needs an update"].waitForExistence(timeout: Wait.appear))
        try audit()
    }

    func testAnAddressThatIsNotYakIsRefused() throws {
        try stub.play("not-yak")
        launch()
        try typeAddressOnConnectScreen()
        XCTAssertTrue(app.staticTexts["No Yak at this address"].waitForExistence(timeout: Wait.appear))
        XCTAssertEqual(app.textFields.firstMatch.value as? String, stub.address)
        try audit()
    }

    /// Opens the link the way the system does, in the running app. `XCUIApplication.open` would relaunch the
    /// app and lose what the test typed.
    private func openLink() { XCUIDevice.shared.system.open(stub.pairingLink) }

    func testACancelledPairingLinkLeavesTheTypedAddressAlone() throws {
        try stub.play("paired")
        launch()
        try typeAddressOnConnectScreen()
        openLink()
        let cancel = app.buttons["Cancel pairing"]
        XCTAssertTrue(cancel.waitForExistence(timeout: Wait.appear))
        cancel.tap()
        XCTAssertTrue(app.textFields.firstMatch.waitForExistence(timeout: Wait.appear))
        XCTAssertEqual(app.textFields.firstMatch.value as? String, stub.address)
    }

    func testACancelledPairingLinkLeavesARunningSignInAlone() throws {
        try stub.play("approval-pending")
        launch(skipsWebSession: true)
        try typeAddressOnConnectScreen()
        app.textFields.firstMatch.typeText("\n")
        XCTAssertTrue(label(containing: "Waiting for you to approve").waitForExistence(timeout: Wait.appear))
        // The stub keeps answering "approval pending", so the sign-in cannot finish while the link is open.
        openLink()
        let cancel = app.buttons["Cancel pairing"]
        XCTAssertTrue(cancel.waitForExistence(timeout: Wait.appear))
        cancel.tap()
        XCTAssertTrue(label(containing: "Waiting for you to approve").waitForExistence(timeout: Wait.appear))
    }

    /// The accessibility audit; a failure names the element and what is wrong with it.
    private func audit() throws {
        let auditBars = AuditRules.Bars(app)
        try app.performAccessibilityAudit { [self, auditBars] issue in
            // An element entirely outside the screen shows no pixels to measure.
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            // Text behind a bar is covered by the bar.
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
            // The address field is one line by design: an address never wraps.
            if issue.auditType == .textClipped, issue.element?.elementType == .textField { return true }
            let screenshot = XCTAttachment(screenshot: app.screenshot())
            screenshot.lifetime = .keepAlways
            screenshot.name = "audit-failure"
            add(screenshot)
            XCTFail(
                "audit: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "no element")"
            )
            return true
        }
    }

    private func typeAddressOnConnectScreen() throws {
        let enterAddress = app.buttons["Enter an address"]
        XCTAssertTrue(enterAddress.waitForExistence(timeout: Wait.appear))
        enterAddress.tap()
        let field = app.textFields.firstMatch
        XCTAssertTrue(field.waitForExistence(timeout: Wait.appear))
        field.tap()
        field.typeText(stub.address)
    }
}
