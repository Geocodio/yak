import XCTest

@MainActor final class PairingSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
    }

    private func launch(paired: Bool = false) {
        app.launchArguments = ["-yakTestInstance", stub.url]
        if paired { app.launchArguments += ["-yakTestToken", "stub-token"] }
        app.launch()
    }

    private func label(containing text: String) -> XCUIElement {
        app.staticTexts.containing(NSPredicate(format: "label CONTAINS %@", text)).firstMatch
    }

    func testOpeningAPairingLinkPairsAndShowsWhoIsSignedIn() throws {
        try stub.play("paired")
        launch()
        app.open(stub.pairingLink)
        let pair = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Pair with'")).firstMatch
        XCTAssertTrue(pair.waitForExistence(timeout: 10))
        pair.tap()
        XCTAssertTrue(label(containing: "Signed in as Test Person").waitForExistence(timeout: 10))
        try audit()
    }

    func testARevokedPhoneIsAskedToPairAgain() throws {
        try stub.play("revoked")
        launch(paired: true)
        XCTAssertTrue(app.buttons["Pair again"].waitForExistence(timeout: 10))
        try audit()
    }

    func testAServerThatIsTooNewShowsTheUpdateScreen() throws {
        try stub.play("server-too-new")
        launch()
        try typeAddressOnConnectScreen()
        XCTAssertTrue(app.staticTexts["The app needs an update"].waitForExistence(timeout: 10))
        try audit()
    }

    func testAnAddressThatIsNotYakIsRefused() throws {
        try stub.play("not-yak")
        launch()
        try typeAddressOnConnectScreen()
        XCTAssertTrue(app.staticTexts["No Yak at this address"].waitForExistence(timeout: 10))
        XCTAssertEqual(app.textFields.firstMatch.value as? String, stub.address)
        try audit()
    }

    /// The accessibility audit; a failure names the element and what is wrong with it.
    private func audit() throws {
        try app.performAccessibilityAudit { [self] issue in
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
        XCTAssertTrue(enterAddress.waitForExistence(timeout: 10))
        enterAddress.tap()
        let field = app.textFields.firstMatch
        XCTAssertTrue(field.waitForExistence(timeout: 5))
        field.tap()
        field.typeText(stub.address)
    }
}
