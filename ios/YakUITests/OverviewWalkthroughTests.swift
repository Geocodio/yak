import XCTest

/// The quality-bar walkthrough of the Overview: one screenshot per state in `$YAK_SHOT_DIR`. Run it with
/// `bundle exec fastlane walkthrough only:OverviewWalkthroughTests`.
@MainActor final class OverviewWalkthroughTests: WalkthroughCase {
    func testOverview() throws {
        try openOverview("overview-stack")
        XCTAssertTrue(app.staticTexts["Sandbox running"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.descendants(matching: .any)["overview.copyBranch"].waitForExistence(timeout: 15))
        try shootOverview("overview")
    }

    func testOverviewLoading() throws {
        try openOverview("overview-slow")
        XCTAssertTrue(app.descendants(matching: .any)["overview.skeleton"].waitForExistence(timeout: 5))
        try shootOverview("overview-loading")
        XCTAssertTrue(app.descendants(matching: .any)["overview.slow"].waitForExistence(timeout: 12))
        try shootOverview("overview-slow")
    }

    func testOverviewEmpty() throws {
        try openOverview("overview-new-thread")
        XCTAssertTrue(app.staticTexts["Links appear once Yak creates a branch."].waitForExistence(timeout: 15))
        XCTAssertFalse(app.descendants(matching: .any)["overview.group.subThreads"].exists)
        try shootOverview("overview-empty")
    }

    func testOverviewRepositoryFailed() throws {
        try openOverview("overview-no-sub-threads")
        XCTAssertTrue(app.buttons["overview.retry.22"].waitForExistence(timeout: 15))
        try shootOverview("overview-repository-failed")
    }

    func testOverviewSettingUp() throws {
        try openOverview("overview-setting-up")
        XCTAssertTrue(app.staticTexts["example/web"].waitForExistence(timeout: 15))
        try shootOverview("overview-setting-up")
    }

    func testOverviewError() throws {
        try openOverview("overview-broken")
        XCTAssertTrue(app.descendants(matching: .any)["overview.error"].firstMatch.waitForExistence(timeout: 20))
        try shootOverview("overview-error")
    }

    func testOverviewOffline() throws {
        try openOverview("overview-stack")
        XCTAssertTrue(app.descendants(matching: .any)["overview.copyBranch"].waitForExistence(timeout: 15))
        try stub.setOnline(false)
        XCTAssertTrue(app.descendants(matching: .any)["overview.offline"].waitForExistence(timeout: 40))
        try shootOverview("overview-offline")
    }

    func testOverviewSetupOutput() throws {
        try openOverview("overview-no-sub-threads")
        let row = app.buttons["overview.repository.22"]
        XCTAssertTrue(row.waitForExistence(timeout: 15))
        row.tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview.setupOutput.text"].waitForExistence(timeout: 10))
        // A sheet covers the page; the audit cannot judge text under its dimming.
        try shoot("overview-setup-output", audits: false)
    }

    func testOverviewRecipient() throws {
        try openOverview("overview-recipient")
        XCTAssertTrue(app.descendants(matching: .any)["overview.visibility"].waitForExistence(timeout: 15))
        XCTAssertFalse(app.buttons["overview.share"].exists)
        try shootOverview("overview-recipient")
    }

    // MARK: Helpers

    /// Plays the scenario, opens the root thread from the drawer and the Overview from the title.
    private func openOverview(_ scenario: String) throws {
        try start(scenario, paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:s7ackr00"]
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
        let title = app.descendants(matching: .any)["thread.title"]
        if title.waitForExistence(timeout: 15) {
            title.tap()
        } else {
            // The short landscape layout hides the title; Command I opens the Overview.
            app.typeKey("i", modifierFlags: .command)
        }
        XCTAssertTrue(app.descendants(matching: .any)["overview"].waitForExistence(timeout: 10))
    }

    /// A screenshot, then the audit without the findings about text in the navigation bar, whose size the system caps.
    private func shootOverview(_ name: String) throws {
        try shoot(name, audits: false)
        try app.performAccessibilityAudit { [app] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            if issue.auditType == .contrast, issue.element == nil, app.windows.firstMatch.frame.width > 700 {
                return true
            }
            XCTFail("audit [\(name)]: \(issue.compactDescription) | \(issue.element?.debugDescription ?? "no element")")
            return true
        }
    }
}
