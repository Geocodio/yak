import XCTest

/// Smoke tests of the drawer's tabs and Filter sheet, against the stub server's `drawer-tabs` scenarios.
@MainActor final class DrawerTabsSmokeTests: XCTestCase {
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

    private func pick(_ tab: String) {
        element("drawer.tab.\(tab)").tap()
    }

    private func row(_ id: String) -> XCUIElement { element("drawer.row.thread:\(id)") }

    /// Mine, Everyone and Closed each show their own rows, the Review tab its groups, and the Review count stays on
    /// the tab whichever tab is on.
    func testEachTabShowsItsOwnList() throws {
        try launch("drawer-tabs")
        XCTAssertTrue(row("t0day001").waitForExistence(timeout: Wait.firstLoad))
        XCTAssertEqual(element("drawer.tab.review").label, "Review, 2 threads")
        XCTAssertTrue(element("drawer.tab.mine").isSelected)

        pick("everyone")
        XCTAssertTrue(row("e0ne0001").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(element("drawer.tab.everyone").isSelected)
        XCTAssertEqual(element("drawer.tab.review").label, "Review, 2 threads")

        pick("closed")
        XCTAssertTrue(row("c10sed01").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(row("e0ne0001").exists)
        XCTAssertTrue(element("drawer.row.thread:c10sed01").label.contains("Closed"))

        pick("review")
        XCTAssertTrue(element("drawer.group.approved").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(row("c10sed01").exists)
        // "Approved by you" starts folded; opening it shows its row.
        XCTAssertFalse(row("appr0ved").exists)
        element("drawer.group.approved").tap()
        XCTAssertTrue(row("appr0ved").waitForExistence(timeout: Wait.appear))
        try audit()
    }

    /// A source in the Filter sheet narrows the tab, shows as a chip, and Clear takes it off again.
    func testTheFilterSheetNarrowsTheList() throws {
        try launch("drawer-tabs")
        pick("everyone")
        XCTAssertTrue(row("e0ne0001").waitForExistence(timeout: Wait.appear))
        element("drawer.filter").tap()
        let slack = element("drawer.filterSheet.slack")
        XCTAssertTrue(slack.waitForExistence(timeout: Wait.appear))
        slack.tap()
        element("drawer.filterSheet.done").tap()
        XCTAssertTrue(element("drawer.chip.slack").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(row("t0day001").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(row("e0ne0001").exists)
        XCTAssertFalse(row("t0day002").exists)
        element("drawer.chip.clear").tap()
        XCTAssertTrue(row("e0ne0001").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(element("drawer.chip.slack").exists)
        // The audit judges the sheet pulled to full height, where every row shows; at half height the list scrolls
        // under the screen's edge. The audit can close the sheet, so it is the last step.
        element("drawer.filter").tap()
        XCTAssertTrue(slack.waitForExistence(timeout: Wait.appear))
        let grabber = app.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.49))
        grabber.press(forDuration: 0.1, thenDragTo: app.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.05)))
        XCTAssertTrue(slack.waitForExistence(timeout: Wait.appear))
        try audit()
    }

    /// Nothing waits in Review: its tab shows no count.
    func testReviewShowsNoCountWhenNothingWaits() throws {
        try launch("drawer-tabs-quiet")
        XCTAssertEqual(element("drawer.tab.review").label, "Review")
        try audit()
    }

    /// An older server lists no filters: only Mine and Everyone, no Review and no Closed.
    func testAnOlderServerHidesReviewAndClosed() throws {
        try launch("drawer-tabs-older")
        XCTAssertTrue(element("drawer.tab.everyone").exists)
        XCTAssertFalse(element("drawer.tab.review").exists)
        XCTAssertFalse(element("drawer.tab.closed").exists)
        try audit()
        element("drawer.filter").tap()
        XCTAssertTrue(element("drawer.filterSheet.yak").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(element("drawer.filterSheet.slack").exists)
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
}
