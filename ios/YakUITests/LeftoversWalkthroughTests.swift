import XCTest

/// The leftovers screens: the instance tint tile and the walkthrough player's states, one screenshot per state in
/// `$YAK_SHOT_DIR`. Run it with `bundle exec fastlane walkthrough only:LeftoversWalkthroughTests`.
@MainActor final class LeftoversWalkthroughTests: WalkthroughCase {
    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    func testSwitcherTints() throws {
        try start("paired", paired: true, extraArguments: ["-yakTestExtraInstances", "YES"])
        let threads = app.buttons["threads.button"]
        if !element("drawer.switcher").waitForExistence(timeout: 10), threads.waitForExistence(timeout: 5) {
            threads.tap()
        }
        XCTAssertTrue(element("drawer.switcher").waitForExistence(timeout: 15))
        element("drawer.switcher").tap()
        XCTAssertTrue(element("switcher").waitForExistence(timeout: 10))
        try shoot("switcher-tints")
    }

    func testSettingsTints() throws {
        try start("paired", paired: true, extraArguments: ["-yakTestExtraInstances", "YES"])
        openAccount()
        XCTAssertTrue(element("settings.screen").waitForExistence(timeout: 15))
        try shoot("settings-tints")
    }

    private func openWalkthrough(_ scenario: String) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.open(stub.taskLink)
        XCTAssertTrue(element("task.walkthrough").waitForExistence(timeout: 30))
    }

    func testWalkthroughPlayer() throws {
        try openWalkthrough("task-walkthrough")
        element("task.walkthrough").tap()
        XCTAssertTrue(element("walkthrough.player").waitForExistence(timeout: 15))
        try shoot("walkthrough-player", audits: false)
    }

    func testWalkthroughLoading() throws {
        try openWalkthrough("task-walkthrough-slow")
        element("task.walkthrough").tap()
        XCTAssertTrue(element("walkthrough.loading").waitForExistence(timeout: 5))
        try shoot("walkthrough-loading", audits: false)
        XCTAssertTrue(element("walkthrough.slow").waitForExistence(timeout: 15))
        try shoot("walkthrough-slow", audits: false)
    }

    func testWalkthroughExpired() throws {
        try openWalkthrough("task-walkthrough-expired")
        element("task.walkthrough").tap()
        XCTAssertTrue(element("walkthrough.failed.expired").waitForExistence(timeout: 20))
        try shoot("walkthrough-expired", audits: false)
    }

    func testWalkthroughOffline() throws {
        try openWalkthrough("task-walkthrough")
        try stub.setOnline(false)
        element("task.walkthrough").tap()
        XCTAssertTrue(element("walkthrough.failed.offline").waitForExistence(timeout: 20))
        try shoot("walkthrough-offline", audits: false)
        try stub.setOnline(true)
        XCTAssertFalse(element("walkthrough.failed.offline").waitForExistence(timeout: 10))
    }
}
