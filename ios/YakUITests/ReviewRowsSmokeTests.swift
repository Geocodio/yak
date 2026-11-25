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
        tapNumber(inRow: "thread:rv1c0001")
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

    /// The whole list passes the audit once no row is cut by the list's bottom edge, at the top and further down.
    func testTheWholeReviewListPassesTheAudit() throws {
        try launch("review-line")
        openReview()
        scrollUntilNoRowStraddlesAnEdge()
        try audit()
        app.swipeUp()
        scrollUntilNoRowStraddlesAnEdge()
        try audit()
    }

    /// Offline, the saved Review list stays, and a tap on a pull request number says so instead of opening a page.
    func testOfflineANumberSaysSoInsteadOfOpeningThePage() throws {
        try launch("review-line")
        openReview()
        try stub.setOnline(false)
        XCTAssertTrue(element("drawer.offlineNotice").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(element("drawer.row.thread:rv1c0001").exists)
        tapNumber(inRow: "thread:rv1c0001")
        XCTAssertTrue(element("drawer.actionLine").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(element("drawer.actionLine").label.contains("You are offline. Try the pull request again"))
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        XCTAssertFalse(sheet.wait(for: .runningForeground, timeout: 3))
    }

    /// Every pull request that can open has an item in the row's menu, also two with the same number in two
    /// repositories; one whose address the app refuses has none.
    func testEachPullRequestHasAnItemInTheMenu() throws {
        try launch("review-line")
        openReview()
        func menuItems(of rowID: String) -> [String] {
            let row = element("drawer.row.thread:\(rowID)")
            XCTAssertTrue(row.waitForExistence(timeout: Wait.appear))
            row.press(forDuration: 1.2)
            let items = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Open pull request'"))
            _ = items.firstMatch.waitForExistence(timeout: Wait.appear)
            let labels = items.allElementsBoundByIndex.map(\.label)
            app.coordinate(withNormalizedOffset: CGVector(dx: 0.95, dy: 0.97)).tap()
            return labels
        }
        XCTAssertEqual(
            menuItems(of: "rv1c0004"), ["Open pull request example/web#271", "Open pull request example/api#88"])
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

    /// A number is its own element for the test (hidden from VoiceOver, which has the row's actions instead).
    private func tapNumber(inRow rowID: String, index: Int = 0) {
        let number = element("drawer.review.pr.\(rowID).\(index)")
        XCTAssertTrue(number.waitForExistence(timeout: Wait.appear))
        number.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.5)).tap()
    }

    /// Scrolls the list in small steps until no row is cut by the list's bottom edge or hides under the section
    /// heading at the top, as a careful tester does before judging contrast: a row cut by an edge is not what the
    /// audit is meant to measure. It stops where the list ends.
    private func scrollUntilNoRowStraddlesAnEdge() {
        let list = element("drawer.list")
        let rows = app.descendants(matching: .any).matching(NSPredicate(format: "identifier BEGINSWITH 'drawer.row.'"))
        func straddles() -> Bool {
            let top = list.frame.minY + Self.headingAllowance
            let bottom = list.frame.maxY
            return rows.allElementsBoundByIndex.contains {
                let frame = $0.frame
                guard frame.height > 0 else { return false }
                return (frame.minY < bottom && frame.maxY > bottom)
                    || (frame.minY < top && frame.maxY > list.frame.minY)
            }
        }
        var lastOffset = rows.firstMatch.frame.minY
        for _ in 0..<30 where straddles() {
            let x = list.frame.midX
            let start = app.coordinate(withNormalizedOffset: .zero).withOffset(
                CGVector(dx: x, dy: list.frame.midY))
            let end = app.coordinate(withNormalizedOffset: .zero).withOffset(
                CGVector(dx: x, dy: list.frame.midY - Self.scrollStep))
            start.press(forDuration: 0.1, thenDragTo: end, withVelocity: .slow, thenHoldForDuration: 0.5)
            let offset = rows.firstMatch.frame.minY
            if offset == lastOffset { return }
            lastOffset = offset
        }
    }

    /// The height of the section heading that rows slide under at the top of the list, and how far one step scrolls.
    private static let headingAllowance: CGFloat = 44
    private static let scrollStep: CGFloat = 30
}
