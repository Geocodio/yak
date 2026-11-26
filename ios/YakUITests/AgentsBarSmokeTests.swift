import XCTest

/// The bar above the message field while agents run, against the stub's `agents-*` scenarios. The stub's test page
/// reports the running agents over the bridge, as the real page does.
@MainActor final class AgentsBarSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func open(_ scenario: String, hasField: Bool = true, contentSize: String? = nil) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        if let contentSize { app.launchArguments += ["-UIPreferredContentSizeCategoryName", contentSize] }
        app.launchRetryingOnce()
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        if hasField { XCTAssertTrue(app.textFields["composer.field"].waitForExistence(timeout: Wait.appear)) }
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }
    private var count: XCUIElement { element("agents.count") }

    private func waitFor(_ predicate: String, _ element: XCUIElement, _ message: String = "") {
        let expectation = XCTNSPredicateExpectation(predicate: NSPredicate(format: predicate), object: element)
        XCTAssertEqual(XCTWaiter().wait(for: [expectation], timeout: Wait.appear), .completed, message)
    }

    /// View is enabled once the page has named the newest agent's card; a tap before that does nothing.
    private func waitForView() {
        waitFor("isEnabled == true", element("agents.view"), "the page did not name an agent card")
    }

    private func waitForCount(_ text: String) {
        XCTAssertTrue(count.waitForExistence(timeout: Wait.firstLoad), "the bar did not appear")
        waitFor("label == %@".replacingOccurrences(of: "%@", with: "'\(text)'"), count, "the count is not \(text)")
    }

    /// Opens the confirmation and answers it with the button named `title`.
    private func confirmStop(_ agents: String) {
        element("agents.stop").tap()
        // An alert button is listed twice, so the first match is the one.
        XCTAssertTrue(app.alerts.buttons["Stop \(agents)"].firstMatch.waitForExistence(timeout: Wait.appear))
        app.alerts.buttons["Stop \(agents)"].firstMatch.tap()
    }

    func testTheBarShowsTheCountAboveTheField() throws {
        try open("agents-two")
        waitForCount("2 agents running")
        XCTAssertTrue(element("agents.view").exists && element("agents.stop").exists)
        XCTAssertLessThan(element("agents.bar").frame.maxY, app.textFields["composer.field"].frame.minY)
        try auditThreadScreen(app)
    }

    func testOneAgentSaysAgentInTheSingular() throws {
        try open("agents-one")
        waitForCount("1 agent running")
    }

    func testTheBarGoesWhenThePageReportsNoAgent() throws {
        try open("agents-two")
        waitForCount("2 agents running")
        try stub.setRunningAgents(1)
        waitForCount("1 agent running")
        try stub.setRunningAgents(0)
        waitFor("exists == false", element("agents.bar"))
    }

    func testViewScrollsTheConversationToTheNewestAgent() throws {
        try open("agents-two")
        waitForCount("2 agents running")
        waitForView()
        element("agents.view").tap()
        XCTAssertTrue(app.staticTexts["Focused agent-card-a0"].waitForExistence(timeout: Wait.appear))
    }

    /// `agents-missing` names a newest card that the page does not draw.
    func testViewSaysSoWhenTheCardIsNotOnThePage() throws {
        try open("agents-missing")
        waitForCount("2 agents running")
        waitForView()
        element("agents.view").tap()
        XCTAssertTrue(element("thread.web.notice").waitForExistence(timeout: Wait.appear))
    }

    func testStopAllAsksFirstAndCancelSendsNothing() throws {
        try open("agents-two")
        waitForCount("2 agents running")
        element("agents.stop").tap()
        XCTAssertTrue(app.alerts["Stop all agents?"].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(
            app.alerts.staticTexts["Claude stops its 2 running agents. Claude itself keeps working on this turn."]
                .exists)
        app.alerts.buttons["Cancel"].tap()
        waitFor("exists == false", app.alerts["Stop all agents?"])
        XCTAssertEqual(try stub.agentStopRequests(), [])
        XCTAssertTrue(element("agents.stop").isEnabled)
    }

    func testConfirmingSendsOneRequestAndTheBarStaysUntilTheAgentsAreGone() throws {
        try open("agents-two")
        waitForCount("2 agents running")
        try stub.setAgentsMode("slow")
        confirmStop("2 agents")
        // While the request is held the button shows progress and cannot be pressed again.
        let stop = element("agents.stop")
        waitFor("label CONTAINS 'Stopping'", stop)
        stop.tap()
        XCTAssertFalse(app.alerts.firstMatch.exists, "a tap on a running stop asks nothing")
        try stub.releaseAgents()
        // The stub says when the held answer left; only then is "Stopping" the answer's doing.
        let answered = NSPredicate { _, _ in self.stub.agentAnswersSent() >= 1 }
        XCTAssertEqual(
            XCTWaiter().wait(for: [XCTNSPredicateExpectation(predicate: answered, object: nil)], timeout: Wait.appear),
            .completed)
        XCTAssertEqual(try stub.agentStopRequests().count, 1)
        XCTAssertTrue(stop.label.contains("Stopping"))
        XCTAssertFalse(element("agents.failure").exists)
        try auditThreadScreen(app)
        XCTAssertTrue(element("agents.bar").exists, "the bar stays until a report says no agent runs")
        try stub.setRunningAgents(0)
        waitFor("exists == false", element("agents.bar"))
        XCTAssertEqual(try stub.agentStopRequests().count, 1)
    }

    func testAnIdleAnswerRemovesTheBarAtOnce() throws {
        try open("agents-one")
        waitForCount("1 agent running")
        try stub.setAgentsMode("zero")
        confirmStop("1 agent")
        waitFor("exists == false", element("agents.bar"))
    }

    func testAFailureSaysSoAndTryAgainStopsTheAgents() throws {
        try open("agents-two")
        waitForCount("2 agents running")
        try stub.setAgentsMode("fail")
        confirmStop("2 agents")
        let failure = element("agents.failure")
        XCTAssertTrue(failure.waitForExistence(timeout: Wait.appear))
        XCTAssertEqual(failure.label, "Yak could not stop the agents. Try again.")
        XCTAssertTrue(element("agents.retry").exists)
        try auditThreadScreen(app)
        try stub.setAgentsMode("ok")
        element("agents.retry").tap()
        waitFor("exists == false", failure)
        XCTAssertEqual(try stub.agentStopRequests().count, 2)
        waitFor("label CONTAINS 'Stopping'", element("agents.stop"))
    }

    func testATooManyRequestsAnswerAsksForAMoment() throws {
        try open("agents-two")
        waitForCount("2 agents running")
        try stub.setAgentsMode("limited")
        confirmStop("2 agents")
        let failure = element("agents.failure")
        XCTAssertTrue(failure.waitForExistence(timeout: Wait.appear))
        XCTAssertEqual(failure.label, "Too many requests. Try again in a moment.")
    }

    /// 404, 403 and 401 offer no Try again: asking again cannot change what the server said.
    func testANotFoundAnswerSaysSoAndOffersNoTryAgain() throws {
        try open("agents-two")
        waitForCount("2 agents running")
        try stub.setAgentsMode("notfound")
        confirmStop("2 agents")
        let failure = element("agents.failure")
        XCTAssertTrue(failure.waitForExistence(timeout: Wait.appear))
        XCTAssertEqual(failure.label, "Yak could not find agents to stop on this thread.")
        XCTAssertFalse(element("agents.retry").exists)
    }

    func testAViewerSeesTheCountAndViewButNoStopAll() throws {
        try open("agents-viewer", hasField: false)
        waitForCount("2 agents running")
        XCTAssertTrue(element("agents.view").exists)
        XCTAssertFalse(element("agents.stop").exists)
        try auditThreadScreen(app)
    }

    func testTheSlashMenuAndTheBarDoNotCoverEachOther() throws {
        try open("agents-slash")
        waitForCount("2 agents running")
        let field = app.textFields["composer.field"]
        field.tap()
        field.typeText("/")
        let menu = element("composer.slash")
        XCTAssertTrue(menu.waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(element("agents.bar").exists)
        XCTAssertLessThanOrEqual(menu.frame.maxY, element("agents.bar").frame.minY + 1)
        try auditThreadScreen(app)
    }

    func testOfflineKeepsTheCountAndDisablesStopAll() throws {
        try open("agents-two")
        waitForCount("2 agents running")
        try stub.setOnline(false)
        waitFor("isEnabled == false", element("agents.stop"))
        XCTAssertTrue(element("agents.offline").exists)
        try auditThreadScreen(app)
        XCTAssertEqual(count.label, "2 agents running")
        try stub.setOnline(true)
        waitFor("isEnabled == true", element("agents.stop"))
    }

    /// At the largest text size the bar wraps and still passes the audit.
    func testTheBarPassesTheAuditAtTheLargestTextSize() throws {
        try open("agents-one", contentSize: "UICTContentSizeCategoryAccessibilityXXXL")
        waitForCount("1 agent running")
        try auditThreadScreen(app)
    }
}
