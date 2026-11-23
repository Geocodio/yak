import XCTest

/// The leftovers screens: the instance tint tile and the account settings, one screenshot per state in
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
}
