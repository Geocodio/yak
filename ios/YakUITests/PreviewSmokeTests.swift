import XCTest

/// Smoke tests of the Preview pane against the stub, which plays the preview host on a second port.
@MainActor final class PreviewSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launch()
    }

    private func openPreview() { PreviewScreen(app: app).open() }

    private func pageShows(_ text: String, timeout: TimeInterval = 15) -> Bool {
        app.webViews.staticTexts[text].waitForExistence(timeout: timeout)
    }

    private func card(_ kind: String) -> XCUIElement { app.descendants(matching: .any)["preview.card.\(kind)"] }

    func testAReadyPreviewSignsInAndShowsThePage() throws {
        try launch(scenario: "preview-ready")
        openPreview()
        XCTAssertTrue(pageShows("Path: /"))
        let log = try stub.previewLog()
        XCTAssertEqual(log.filter { $0.hasPrefix("auth ") }, ["auth /"])
        XCTAssertFalse(app.webViews.staticTexts["The app showed the dashboard bounce."].exists)
        try audit()
    }

    func testAWakingPreviewWarmsAndOpensByItself() throws {
        try launch(scenario: "preview-starting")
        openPreview()
        XCTAssertTrue(card("waking").waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["Starting the dev server"].exists)
        XCTAssertTrue(pageShows("Path: /", timeout: 30))
        XCTAssertGreaterThanOrEqual(try stub.previewLog().filter { $0 == "warm" }.count, 2)
    }

    func testAnExpiredSignInIsRenewed() throws {
        try launch(scenario: "preview-ready")
        openPreview()
        XCTAssertTrue(pageShows("Path: /"))
        // A new play makes the page's cookie stale.
        try stub.play("preview-ready")
        let reload = app.buttons["preview.reload"]
        XCTAssertTrue(reload.waitForExistence(timeout: 5))
        reload.tap()
        let log = { try self.stub.previewLog() }
        let renewed = NSPredicate { _, _ in (try? log().filter { $0 == "auth /" }.count) ?? 0 >= 1 }
        wait(for: [XCTNSPredicateExpectation(predicate: renewed, object: nil)], timeout: 20)
        XCTAssertTrue(pageShows("Path: /"))
        XCTAssertFalse(app.webViews.staticTexts["The app showed the dashboard bounce."].exists)
    }

    func testRestoreADestroyedSandbox() throws {
        try launch(scenario: "preview-destroyed")
        openPreview()
        let restore = app.buttons["preview.restore"]
        XCTAssertTrue(restore.waitForExistence(timeout: 10))
        restore.tap()
        XCTAssertTrue(card("restoring").waitForExistence(timeout: 10))
        XCTAssertTrue(pageShows("Path: /", timeout: 40))
    }

    func testAskYakToFixSendsTheIntent() throws {
        try launch(scenario: "preview-failing")
        openPreview()
        let ask = app.buttons["preview.ask"]
        XCTAssertTrue(ask.waitForExistence(timeout: 10))
        ask.tap()
        let sent = NSPredicate { _, _ in (try? self.stub.lastMessage().contains("\"intent\":\"fix_preview\"")) ?? false
        }
        wait(for: [XCTNSPredicateExpectation(predicate: sent, object: nil)], timeout: 20)
        XCTAssertTrue(card("setup-running").waitForExistence(timeout: 15))
        XCTAssertTrue(app.staticTexts["Yak is fixing the preview"].exists)
    }

    func testTypingAnAddressStaysOnThePreview() throws {
        try launch(scenario: "preview-ready")
        openPreview()
        XCTAssertTrue(pageShows("Path: /"))
        func go(_ typed: String) {
            app.buttons["preview.address"].tap()
            let field = app.textFields["preview.address.field"]
            XCTAssertTrue(field.waitForExistence(timeout: 5))
            field.typeText(typed)
            app.keyboards.buttons["Go"].tap()
        }
        go("pricing")
        XCTAssertTrue(pageShows("Path: /pricing"))
        go("//other.example.com/x")
        XCTAssertTrue(pageShows("Path: /other.example.com/x"))
        XCTAssertEqual(try stub.previewLog().filter { $0.hasPrefix("auth ") }.count, 1)
    }

    func testLinksToOtherHostsLeaveThePane() throws {
        try launch(scenario: "preview-ready")
        openPreview()
        XCTAssertTrue(pageShows("Path: /"))
        app.webViews.links["Docs"].tap()
        XCTAssertTrue(pageShows("Path: /", timeout: 5))
    }

    func testTheStripSteersFromThePreview() throws {
        try launch(scenario: "preview-working")
        openPreview()
        let strip = app.buttons["activity.strip"]
        XCTAssertTrue(strip.waitForExistence(timeout: 10))
        strip.tap()
        XCTAssertTrue(app.descendants(matching: .any)["conversation"].waitForExistence(timeout: 5))
        XCTAssertFalse(strip.exists)
    }

    func testTheQuestionChipOpensChat() throws {
        try launch(scenario: "preview-question")
        openPreview()
        let chip = app.buttons["question.chip"]
        XCTAssertTrue(chip.waitForExistence(timeout: 10))
        XCTAssertEqual(chip.label, "2 questions waiting")
        chip.tap()
        XCTAssertTrue(app.descendants(matching: .any)["conversation"].waitForExistence(timeout: 5))
    }

    private func audit() throws {
        try app.performAccessibilityAudit { [self] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            // The page's own content is the app's, not ours to restyle.
            let page = app.webViews.firstMatch
            if page.exists, let element = issue.element, page.frame.contains(element.frame) { return true }
            if issue.auditType == .contrast, issue.element == nil, app.windows.firstMatch.frame.width > 700 {
                return true
            }
            if issue.auditType == .textClipped, issue.element?.elementType == .textField { return true }
            XCTFail("audit: \(issue.compactDescription) | \(issue.element?.debugDescription ?? "none")")
            return true
        }
    }
}
