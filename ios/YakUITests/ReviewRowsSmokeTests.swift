import XCTest

/// Smoke tests of the Review tab's pull request line and the error on a failed row, against the stub server's
/// `review-line` scenarios.
@MainActor final class ReviewRowsSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func launch(_ scenario: String) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(element("drawer.tab.mine").waitForExistence(timeout: Wait.firstLoad))
    }

    private func openReview() {
        element("drawer.tab.review").tap()
        XCTAssertTrue(element("drawer.row.thread:rv1c0001").waitForExistence(timeout: Wait.appear))
    }

    private func audit() throws {
        try auditTwice(app) { [app] in
            let auditBars = AuditRules.Bars(app)
            return { issue in
                if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
                if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
                return false
            }
        }
    }

    /// Each Review row reads as one element with its pull request line, and VoiceOver can open each pull request.
    func testTheReviewRowsReadAsOneElementWithTheirLine() throws {
        try launch("review-line")
        openReview()
        XCTAssertTrue(
            element("drawer.row.thread:rv1c0002").label.contains(
                "Pull request 264, ready, has conflicts, 1 other approved"))
        XCTAssertTrue(
            element("drawer.row.thread:rv1c0004").label.contains(
                "Pull requests example/web 271, example/api 88, ready, checks passing, 3 others approved"))
    }

    /// "Approved by you" starts folded and opens, and the screen passes the audit in both states. The scenario has
    /// three rows, so no row is cut at the list's edge.
    func testApprovedByYouUnfoldsAndTheReviewTabPassesTheAudit() throws {
        try launch("review-line-few")
        openReview()
        XCTAssertFalse(element("drawer.row.thread:rv1c0008").exists)
        try audit()
        element("drawer.group.approved").tap()
        XCTAssertTrue(element("drawer.row.thread:rv1c0008").waitForExistence(timeout: Wait.appear))
        try audit()
    }

    /// A tap on a pull request number opens that pull request in the browser sheet.
    func testTheNumberOpensTheBrowserSheet() throws {
        try launch("review-line")
        openReview()
        tapNumber(inRow: "drawer.row.thread:rv1c0001")
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        XCTAssertTrue(sheet.wait(for: .runningForeground, timeout: Wait.appear))
    }

    /// A tap on the rest of the row opens the thread, not the pull request.
    func testTheRestOfTheRowOpensTheThread() throws {
        try launch("review-line")
        openReview()
        let row = element("drawer.row.thread:rv1c0002")
        row.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.2)).tap()
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        XCTAssertFalse(sheet.wait(for: .runningForeground, timeout: 3))
        let tab = element("drawer.tab.review")
        wait(
            for: [XCTNSPredicateExpectation(predicate: NSPredicate(format: "isHittable == false"), object: tab)],
            timeout: Wait.appear)
    }

    /// Offline, the saved Review list stays, and a tap on a pull request number says so instead of opening a page.
    func testOfflineANumberSaysSoInsteadOfOpeningThePage() throws {
        try launch("review-line")
        openReview()
        try stub.setOnline(false)
        XCTAssertTrue(element("drawer.offlineNotice").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(element("drawer.row.thread:rv1c0001").exists)
        tapNumber(inRow: "drawer.row.thread:rv1c0001")
        XCTAssertTrue(element("drawer.actionLine").waitForExistence(timeout: Wait.appear))
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        XCTAssertFalse(sheet.wait(for: .runningForeground, timeout: 3))
    }

    /// A failed row in Needs you shows its error under "Failed".
    func testAFailedRowShowsItsError() throws {
        try launch("review-line")
        let row = element("drawer.row.thread:n33d0002")
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(row.label.contains("Failed. CI failed twice on yak/null-spreadsheet"))
        try audit()
    }

    /// Nothing waits in Review: the groups are hidden and the tab says there is nothing here.
    func testAnEmptyReviewTabShowsNoGroups() throws {
        try launch("review-line-empty")
        element("drawer.tab.review").tap()
        XCTAssertTrue(app.staticTexts["No threads here"].waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(element("drawer.group.approved").exists)
        try audit()
    }

    /// The number sits at the start of the row's last line; the row is its own element, so the tap is by position.
    private func tapNumber(inRow identifier: String) {
        let row = element(identifier)
        XCTAssertTrue(row.waitForExistence(timeout: Wait.appear))
        row.coordinate(withNormalizedOffset: .zero)
            .withOffset(CGVector(dx: Self.numberOffset, dy: row.frame.height - Self.numberFromBottom)).tap()
    }

    private static let numberOffset: CGFloat = 45
    private static let numberFromBottom: CGFloat = 22
}
