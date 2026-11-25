import XCTest

/// Every state of the drawer's tabs, shot and audited: each tab, no Review count, an older server, the filters on and
/// the Filter sheet. At the largest text size the tab row is the menu button, and the menu is shot open.
@MainActor final class DrawerTabsWalkthroughTests: WalkthroughCase {
    private func launch(_ scenario: String) throws {
        try start(scenario, paired: true)
        XCTAssertTrue(app.buttons["drawer.filter"].waitForExistence(timeout: Wait.firstLoad))
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private var isMenu: Bool { environment["YAK_TEXT_SIZE"] == "largest" }

    private func pick(_ tab: String, label: String) {
        if isMenu {
            element("drawer.tabs.menu").tap()
            app.buttons.matching(NSPredicate(format: "label BEGINSWITH %@", label)).firstMatch.tap()
        } else {
            element("drawer.tab.\(tab)").tap()
        }
    }

    func testMine() throws {
        try launch("drawer-tabs")
        XCTAssertTrue(element("drawer.row.thread:n33d0001").waitForExistence(timeout: Wait.firstLoad))
        try shoot("drawer-tabs-mine")
        if isMenu {
            element("drawer.tabs.menu").tap()
            XCTAssertTrue(app.buttons["Mine"].waitForExistence(timeout: Wait.appear))
            try shoot("drawer-tabs-menu-open", audits: false)
        }
    }

    func testReview() throws {
        try launch("drawer-tabs")
        pick("review", label: "Review")
        // At the largest text size the lower groups are below the screen, so the first heading is what shows.
        let heading = app.descendants(matching: .any).matching(
            NSPredicate(format: "label BEGINSWITH %@", "Back to you")
        )
        .firstMatch
        XCTAssertTrue(heading.waitForExistence(timeout: Wait.firstLoad))
        try shoot("drawer-tabs-review")
    }

    func testEveryone() throws {
        try launch("drawer-tabs")
        XCTAssertTrue(element("drawer.row.thread:n33d0001").waitForExistence(timeout: Wait.firstLoad))
        pick("everyone", label: "Everyone")
        // Everyone has no needs-you section, so the Mine list has left when its first row is gone.
        XCTAssertTrue(element("drawer.row.thread:n33d0001").waitForNonExistence(timeout: Wait.appear))
        XCTAssertTrue(element("drawer.row.thread:t0day001").waitForExistence(timeout: Wait.firstLoad))
        try shoot("drawer-tabs-everyone")
    }

    func testClosed() throws {
        try launch("drawer-tabs")
        pick("closed", label: "Closed")
        XCTAssertTrue(element("drawer.row.thread:c10sed01").waitForExistence(timeout: Wait.firstLoad))
        try shoot("drawer-tabs-closed")
    }

    func testNoReviewCount() throws {
        try launch("drawer-tabs-quiet")
        XCTAssertTrue(element("drawer.row.thread:n33d0001").waitForExistence(timeout: Wait.firstLoad))
        try shoot("drawer-tabs-quiet")
    }

    func testOlderServer() throws {
        try launch("drawer-tabs-older")
        XCTAssertTrue(element("drawer.row.thread:n33d0001").waitForExistence(timeout: Wait.firstLoad))
        try shoot("drawer-tabs-older")
    }

    func testFiltersOnAndTheSheet() throws {
        try launch("drawer-tabs")
        XCTAssertTrue(element("drawer.row.thread:n33d0001").waitForExistence(timeout: Wait.firstLoad))
        element("drawer.filter").tap()
        let slack = element("drawer.filterSheet.slack")
        XCTAssertTrue(slack.waitForExistence(timeout: Wait.appear))
        slack.tap()
        element("drawer.filterSheet.sentry").tap()
        try shoot("drawer-tabs-filter-sheet", audits: false)
        element("drawer.filterSheet.done").tap()
        XCTAssertTrue(element("drawer.chip.slack").waitForExistence(timeout: Wait.appear))
        try shoot("drawer-tabs-filters")
        // The half-height sheet scrolls, so the audit runs on the sheet pulled to full height, where every row shows.
        // The audit can close the sheet, so it is the last step.
        element("drawer.filter").tap()
        XCTAssertTrue(element("drawer.filterSheet.slack").waitForExistence(timeout: Wait.appear))
        let grabber = app.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.49))
        grabber.press(forDuration: 0.1, thenDragTo: app.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.05)))
        try shoot("drawer-tabs-filter-sheet-large")
    }
}
