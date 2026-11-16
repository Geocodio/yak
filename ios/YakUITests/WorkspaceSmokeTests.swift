import XCTest

extension XCUIApplication {
    /// Opens a thread from the drawer, which shows first on a phone.
    func openThread(_ id: String) {
        let row = descendants(matching: .any)["drawer.row.thread:\(id)"]
        if !row.waitForExistence(timeout: 10) { buttons["threads.button"].tap() }
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
    }

    /// Switches to a pane by its name through the pane menu or, where the panes are a row, its button.
    func openPane(_ name: String) {
        let menu = buttons["pane.menu"]
        if menu.waitForExistence(timeout: 5) {
            menu.tap()
            let byIdentifier = descendants(matching: .any)["pane.menu.\(name.lowercased())"]
            let item =
                byIdentifier.waitForExistence(timeout: 3) ? byIdentifier : buttons.matching(identifier: name).firstMatch
            XCTAssertTrue(item.waitForExistence(timeout: 5))
            item.tap()
        } else {
            let row = buttons["pane.row.\(name.lowercased())"]
            XCTAssertTrue(row.waitForExistence(timeout: 5))
            row.tap()
        }
    }
}

/// Smoke tests of the Terminal and Files panes against the stub server.
@MainActor final class WorkspaceSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String, arguments: [String] = []) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"] + arguments
        app.launch()
    }

    private var output: XCUIElement { app.descendants(matching: .any)["terminal.view"] }

    private func waitForValue(_ element: XCUIElement, containing text: String, timeout: TimeInterval = 10) {
        let predicate = NSPredicate(format: "value CONTAINS %@", text)
        wait(for: [XCTNSPredicateExpectation(predicate: predicate, object: element)], timeout: timeout)
        XCTAssertTrue((element.value as? String ?? "").contains(text), "\(text) is not in the output")
    }

    func testTerminalOpensTypesAndReconnects() throws {
        try launch(scenario: "workspace")
        app.openThread("d0e515ef")
        app.openPane("Terminal")
        XCTAssertTrue(output.waitForExistence(timeout: 15))
        waitForValue(output, containing: "stub$")
        try audit()
        output.tap()
        app.typeText("echo hello\n")
        waitForValue(output, containing: "hello")
        try stub.post("terminal/close", "1011")
        XCTAssertTrue(app.descendants(matching: .any)["terminal.banner"].waitForExistence(timeout: 10))
        waitForValue(output, containing: "New shell. The connection dropped at", timeout: 20)
        XCTAssertEqual(try stub.get("terminal/opened"), "2")
    }

    func testTerminalIdleCloseDoesNotReconnect() throws {
        try launch(scenario: "workspace")
        app.openThread("d0e515ef")
        app.openPane("Terminal")
        waitForValue(output, containing: "stub$", timeout: 15)
        try stub.post("terminal/close", "4408")
        XCTAssertTrue(
            app.staticTexts["This shell closed after 30 minutes without input."].waitForExistence(timeout: 10))
        Thread.sleep(forTimeInterval: 5)
        XCTAssertEqual(try stub.get("terminal/opened"), "1")
    }

    func testPaneSwitchKeepsTheShell() throws {
        try launch(scenario: "workspace")
        app.openThread("d0e515ef")
        app.openPane("Terminal")
        waitForValue(output, containing: "stub$", timeout: 15)
        app.openPane("Chat")
        app.openPane("Terminal")
        XCTAssertEqual(try stub.get("terminal/opened"), "1")
        XCTAssertTrue((output.value as? String ?? "").contains("stub$"))
    }

    private func audit() throws {
        try app.performAccessibilityAudit { [self] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                // The system caps the size of text in its bars, and draws its glass under the threads badge.
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            // On a wide screen the audit reports contrast on the thread screen without naming an element.
            if issue.auditType == .contrast, issue.element == nil { return true }
            // A search field is one line by design.
            if issue.auditType == .textClipped, issue.element?.elementType == .textField { return true }
            let screenshot = XCTAttachment(screenshot: app.screenshot())
            screenshot.lifetime = .keepAlways
            screenshot.name = "audit-failure"
            add(screenshot)
            XCTFail(
                "audit: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "none")"
            )
            return true
        }
    }
}
