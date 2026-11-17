import XCTest

/// The nightly journeys: the real app against a real local Yak in e2e mode. They run only from
/// `bundle exec fastlane e2e` (and its workflow), never from `ui_smoke`.
@MainActor final class JourneyTests: XCTestCase {
    private var server: JourneyServer!
    private var web: JourneyWebPages!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        XCUIDevice.shared.orientation = .portrait
        server = try JourneyServer()
        web = JourneyWebPages(server: server)
    }

    private func launch(phone: JourneySeed.Phone?) {
        app.launchArguments = server.launchArguments(phone: phone)
        app.launchRetryingOnce()
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func threadRow(_ thread: JourneySeed.SeededThread) -> XCUIElement {
        element("drawer.row.thread:\(thread.id)")
    }

    /// Opens the drawer when the thread screen shows, so a thread row can be tapped.
    private func openDrawerIfNeeded() {
        if element("drawer").waitForExistence(timeout: 10) { return }
        app.buttons["threads.button"].tap()
    }

    private func typeAddressOnConnectScreen() {
        let enterAddress = app.buttons["Enter an address"]
        JourneyWait.until(enterAddress, within: 30, "the button \"Enter an address\"", in: self)
        enterAddress.tap()
        let field = app.textFields.firstMatch
        JourneyWait.until(field, within: 15, "the address field", in: self)
        field.tap()
        field.typeText(server.address + "\n")
        JourneyWait.until(
            app.staticTexts["Waiting for you to approve this device"], within: 15,
            "\"Waiting for you to approve this device\"", in: self)
    }

    /// Approves the pairing the waiting screen shows, over the web, while the system's sign-in sheet is open.
    private func approveTheWaitingPairing() async throws {
        web.acceptSignInAlert()
        let address = element("pairing.approvalURL")
        JourneyWait.until(address, within: 15, "the approval address on the waiting screen", in: self)
        try await web.approve(approvalURL: address.label)
        app.activate()
    }

    private func assertNoPairingProblem() {
        XCTAssertFalse(app.buttons["Pair again"].exists, "the app asks to pair again")
        XCTAssertFalse(element("thread.connection").exists, "an offline or slow line shows")
    }

    // MARK: Journey 1

    func testJourney1PairsWithATypedAddressThroughTheLocalLogin() async throws {
        try await web.signInWithTheLocalLogin()
        launch(phone: nil)
        typeAddressOnConnectScreen()
        try await approveTheWaitingPairing()
        openDrawerIfNeeded()
        JourneyWait.until(
            threadRow(server.seed.threads.finished), within: 30, "the finished thread in the drawer", in: self)
        JourneyWait.until(threadRow(server.seed.threads.held), within: 10, "the held thread in the drawer", in: self)
        assertNoPairingProblem()
        try JourneyWait.audit(app, in: self)
    }

    // MARK: Journey 6

    func testJourney6RevokedOnTheWebAsksToPairAgainAndPairs() async throws {
        launch(phone: server.seed.phones.second)
        openDrawerIfNeeded()
        let finished = threadRow(server.seed.threads.finished)
        JourneyWait.until(finished, within: 20, "the finished thread in the drawer", in: self)
        try await web.signInWithTheLocalLogin()
        try await web.revokePhone(named: server.seed.phones.second.name)
        app.activate()
        openDrawerIfNeeded()
        if finished.waitForExistence(timeout: 5) { finished.tap() }
        let pairAgain = app.buttons["Pair again"]
        JourneyWait.until(pairAgain, within: 20, "\"Pair again\" after the revoke", in: self)
        try JourneyWait.audit(app, in: self)
        pairAgain.tap()
        // The sheet opens with the instance's address already filled in.
        let continueButton = app.buttons["Continue to sign in"]
        JourneyWait.until(continueButton, within: 20, "the button \"Continue to sign in\"", in: self)
        continueButton.tap()
        JourneyWait.until(
            app.staticTexts["Waiting for you to approve this device"], within: 15,
            "\"Waiting for you to approve this device\"", in: self)
        try await approveTheWaitingPairing()
        // Pairing again returns to the thread the person had opened.
        JourneyWait.until(
            app.staticTexts[server.seed.threads.finished.title], within: 30,
            "the finished thread's title after pairing again", in: self)
        XCTAssertFalse(app.buttons["Pair again"].exists, "the app still asks to pair again")
    }
}
