import XCTest

/// The quality-bar walkthrough of the drawer's Observations tile and the Observations list: one screenshot per
/// state in `$YAK_SHOT_DIR`. Run it with `bundle exec fastlane walkthrough only:ObservationsWalkthroughTests`.
@MainActor final class ObservationsWalkthroughTests: WalkthroughCase {
    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    /// The cold start lands on the drawer; a compact thread screen opens it with the threads button.
    private func openDrawer() {
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(app.otherElements["drawer"].waitForExistence(timeout: 10))
    }

    /// Leaves the list for the drawer and opens it again, which reloads it.
    private func reopen(_ tile: String, leaving threadsButton: String) {
        element(threadsButton).tap()
        let threads = app.buttons["threads.button"]
        if threads.waitForExistence(timeout: 5), threads.isHittable { threads.tap() }
        let button = app.buttons[tile]
        let isReady = NSPredicate(format: "isHittable == true")
        wait(for: [XCTNSPredicateExpectation(predicate: isReady, object: button)], timeout: 10)
        button.tap()
    }

    private func openTilesScreen(_ tile: String, scenario: String) throws {
        try start(scenario, paired: true)
        openDrawer()
        let button = app.buttons[tile]
        XCTAssertTrue(button.waitForExistence(timeout: 10))
        button.tap()
    }

    // MARK: Drawer

    func testDrawerTiles() throws {
        try start("changes", paired: true)
        openDrawer()
        XCTAssertTrue(element("drawer.tile.observations").waitForExistence(timeout: 10))
        XCTAssertTrue(element("drawer.taskLabel").waitForExistence(timeout: 10))
        try shoot("drawer-tiles")
    }

    // MARK: Observations

    func testObservations() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "changes")
        XCTAssertTrue(element("observations.row.301").waitForExistence(timeout: 15))
        try shoot("observations")
    }

    func testObservationsDeclined() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "changes")
        XCTAssertTrue(element("observations.row.301").waitForExistence(timeout: 15))
        app.buttons.matching(NSPredicate(format: "label BEGINSWITH %@", "Declined")).firstMatch.tap()
        XCTAssertTrue(element("observations.row.302").waitForExistence(timeout: 10))
        try shoot("observations-declined")
    }

    func testObservationsEmpty() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "tasks-empty")
        XCTAssertTrue(element("observations.empty").waitForExistence(timeout: 15))
        try shoot("observations-empty")
    }

    func testObservationsError() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "tasks-error")
        XCTAssertTrue(element("observations.error").waitForExistence(timeout: 15))
        try shoot("observations-error")
    }

    func testObservationsLoading() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "tasks-slow")
        XCTAssertTrue(element("observations.threads").waitForExistence(timeout: 10))
        Thread.sleep(forTimeInterval: 1.5)
        try shoot("observations-loading")
    }

    func testObservationsOffline() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "changes")
        XCTAssertTrue(element("observations.row.301").waitForExistence(timeout: 15))
        try stub.setOnline(false)
        reopen("drawer.tile.observations", leaving: "observations.threads")
        XCTAssertTrue(label("Offline. Showing a saved copy").waitForExistence(timeout: 25))
        try shoot("observations-offline")
    }

    func testObservationsWithNulls() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "tasks-nulls")
        XCTAssertTrue(element("observations.row.301").waitForExistence(timeout: 15))
        try shoot("observations-nulls")
    }
}
