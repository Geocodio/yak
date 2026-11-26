import XCTest

/// The agents bar in every state, one screenshot each in `$YAK_SHOT_DIR`. Run it with
/// `bundle exec fastlane walkthrough only:AgentsBarWalkthroughTests/<test>`; the smoke tests hold the audits.
@MainActor final class AgentsBarWalkthroughTests: WalkthroughCase {
    private func open(_ scenario: String) throws {
        try start(scenario, paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: Wait.appear) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        XCTAssertTrue(app.textFields["composer.field"].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.descendants(matching: .any)["agents.bar"].waitForExistence(timeout: Wait.firstLoad))
        // View is enabled once the page has named the newest agent's card.
        let named = NSPredicate(format: "isEnabled == true")
        wait(
            for: [XCTNSPredicateExpectation(predicate: named, object: app.buttons["agents.view"])],
            timeout: Wait.appear)
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    func testThreeAgents() throws {
        try open("agents-three")
        try shoot("agents-three", audits: false)
    }

    func testOneAgent() throws {
        try open("agents-one")
        try shoot("agents-one", audits: false)
    }

    func testStopAllAlert() throws {
        try open("agents-two")
        element("agents.stop").tap()
        XCTAssertTrue(app.alerts["Stop all agents?"].waitForExistence(timeout: Wait.appear))
        try shoot("agents-stop-alert", audits: false)
    }

    func testStopping() throws {
        try open("agents-two")
        try stub.post("agents/mode", "slow")
        element("agents.stop").tap()
        app.alerts.buttons["Stop 2 agents"].firstMatch.tap()
        let stopping = NSPredicate(format: "label CONTAINS 'Stopping'")
        wait(
            for: [XCTNSPredicateExpectation(predicate: stopping, object: element("agents.stop"))], timeout: Wait.appear)
        try shoot("agents-stopping-request", audits: false)
        try stub.post("agents/release", "go")
        try shoot("agents-stopping-waiting", audits: false)
    }

    func testFailure() throws {
        try open("agents-two")
        try stub.post("agents/mode", "fail")
        element("agents.stop").tap()
        app.alerts.buttons["Stop 2 agents"].firstMatch.tap()
        XCTAssertTrue(element("agents.failure").waitForExistence(timeout: Wait.appear))
        try shoot("agents-failure", audits: false)
    }

    func testKeyboardUp() throws {
        try open("agents-two")
        app.textFields["composer.field"].tap()
        try shoot("agents-keyboard", audits: false)
    }

    func testSlashMenuOpen() throws {
        try open("agents-slash")
        app.textFields["composer.field"].tap()
        app.textFields["composer.field"].typeText("/")
        XCTAssertTrue(element("composer.slash").waitForExistence(timeout: Wait.appear))
        try shoot("agents-slash-menu", audits: false)
    }

    func testViewer() throws {
        try start("agents-viewer", paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: Wait.appear) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        XCTAssertTrue(element("agents.bar").waitForExistence(timeout: Wait.firstLoad))
        try shoot("agents-viewer", audits: false)
    }
}
