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
        let button = app.buttons["threads.button"]
        JourneyWait.until(button, within: 20, "the threads button", in: self)
        button.tap()
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
        JourneyWait.until(finished, within: 10, "the finished thread in the drawer after the revoke", in: self)
        finished.tap()
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
        // The drawer row has the same title, so the wait is on the thread screen's own title.
        let title = app.descendants(matching: .any).matching(identifier: "thread.title")
            .matching(NSPredicate(format: "label CONTAINS %@", server.seed.threads.finished.title)).firstMatch
        JourneyWait.until(title, within: 30, "the finished thread's title after pairing again", in: self)
        XCTAssertFalse(app.buttons["Pair again"].exists, "the app still asks to pair again")
    }

    // MARK: Journey 2

    func testJourney2StartsAThreadAndWatchesItStreamOverReverb() throws {
        launch(phone: server.seed.phones.main)
        openDrawerIfNeeded()
        let newThread = app.buttons["drawer.newThread"]
        JourneyWait.until(newThread, within: 30, "the New thread button", in: self)
        newThread.tap()
        let repository = element("newThread.repo")
        JourneyWait.until(repository, within: 20, "the repository picker", in: self)
        // The chip shows the repository's display name; the seeded example/api repository is named "API".
        XCTAssertEqual(repository.value as? String, "API")
        let field = element("newThread.field")
        JourneyWait.until(field, within: 10, "the new thread field", in: self)
        field.tap()
        field.typeText("Add a health check endpoint")
        element("newThread.send").tap()
        let stop = app.buttons["composer.stop"]
        JourneyWait.until(stop, within: 45, "the stop button while the turn runs", in: self)
        XCTAssertFalse(element("thread.connection").exists, "an offline or slow line shows")
        JourneyWait.until(app.staticTexts["scrubbed text 1"], within: 30, "the streamed assistant text", in: self)
        JourneyWait.until(stop, exists: false, within: 30, "the stop button to go after the turn", in: self)
        XCTAssertEqual(composerPlaceholder, "Message Yak")
        XCTAssertFalse(element("thread.connection").exists, "an offline or slow line shows")
        try JourneyWait.audit(app, in: self)
    }

    // MARK: Journey 4

    /// The marker the fake runner (`E2eRunnerScript::HOLD_MARKER`) keeps a turn open for until it is interrupted.
    private let holdMarker = "(hold this turn)"

    private var composerPlaceholder: String? { app.textFields["composer.field"].placeholderValue }

    func testJourney4SteersARunningTurnAndStopsIt() throws {
        launch(phone: server.seed.phones.main)
        openDrawerIfNeeded()
        let held = threadRow(server.seed.threads.held)
        JourneyWait.until(held, within: 30, "the held thread in the drawer", in: self)
        held.tap()
        let field = app.textFields["composer.field"]
        JourneyWait.until(field, within: 20, "the composer", in: self)
        let startedAt = Date()
        field.tap()
        field.typeText("Look at the importer first \(holdMarker)")
        app.buttons["composer.send"].tap()
        let stop = app.buttons["composer.stop"]
        JourneyWait.until(stop, within: 45, "the stop button while the held turn runs", in: self)
        // The composer follows the thread's own state: while the held turn runs, a message steers it.
        let steering = NSPredicate { [app] _, _ in app.textFields["composer.field"].placeholderValue == "Steer Yak" }
        wait(for: [XCTNSPredicateExpectation(predicate: steering, object: nil)], timeout: 30)
        XCTAssertEqual(composerPlaceholder, "Steer Yak")
        field.tap()
        field.typeText("Also cover the empty file case")
        app.buttons["composer.send"].tap()
        JourneyWait.until(
            app.staticTexts["Also cover the empty file case"], within: 10, "the steer message in the conversation page",
            in: self)
        XCTAssertFalse(app.staticTexts["Sends after this turn"].exists, "the steer waits for the end of the turn")
        XCTAssertFalse(app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH 'Not sent'")).firstMatch.exists)
        stop.tap()
        JourneyWait.until(stop, exists: false, within: 20, "the stop button to go after Stop", in: self)
        XCTAssertEqual(composerPlaceholder, "Message Yak")
        XCTAssertLessThan(Date().timeIntervalSince(startedAt), 60, "the held bound of 120 s must never be reached")
        try JourneyWait.audit(app, in: self)
    }

    // MARK: Journey 5

    /// The pull request number the faked GitHub answers with (`E2eGitHub::PULL_REQUEST_NUMBER`).
    private let fakePullRequestNumber = 7

    private func showPane(_ name: String) {
        let menu = app.buttons["pane.menu"]
        JourneyWait.until(menu, within: 20, "the pane menu", in: self)
        menu.tap()
        let item = app.buttons.matching(NSPredicate(format: "label == %@", name)).firstMatch
        JourneyWait.until(item, within: 10, "the \(name) item of the pane menu", in: self)
        item.tap()
    }

    func testJourney5ReviewsTheDiffAndOpensAPullRequest() throws {
        launch(phone: server.seed.phones.main)
        openDrawerIfNeeded()
        let changes = threadRow(server.seed.threads.changes)
        JourneyWait.until(changes, within: 30, "the changes thread in the drawer", in: self)
        changes.tap()
        showPane("Files")
        let list = element("files.list")
        // Every loaded file page has a title, so the file is named by its text.
        func title(_ file: String) -> XCUIElement {
            app.staticTexts.matching(identifier: "files.file.title")
                .matching(NSPredicate(format: "label CONTAINS %@", file)).firstMatch
        }
        JourneyWait.until(list, within: 30, "the file position", in: self)
        JourneyWait.untilLabel(list, is: "File 1 of 2. Show all files", within: 15, "the file position", in: self)
        JourneyWait.until(title("upload.php"), within: 15, "the first file's title", in: self)
        // Each loaded page has its own Viewed button; the visible one is the current file's.
        let viewed = app.buttons.matching(identifier: "files.viewed").allElementsBoundByIndex.first { $0.isHittable }
        XCTAssertNotNil(viewed, "no visible Viewed button")
        viewed?.tap()
        element("files.next").tap()
        JourneyWait.untilLabel(list, is: "File 2 of 2. Show all files", within: 15, "the file position", in: self)
        JourneyWait.until(title("retry.php"), within: 15, "the second file's title", in: self)
        let openPullRequest = element("files.openPullRequest")
        JourneyWait.until(openPullRequest, within: 15, "the Open PR button", in: self)
        openPullRequest.tap()
        JourneyWait.untilLabel(
            openPullRequest, is: "Pull request \(fakePullRequestNumber), open. Show details", within: 45,
            "the opened pull request", in: self)
        openPullRequest.tap()
        JourneyWait.until(element("pullRequest.card"), within: 10, "the pull requests card", in: self)
        let row = app.descendants(matching: .any)
            .matching(NSPredicate(format: "identifier BEGINSWITH 'pullRequest.row.'")).firstMatch
        JourneyWait.until(row, within: 10, "the pull request row", in: self)
        XCTAssertTrue(row.label.contains("#\(fakePullRequestNumber) example/api"), "the row reads \(row.label)")
        XCTAssertTrue(row.label.contains("Open"), "the row reads \(row.label)")
        try JourneyWait.audit(app, in: self)
    }
}
